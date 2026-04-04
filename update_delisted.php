<?php
/**
 * update_delisted.php — v2
 * Simpan ke delisted.json TERPISAH dari data.json
 * Sehingga data.json (958 saham) tidak pernah disentuh
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

define('DELIST_FILE', __DIR__ . '/delisted.json');

// Auth
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_name('IHSG_SID');
    session_start();
}
if (empty($_SESSION['uid']) || empty($_SESSION['logged_in'])) {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}
require_once __DIR__ . '/db.php';
$pdo  = db();
$stmt = $pdo->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
$stmt->execute([(int)$_SESSION['uid']]);
$me = $stmt->fetch();
if (!$me || !in_array($me['email'], ['jevriekosatria@gmail.com'])) {
    echo json_encode(['ok'=>false,'msg'=>'Akses ditolak']); exit;
}

// Load delisted.json (file kecil, bukan data.json!)
$delisted = [];
if (file_exists(DELIST_FILE)) {
    $raw = json_decode(file_get_contents(DELIST_FILE), true);
    $delisted = array_values(array_unique($raw['delisted_stocks'] ?? []));
}

// GET → return list
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok'=>true, 'delisted'=>$delisted, 'count'=>count($delisted)]);
    exit;
}

// POST → add / remove
$body   = json_decode(file_get_contents('php://input'), true);
$action = strtolower($body['action'] ?? '');
$kode   = strtoupper(trim($body['kode'] ?? ''));

if (!$kode || strlen($kode) < 2 || strlen($kode) > 6) {
    echo json_encode(['ok'=>false,'msg'=>'Kode tidak valid']); exit;
}

if ($action === 'add') {
    if (!in_array($kode, $delisted)) $delisted[] = $kode;
    $msg = "$kode diblock untuk semua user";
} elseif ($action === 'remove') {
    $delisted = array_values(array_filter($delisted, fn($k) => $k !== $kode));
    $msg = "$kode dihapus dari daftar block";
} else {
    echo json_encode(['ok'=>false,'msg'=>'Action tidak valid']); exit;
}

// Simpan ke delisted.json SAJA — data.json tidak disentuh sama sekali
$saved = file_put_contents(DELIST_FILE, json_encode(
    ['updated' => date('c'), 'delisted_stocks' => $delisted],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
));

echo json_encode([
    'ok'      => $saved !== false,
    'msg'     => $saved !== false ? "✅ $msg" : '❌ Gagal simpan (cek permission)',
    'delisted'=> $delisted,
    'count'   => count($delisted),
]);
