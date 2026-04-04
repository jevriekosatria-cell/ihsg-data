<?php
/**
 * api_user.php — API data per-user (standalone)
 */
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Output helper ────────────────────────────────────────────
function api_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Session ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.cookie_lifetime', 86400);
    session_name('IHSG_SID');
    session_start();
}

// ── Cek login ────────────────────────────────────────────────
if (empty($_SESSION['uid']) || empty($_SESSION['logged_in'])) {
    api_out(['ok' => false, 'msg' => 'Unauthorized'], 401);
}
$uid = (int)$_SESSION['uid'];

// ── Ambil data user dari DB ───────────────────────────────────
$pdo  = db();
$stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$uid]);
$me = $stmt->fetch();
if (!$me) api_out(['ok' => false, 'msg' => 'User tidak ditemukan'], 401);

// ── Input ────────────────────────────────────────────────────
$raw    = file_get_contents('php://input');
$body   = $raw ? (json_decode($raw, true) ?: []) : [];

// ── is_developer — cek apakah user adalah developer (server-side only) ──
if ($action === 'is_developer') {
    if (empty($_SESSION['uid']) || empty($_SESSION['logged_in'])) {
        api_out(['ok' => false, 'is_dev' => false]);
    }
    $stmt = db()->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['uid']]);
    $user = $stmt->fetch();
    $dev_emails = ['jevriekosatria@gmail.com'];
    $is_dev = $user && in_array($user['email'], $dev_emails);
    api_out(['ok' => true, 'is_dev' => $is_dev]);
}

$action = strtolower($_GET['action'] ?? $body['action'] ?? '');

// ── GET watchlist ────────────────────────────────────────────
if ($action === 'watchlist') {
    $stmt = $pdo->prepare("SELECT kode, note, added_at FROM watchlist WHERE user_id=? ORDER BY added_at DESC");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();
    // Parse entry_price dari note (format: "entry:12345")
    foreach ($rows as &$row) {
        $row['entry_price'] = null;
        if ($row['note'] && strpos($row['note'], 'entry:') === 0) {
            $row['entry_price'] = (float) substr($row['note'], 6);
        }
    }
    api_out(['ok' => true, 'watchlist' => $rows]);
}

// ── POST watchlist_add ───────────────────────────────────────
if ($action === 'watchlist_add') {
    $kode = strtoupper(trim($body['kode'] ?? ''));
    $note = substr(trim($body['note'] ?? ''), 0, 255);
    if (!$kode) api_out(['ok' => false, 'msg' => 'Kode wajib diisi'], 400);
    try {
        $pdo->prepare("INSERT INTO watchlist (user_id, kode, note) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE note=VALUES(note)")
            ->execute([$uid, $kode, $note ?: null]);
        api_out(['ok' => true, 'msg' => "$kode ditambahkan ke watchlist"]);
    } catch (Exception $e) {
        api_out(['ok' => false, 'msg' => 'Gagal menyimpan'], 500);
    }
}

// ── POST watchlist_remove ────────────────────────────────────
if ($action === 'watchlist_remove') {
    $kode = strtoupper(trim($body['kode'] ?? ''));
    if (!$kode) api_out(['ok' => false, 'msg' => 'Kode wajib diisi'], 400);
    $pdo->prepare("DELETE FROM watchlist WHERE user_id=? AND kode=?")->execute([$uid, $kode]);
    api_out(['ok' => true, 'msg' => "$kode dihapus dari watchlist"]);
}

// ── GET settings ─────────────────────────────────────────────
if ($action === 'settings') {
    $stmt = $pdo->prepare("SELECT settings FROM user_settings WHERE user_id=?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    api_out(['ok' => true, 'settings' => $row ? json_decode($row['settings'], true) : []]);
}

// ── POST settings_save ───────────────────────────────────────
if ($action === 'settings_save') {
    $settings = $body['settings'] ?? [];
    if (!is_array($settings)) api_out(['ok' => false, 'msg' => 'Settings harus object'], 400);
    $allowed  = ['theme','filterPresets','defaultSort','defaultView','notifications','language'];
    $filtered = array_intersect_key($settings, array_flip($allowed));
    $pdo->prepare("INSERT INTO user_settings (user_id, settings) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE settings=VALUES(settings), updated_at=NOW()")
        ->execute([$uid, json_encode($filtered)]);
    api_out(['ok' => true, 'msg' => 'Settings tersimpan']);
}

// ── POST change_password ─────────────────────────────────────
if ($action === 'change_password') {
    $old = $body['old_password'] ?? '';
    $new = $body['new_password'] ?? '';
    if (strlen($new) < 8) api_out(['ok' => false, 'msg' => 'Password minimal 8 karakter'], 400);
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!password_verify($old, $user['password']))
        api_out(['ok' => false, 'msg' => 'Password lama tidak sesuai'], 401);
    $hash = password_hash($new, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $uid]);
    api_out(['ok' => true, 'msg' => 'Password berhasil diubah']);
}

// ── GET list_users (developer only) ──────────────────────────
if ($action === 'list_users') {
    $devEmails = ['jevriekosatria@gmail.com'];
    if (!in_array($me['email'], $devEmails))
        api_out(['ok' => false, 'msg' => 'Akses ditolak'], 403);
    $stmt = $pdo->prepare("SELECT id, username, email, created_at, last_login FROM users ORDER BY id ASC");
    $stmt->execute();
    api_out(['ok' => true, 'users' => $stmt->fetchAll()]);
}

// ── POST save_idx_data (developer only) ──────────────────────
if ($action === 'save_idx_data') {
    $devEmails = ['jevriekosatria@gmail.com'];
    if (!in_array($me['email'], $devEmails))
        api_out(['ok' => false, 'msg' => 'Akses ditolak'], 403);

    $stocks = $body['stocks'] ?? null;
    $meta   = $body['meta']   ?? [];

    if (!$stocks || !is_array($stocks))
        api_out(['ok' => false, 'msg' => 'Data stocks wajib diisi'], 400);

    $payload = [
        'stocks'     => $stocks,
        'meta'       => $meta,
        'saved_at'   => date('Y-m-d H:i:s'),
        'saved_by'   => $me['username'],
        'total'      => count($stocks),
    ];

    $cacheFile = __DIR__ . '/idx_data_cache.json';
    $result = file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));

    if ($result === false)
        api_out(['ok' => false, 'msg' => 'Gagal menyimpan file cache'], 500);

    api_out([
        'ok'       => true,
        'msg'      => 'Data IDX berhasil disimpan ke server',
        'total'    => count($stocks),
        'saved_at' => $payload['saved_at'],
    ]);
}

// ── GET get_idx_data (publik, semua user bisa akses) ─────────
if ($action === 'get_idx_data') {
    $cacheFile = __DIR__ . '/idx_data_cache.json';

    if (!file_exists($cacheFile))
        api_out(['ok' => false, 'msg' => 'Data belum tersedia. Developer belum upload data IDX.'], 404);

    $raw = file_get_contents($cacheFile);
    if (!$raw)
        api_out(['ok' => false, 'msg' => 'Gagal membaca data cache'], 500);

    $data = json_decode($raw, true);
    if (!$data)
        api_out(['ok' => false, 'msg' => 'Data cache corrupt'], 500);

    api_out([
        'ok'       => true,
        'stocks'   => $data['stocks']   ?? [],
        'meta'     => $data['meta']     ?? [],
        'saved_at' => $data['saved_at'] ?? null,
        'saved_by' => $data['saved_by'] ?? null,
        'total'    => $data['total']    ?? 0,
    ]);
}

api_out(['ok' => false, 'msg' => 'Action tidak dikenal: ' . $action], 400);
