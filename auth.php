<?php
/**
 * auth.php — Register, Login, Logout, Session check
 * Compatible PHP 7.2+
 */

// Tampilkan error sementara untuk debug
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Session ──────────────────────────────────────────────────
function ihsg_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', 86400);    // 24 jam
        ini_set('session.cookie_lifetime', 86400);   // 24 jam
        session_name('IHSG_SID');
        session_start();
    }
}

// ── Helper output ────────────────────────────────────────────
function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Ambil input JSON atau POST biasa ────────────────────────
function get_input() {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if ($j) return $j;
    }
    return array_merge($_GET, $_POST);
}

// ── Ambil user yang sedang login ─────────────────────────────
function ihsg_get_current_user() {
    ihsg_session_start();
    if (empty($_SESSION['uid']) || empty($_SESSION['logged_in'])) return null;
    $uid = (int)$_SESSION['uid'];
    try {
        $stmt = db()->prepare("SELECT id, username, email, created_at, last_login FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$uid]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// ── Rate limit: max 5 login gagal per 15 menit ───────────────
function is_rate_limited($identifier) {
    try {
        $pdo = db();
        $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->execute();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ?");
        $stmt->execute([$identifier]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Exception $e) {
        return false;
    }
}

function add_attempt($identifier) {
    try {
        db()->prepare("INSERT INTO login_attempts (identifier) VALUES (?)")->execute([$identifier]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════
//  ROUTING
// ════════════════════════════════════════════════════════════
$input  = get_input();
$action = strtolower($_GET['action'] ?? $input['action'] ?? '');

// ── GET ?action=me ───────────────────────────────────────────
if ($action === 'me') {
    $user = ihsg_get_current_user();
    if (!$user) out(['ok' => false, 'msg' => 'Belum login'], 401);
    out(['ok' => true, 'user' => $user]);
}

// ── POST register ────────────────────────────────────────────
if ($action === 'register') {
    $username = trim($input['username'] ?? '');
    $email    = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if (strlen($username) < 3 || strlen($username) > 30)
        out(['ok' => false, 'msg' => 'Username 3–30 karakter'], 400);

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username))
        out(['ok' => false, 'msg' => 'Username hanya boleh huruf, angka, underscore'], 400);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        out(['ok' => false, 'msg' => 'Email tidak valid'], 400);

    if (strlen($password) < 8)
        out(['ok' => false, 'msg' => 'Password minimal 8 karakter'], 400);

    $pdo = db();

    // Cek duplikat
    $cek = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
    $cek->execute([$email, $username]);
    if ($cek->fetch())
        out(['ok' => false, 'msg' => 'Email atau username sudah terdaftar'], 409);

    // Simpan user
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $ins  = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
    $ins->execute([$username, $email, $hash]);
    $uid = (int)$pdo->lastInsertId();

    // Buat settings kosong
    try {
        $pdo->prepare("INSERT INTO user_settings (user_id, settings) VALUES (?, '{}')")->execute([$uid]);
    } catch (Exception $e) {}

    // Auto login
    ihsg_session_start();
    session_regenerate_id(true);
    $_SESSION['uid']      = $uid;
    $_SESSION['username'] = $username;
    $_SESSION['logged_in'] = true;

    out(['ok' => true, 'msg' => 'Akun berhasil dibuat', 'user' => [
        'id'       => $uid,
        'username' => $username,
        'email'    => $email,
    ]]);
}

// ── POST login ───────────────────────────────────────────────
if ($action === 'login') {
    $email    = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!$email || !$password)
        out(['ok' => false, 'msg' => 'Email dan password wajib diisi'], 400);

    // Rate limit
    if (is_rate_limited($ip) || is_rate_limited($email))
        out(['ok' => false, 'msg' => 'Terlalu banyak percobaan. Coba lagi 15 menit lagi.'], 429);

    $pdo  = db();
    $stmt = $pdo->prepare("SELECT id, username, email, password FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        add_attempt($ip);
        add_attempt($email);
        out(['ok' => false, 'msg' => 'Email atau password salah'], 401);
    }

    // Update last login
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    // Set session
    ihsg_session_start();
    session_regenerate_id(true); // cegah session fixation
    $_SESSION['uid']      = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['logged_in'] = true;

    out(['ok' => true, 'msg' => 'Login berhasil', 'user' => [
        'id'       => $user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
    ]]);
}

// ── POST logout ──────────────────────────────────────────────
if ($action === 'logout') {
    ihsg_session_start();
    session_destroy();
    out(['ok' => true, 'msg' => 'Logout berhasil']);
}

out(['ok' => false, 'msg' => 'Action tidak dikenal'], 400);
