/**
 * auth_check.js — Taruh di <head> index.html
 * Cek session → redirect ke login jika belum login
 * Inject navbar user info & watchlist integration
 */
(async function() {
  // ── Cek login — hardened, tidak bisa di-bypass ─────────────
  let currentUser = null;
  try {
    const res = await fetch('./auth.php?action=me', { credentials: 'include', cache: 'no-store' });
    if (!res.ok) { window.location.replace('./login.html'); return; }
    const j = await res.json();
    if (!j.ok || !j.user || !j.user.id) {
      window.location.replace('./login.html'); return;
    }
    currentUser = j.user;
    Object.freeze(currentUser); // cegah modifikasi dari console
  } catch(e) {
    window.location.replace('./login.html'); return;
  }

  // ── Inject user info ke navbar (setelah DOM ready) ─────────
  document.addEventListener('DOMContentLoaded', () => {
    injectUserNav(currentUser);
    loadUserWatchlist();
    loadUserSettings();
  });

  // ── User nav ───────────────────────────────────────────────
  function injectUserNav(user) {
    const nav = document.querySelector('nav, .nav-header, header') || document.body;
    const el  = document.createElement('div');
    el.id     = 'user-nav';
    el.style.cssText = 'position:fixed;top:10px;right:16px;z-index:999999;display:flex;align-items:center;gap:8px;pointer-events:auto;';
    el.innerHTML = `
      <div style="position:relative;">
        <button id="user-btn" style="display:flex;align-items:center;gap:6px;padding:6px 12px;
          background:rgba(0,200,255,.08);border:1px solid rgba(0,200,255,.2);border-radius:8px;
          color:#00c8ff;font-size:.78rem;font-weight:600;cursor:pointer;font-family:inherit;">
          👤 ${user.username}
          <span style="font-size:.6rem;opacity:.6;">▼</span>
        </button>
        <div id="user-dropdown" style="display:none;position:absolute;right:0;top:calc(100% + 6px);
          background:#0d1117;border:1px solid rgba(0,200,255,.15);border-radius:10px;
          min-width:180px;padding:6px;box-shadow:0 8px 24px rgba(0,0,0,.4);z-index:9999;">
          <div style="padding:8px 10px 6px;border-bottom:1px solid rgba(255,255,255,.06);margin-bottom:4px;">
            <div style="font-size:.8rem;font-weight:600;color:#e0e0e0;">${user.username}</div>
            <div style="font-size:.7rem;color:#7a7a8a;">${user.email}</div>
          </div>
          <button class="ud-item" onclick="openChangePassword()">🔑 Ganti Password</button>
          <button class="ud-item" onclick="doLogout()" style="color:#ff3b5c;">↩ Logout</button>
        </div>
      </div>
    `;

    // Style dropdown items
    const style = document.createElement('style');
    style.textContent = `.ud-item{display:block;width:100%;text-align:left;padding:7px 10px;
      background:none;border:none;color:#c0c0c0;font-size:.8rem;cursor:pointer;border-radius:6px;
      font-family:inherit;transition:background .1s;}
      .ud-item:hover{background:rgba(0,200,255,.08);color:#00c8ff;}
      @media(max-width:768px){#user-nav{display:none!important;}}`;
    document.head.appendChild(style);
    document.body.appendChild(el);

    // Toggle dropdown
    document.getElementById('user-btn').addEventListener('click', (e) => {
      e.stopPropagation();
      const dd = document.getElementById('user-dropdown');
      dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', () => {
      const dd = document.getElementById('user-dropdown');
      if (dd) dd.style.display = 'none';
    });
  }

  // ── Logout ─────────────────────────────────────────────────
  window.doLogout = async function() {
    await fetch('./auth.php?action=logout', { method:'POST', credentials:'include' });
    window.location.href = './login.html';
  };

  // ── Ganti password ─────────────────────────────────────────
  window.openChangePassword = function() {
    const modal = document.createElement('div');
    modal.id = 'cp-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:99999;display:flex;align-items:center;justify-content:center;';
    modal.innerHTML = `
      <div style="background:#0d1117;border:1px solid rgba(0,200,255,.2);border-radius:14px;padding:28px;width:360px;max-width:90vw;">
        <h3 style="color:#00c8ff;margin-bottom:20px;font-size:1rem;">🔑 Ganti Password</h3>
        <input type="password" id="cp-old" placeholder="Password lama" style="width:100%;padding:10px;background:rgba(255,255,255,.05);border:1px solid rgba(0,200,255,.15);border-radius:7px;color:#e0e0e0;margin-bottom:10px;font-size:.9rem;outline:none;">
        <input type="password" id="cp-new" placeholder="Password baru (min. 8 karakter)" style="width:100%;padding:10px;background:rgba(255,255,255,.05);border:1px solid rgba(0,200,255,.15);border-radius:7px;color:#e0e0e0;margin-bottom:10px;font-size:.9rem;outline:none;">
        <input type="password" id="cp-new2" placeholder="Ulangi password baru" style="width:100%;padding:10px;background:rgba(255,255,255,.05);border:1px solid rgba(0,200,255,.15);border-radius:7px;color:#e0e0e0;margin-bottom:14px;font-size:.9rem;outline:none;">
        <div id="cp-msg" style="font-size:.78rem;margin-bottom:10px;display:none;"></div>
        <div style="display:flex;gap:8px;">
          <button onclick="submitChangePassword()" style="flex:1;padding:10px;background:rgba(0,200,255,.12);border:1px solid rgba(0,200,255,.3);border-radius:7px;color:#00c8ff;font-weight:600;cursor:pointer;">Simpan</button>
          <button onclick="document.getElementById('cp-modal').remove()" style="flex:1;padding:10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:7px;color:#aaa;cursor:pointer;">Batal</button>
        </div>
      </div>`;
    document.body.appendChild(modal);
  };

  window.submitChangePassword = async function() {
    const old_password = document.getElementById('cp-old').value;
    const new_password = document.getElementById('cp-new').value;
    const new_password2= document.getElementById('cp-new2').value;
    const msgEl        = document.getElementById('cp-msg');

    if (new_password !== new_password2) {
      msgEl.style.cssText='display:block;color:#ff3b5c;';
      msgEl.textContent='Password baru tidak sama'; return;
    }
    try {
      const res = await fetch('./api_user.php?action=change_password', {
        method:'POST', credentials:'include',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({old_password, new_password})
      });
      const j = await res.json();
      msgEl.style.cssText = j.ok ? 'display:block;color:#00e5a0;' : 'display:block;color:#ff3b5c;';
      msgEl.textContent = j.msg;
      if (j.ok) setTimeout(() => document.getElementById('cp-modal')?.remove(), 1500);
    } catch(e) { msgEl.style.cssText='display:block;color:#ff3b5c;'; msgEl.textContent='Gagal'; }
  };

  // ── Watchlist per user ─────────────────────────────────────
  let _watchlist = new Set();

  async function loadUserWatchlist() {
    try {
      const res = await fetch('./api_user.php?action=watchlist', { credentials:'include' });
      const j   = await res.json();
      if (j.ok) {
        _watchlist = new Set(j.watchlist.map(w => w.kode));
        // Sync ke UI jika ada fungsi watchlist global
        if (typeof syncWatchlistUI === 'function') syncWatchlistUI(_watchlist);
      }
    } catch(e) {}
  }

  window.userAddWatchlist = async function(kode, note='') {
    const res = await fetch('./api_user.php?action=watchlist_add', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({kode, note})
    });
    const j = await res.json();
    if (j.ok) { _watchlist.add(kode); if (typeof toast==='function') toast('⭐ '+kode+' ditambahkan', 'ok'); }
    return j;
  };

  window.userRemoveWatchlist = async function(kode) {
    const res = await fetch('./api_user.php?action=watchlist_remove', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({kode})
    });
    const j = await res.json();
    if (j.ok) { _watchlist.delete(kode); if (typeof toast==='function') toast('🗑 '+kode+' dihapus', 'ok'); }
    return j;
  };

  window.isInWatchlist = (kode) => _watchlist.has(kode);
  window.getUserWatchlist = () => [..._watchlist];

  // ── Settings per user ──────────────────────────────────────
  async function loadUserSettings() {
    try {
      const res = await fetch('./api_user.php?action=settings', { credentials:'include' });
      const j   = await res.json();
      if (j.ok && j.settings && typeof applyUserSettings === 'function') {
        applyUserSettings(j.settings);
      }
    } catch(e) {}
  }

  window.saveUserSettings = async function(settings) {
    await fetch('./api_user.php?action=settings_save', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({settings})
    });
  };

  window.currentUser = currentUser;
  window._authReady  = true;

  // ── Panggil initAkun jika pending ───────────────────────
  if (typeof window._pendingInitAkun === 'function') {
    const fn = window._pendingInitAkun;
    window._pendingInitAkun = null;
    fn();
  }

  // ── Developer check — verify dari SERVER, tidak expose ke window ──
  let isDev = false;
  try {
    const devRes = await fetch('./api_user.php?action=is_developer', {credentials:'include', cache:'no-store'});
    const devJ = await devRes.json();
    isDev = (devJ.ok === true && devJ.is_dev === true);
  } catch(e) { isDev = false; }
  // isDev tidak di-assign ke window — cegah bypass dari console
  
  function applyRoleUI() {
    if (!isDev) {
      ['nav-upload-wrap','mob-upload-wrap'].forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.style.display='none';
      });
      // Sembunyikan link developer dari non-dev
      const devNav = document.getElementById('nav-dev-item');
      if (devNav) devNav.style.display = 'none';
    } else {
      const devNav = document.getElementById('nav-dev-item');
      if (devNav) devNav.style.display = '';
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyRoleUI);
  } else {
    applyRoleUI();
  }
  setTimeout(applyRoleUI, 500);

  // Keep session alive — ping setiap 10 menit
  setInterval(async () => {
    try {
      const res = await fetch('./auth.php?action=me', { credentials: 'include' });
      const j   = await res.json();
      if (!j.ok) window.location.href = './login.html';
    } catch(e) {}
  }, 10 * 60 * 1000);

})();
