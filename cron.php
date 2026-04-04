<?php
/**
 * IHSG Screener — Cron Trigger v4 (Resume-based)
 *
 * Cara kerja:
 * - Setiap run fetch 1 batch (30 saham) sebanyak mungkin dalam 25 menit
 * - Kalau belum selesai, cron berikutnya otomatis lanjut (resume)
 * - Kalau sudah selesai semua, reset progress untuk hari berikutnya
 *
 * Pasang 4 cron di hPanel (hari kerja):
 *   0  2 * * 1-5   php /home/u967259794/public_html/cron.php  → 09:00 WIB
 *   30 2 * * 1-5   php /home/u967259794/public_html/cron.php  → 09:30 WIB
 *   0  5 * * 1-5   php /home/u967259794/public_html/cron.php  → 12:00 WIB
 *   30 5 * * 1-5   php /home/u967259794/public_html/cron.php  → 12:30 WIB
 *   0  9 * * 1-5   php /home/u967259794/public_html/cron.php  → 16:00 WIB
 *   30 9 * * 1-5   php /home/u967259794/public_html/cron.php  → 16:30 WIB
 *   0  13 * * 1-5  php /home/u967259794/public_html/cron.php  → 20:00 WIB
 *   30 13 * * 1-5  php /home/u967259794/public_html/cron.php  → 20:30 WIB
 */

set_time_limit(1800); // 30 menit max
ini_set('memory_limit', '256M');
error_reporting(0);

$secret   = 'SaTri41997';
$logFile  = __DIR__ . '/fetch_log.txt';
$progFile = __DIR__ . '/fetch_progress.json';

// Keamanan
if (PHP_SAPI !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== $secret) {
        http_response_code(403);
        die('Forbidden');
    }
}

$isCli   = (PHP_SAPI === 'cli');
$timeMax = 25 * 60; // berhenti setelah 25 menit agar aman sebelum PHP timeout
$tStart  = time();

// Baca progress saat ini
$prog = file_exists($progFile)
    ? (json_decode(file_get_contents($progFile), true) ?? ['next' => 0, 'success' => 0, 'failed' => 0])
    : ['next' => 0, 'success' => 0, 'failed' => 0];

// Kalau sebelumnya sudah selesai semua → reset untuk sesi baru
$stocksMeta = json_decode(file_get_contents(__DIR__ . '/stocks_meta.json'), true);
$total      = count($stocksMeta);
if (($prog['next'] ?? 0) >= $total) {
    $prog = ['next' => 0, 'success' => 0, 'failed' => 0];
    file_put_contents($progFile, json_encode($prog));
}

$startMsg = "[" . date('Y-m-d H:i:s') . "] === CRON START — lanjut dari saham ke-" . ($prog['next'] ?? 0) . "/{$total} ===\n";
file_put_contents($logFile, $startMsg, FILE_APPEND);

$loop      = 0;
$done      = false;
$maxLoop   = 60; // max 60 batch per run (60 × 30 = 1800 saham, lebih dari cukup)

while (!$done && $loop < $maxLoop) {
    // Cek waktu — berhenti kalau mendekati batas
    if ((time() - $tStart) >= $timeMax) {
        $msg = "[" . date('Y-m-d H:i:s') . "] ⏱ Batas waktu 25 menit tercapai — resume di cron berikutnya\n";
        file_put_contents($logFile, $msg, FILE_APPEND);
        break;
    }

    $loop++;

    // Panggil fetch_batch action=fetch
    ob_start();
    $_GET['action'] = 'fetch';
    // Reset require cache trick — include ulang
    include __DIR__ . '/fetch_batch.php';
    $raw = ob_get_clean();

    $result = @json_decode($raw, true);

    if (!$result) {
        $msg = "[" . date('Y-m-d H:i:s') . "] Loop $loop: Gagal decode — stop\n";
        file_put_contents($logFile, $msg, FILE_APPEND);
        sleep(3);
        break;
    }

    if (!empty($result['error'])) {
        $msg = "[" . date('Y-m-d H:i:s') . "] Loop $loop: ERROR — " . ($result['msg'] ?? 'unknown') . "\n";
        file_put_contents($logFile, $msg, FILE_APPEND);
        sleep(5);
        continue;
    }

    $pct  = $result['pct']    ?? 0;
    $ok   = $result['cumOk']  ?? 0;
    $fail = $result['cumFail'] ?? 0;

    if ($loop % 5 === 0) { // log setiap 5 batch agar tidak terlalu verbose
        $msg = "[" . date('Y-m-d H:i:s') . "] Loop $loop — {$pct}% — ✅{$ok} ⚠{$fail}\n";
        file_put_contents($logFile, $msg, FILE_APPEND);
    }

    if (!empty($result['done'])) {
        $done    = true;
        $doneMsg = "[" . date('Y-m-d H:i:s') . "] === ✅ SELESAI! {$ok} berhasil, {$fail} gagal ===\n";
        file_put_contents($logFile, $doneMsg, FILE_APPEND);
    }

    sleep(1);
}

if (!$isCli) {
    header('Content-Type: application/json');
    $next = $prog['next'] ?? 0;
    echo json_encode([
        'done'    => $done,
        'loops'   => $loop,
        'elapsed' => (time() - $tStart) . 's',
        'msg'     => $done ? "✅ Semua {$total} saham selesai" : "⏸ Pause di saham ke-{$next}/{$total}, lanjut di cron berikutnya",
    ]);
}
