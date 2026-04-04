<?php
// save_ara.php — Terima data IDX dari developer panel, simpan ke ara_data.json
// FIXED: session_name IHSG_SID + key uid & logged_in (harus sama persis api_user.php)
header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Session — HARUS identik dengan api_user.php ───────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.cookie_lifetime', 86400);
    session_name('IHSG_SID');   // ← INI yang menyebabkan Unauthorized sebelumnya
    session_start();
}

// ── Cek login — key sama persis dengan api_user.php ──────────
if (empty($_SESSION['uid']) || empty($_SESSION['logged_in'])) {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']); exit;
}

// ── Cek developer only ────────────────────────────────────────
require_once __DIR__ . '/db.php';
$pdo  = db();
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
$stmt->execute([(int)$_SESSION['uid']]);
$me = $stmt->fetch();

$devEmails = ['jevriekosatria@gmail.com'];
if (!$me || !in_array($me['email'], $devEmails)) {
    echo json_encode(['ok' => false, 'msg' => 'Akses ditolak — bukan developer']); exit;
}

// ── Baca body JSON ────────────────────────────────────────────
$raw = file_get_contents('php://input');
$p   = json_decode($raw, true);
if (!$p) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid JSON']); exit;
}

$stocks = $p['data'] ?? $p['stocks'] ?? null;
$date   = $p['date'] ?? date('Y-m-d');

// Handle reset — kirim data kosong untuk hapus ara_data.json
$isReset = isset($p['reset']) && $p['reset'] === true;
if ($isReset || !$stocks || !count($stocks)) {
    // Simpan file kosong → frontend tampilkan pesan Telegram
    $empty = ['date' => '', 'updated' => date('c'), 'count' => 0, 'data' => []];
    file_put_contents(__DIR__ . '/ara_data.json', json_encode($empty));
    echo json_encode(['ok' => true, 'msg' => 'Data direset. User akan lihat pesan join Telegram.']);
    exit;
}

// ── Simpan ke ara_data.json ───────────────────────────────────
$mode    = $p['mode']     ?? '1-hari';
$days    = $p['days_analyzed'] ?? 1;
$dayDates= $p['day_dates']   ?? [$date];
$multiAnalysis = $p['multi_analysis'] ?? null;

$save = [
    'date'           => $date,
    'updated'        => date('c'),
    'count'          => count($stocks),
    'saved_by'       => $me['email'],
    'mode'           => $mode,
    'days_analyzed'  => $days,
    'day_dates'      => $dayDates,
    'data'           => $stocks,
    'multi_analysis' => $multiAnalysis,
    'momentum_data'  => $p['momentum_data'] ?? null,
];

$ok = file_put_contents(__DIR__ . '/ara_data.json', json_encode($save, JSON_UNESCAPED_UNICODE));
if ($ok === false) {
    echo json_encode(['ok' => false, 'msg' => 'Gagal tulis ara_data.json — cek permission (chmod 664)']); exit;
}

echo json_encode(['ok' => true, 'msg' => 'Tersimpan ' . count($stocks) . ' saham untuk ' . $date]);
