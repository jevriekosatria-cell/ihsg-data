<?php
/**
 * db.php — Koneksi database
 * #SaTri41997
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'u967259794_IHSG_Screener');
define('DB_USER', 'u967259794_jevriekosatria');
define('DB_PASS', '#SaTri41997');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['ok'=>false,'msg'=>'Database connection failed']));
    }
    return $pdo;
}
