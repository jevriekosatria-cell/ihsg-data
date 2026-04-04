<?php
/**
 * tracker.php — Visitor tracking & stats
 * Taruh di root (sama dengan auth.php, db.php)
 */

require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Pastikan tabel visitor_logs ada ──────────────────────────
function ensureTable() {
    db()->exec("CREATE TABLE IF NOT EXISTS visitor_logs (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_address  VARCHAR(45)  NOT NULL,
        user_id     INT UNSIGNED NULL,
        page        VARCHAR(255) NULL,
        user_agent  VARCHAR(500) NULL,
        visited_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip   (ip_address),
        INDEX idx_date (visited_at),
        INDEX idx_uid  (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getIP() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function getCurrentUserId() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('IHSG_SID');
        session_start();
    }
    return $_SESSION['uid'] ?? null;
}

$action = strtolower($_GET['action'] ?? '');

// ── ACTION: hit — catat kunjungan ────────────────────────────
if ($action === 'hit') {
    try {
        ensureTable();
        $raw  = file_get_contents('php://input');
        $body = $raw ? (json_decode($raw, true) ?? []) : [];
        $page = substr(trim($body['page'] ?? $_GET['page'] ?? ''), 0, 255);
        $ip   = getIP();
        $uid  = getCurrentUserId();
        $ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        // Skip bot umum
        $botPattern = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|preview/i';
        if (preg_match($botPattern, $ua)) {
            out(['ok' => true, 'msg' => 'bot skipped']);
        }

        // Cek apakah IP ini sudah tercatat dalam 30 menit terakhir
        $stmt = db()->prepare("SELECT id FROM visitor_logs WHERE ip_address = ? AND visited_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1");
        $stmt->execute([$ip]);
        if ($stmt->fetch()) {
            out(['ok' => true, 'msg' => 'already tracked']);
        }

        db()->prepare("INSERT INTO visitor_logs (ip_address, user_id, page, user_agent) VALUES (?,?,?,?)")
           ->execute([$ip, $uid, $page, $ua]);

        out(['ok' => true]);
    } catch (Exception $e) {
        out(['ok' => false, 'msg' => $e->getMessage()]);
    }
}

// ── ACTION: stats — ambil statistik (developer only) ─────────
if ($action === 'stats') {
    // Cek developer
    $devEmails = ['jevriekosatria@gmail.com'];
    if (session_status() === PHP_SESSION_NONE) {
        session_name('IHSG_SID');
        session_start();
    }
    if (empty($_SESSION['uid'])) out(['ok' => false, 'msg' => 'Unauthorized'], 401);

    try {
        $uid  = (int)$_SESSION['uid'];
        $stmt = db()->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        if (!$user || !in_array($user['email'], $devEmails))
            out(['ok' => false, 'msg' => 'Forbidden'], 403);
    } catch (Exception $e) {
        out(['ok' => false, 'msg' => 'DB error'], 500);
    }

    try {
        ensureTable();
        $pdo = db();

        // Total hits & unique IP
        $totalHits = (int)$pdo->query("SELECT COUNT(*) FROM visitor_logs")->fetchColumn();
        $uniqueIP  = (int)$pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitor_logs")->fetchColumn();

        // Hari ini
        $hitsToday   = (int)$pdo->query("SELECT COUNT(*) FROM visitor_logs WHERE DATE(visited_at)=CURDATE()")->fetchColumn();
        $uniqueToday = (int)$pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE DATE(visited_at)=CURDATE()")->fetchColumn();

        // 7 hari
        $hits7   = (int)$pdo->query("SELECT COUNT(*) FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
        $unique7 = (int)$pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();

        // 30 hari
        $hits30   = (int)$pdo->query("SELECT COUNT(*) FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn();
        $unique30 = (int)$pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn();

        // Total registered users
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

        // Logged in vs guest (7 hari)
        $loggedIn = (int)$pdo->query("SELECT COUNT(*) FROM visitor_logs WHERE user_id IS NOT NULL AND visited_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
        $guest    = (int)$pdo->query("SELECT COUNT(*) FROM visitor_logs WHERE user_id IS NULL AND visited_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();

        // Trend 7 hari
        $trend = $pdo->query("
            SELECT DATE(visited_at) as tanggal,
                   COUNT(*) as hits,
                   COUNT(DISTINCT ip_address) as unique_ip
            FROM visitor_logs
            WHERE visited_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)
            GROUP BY DATE(visited_at)
            ORDER BY tanggal ASC
        ")->fetchAll();

        out([
            'ok'          => true,
            'total_hits'  => $totalHits,
            'unique_ip'   => $uniqueIP,
            'today'       => ['hits' => $hitsToday,  'unique' => $uniqueToday],
            '7days'       => ['hits' => $hits7,       'unique' => $unique7],
            '30days'      => ['hits' => $hits30,      'unique' => $unique30],
            'total_users' => $totalUsers,
            'login_vs_guest' => ['logged_in' => $loggedIn, 'guest' => $guest],
            'trend'       => $trend,
        ]);

    } catch (Exception $e) {
        out(['ok' => false, 'msg' => $e->getMessage()]);
    }
}

out(['ok' => false, 'msg' => 'Action tidak dikenal'], 400);
