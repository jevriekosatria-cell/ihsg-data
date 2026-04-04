<?php
/**
 * update_der.php
 * Update HANYA field DER dari file XLSX IDX
 * Field lain (ROE, ROA, NPM, harga, dll) TIDAK disentuh sama sekali
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
set_time_limit(120);

define('DATA_FILE', __DIR__ . '/data.json');

// ── Auth: hanya developer ────────────────────────────────
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

// ── Load data.json yang ada ───────────────────────────────
if (!file_exists(DATA_FILE)) {
    echo json_encode(['ok'=>false,'msg'=>'data.json tidak ditemukan']); exit;
}
$dataRaw = json_decode(file_get_contents(DATA_FILE), true);
if (!$dataRaw || !isset($dataRaw['stocks'])) {
    echo json_encode(['ok'=>false,'msg'=>'data.json rusak']); exit;
}

// ── Handle GET ?action=status ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok'      => true,
        'total'   => count($dataRaw['stocks']),
        'updated' => $dataRaw['updated'] ?? null,
        'msg'     => 'Ready. POST file XLSX untuk update DER saja.',
    ]);
    exit;
}

// ── Parse XLSX: ambil HANYA Kode + DER ───────────────────
$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'msg'=>'Upload file XLSX']); exit;
}
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
    echo json_encode(['ok'=>false,'msg'=>'Hanya .xlsx']); exit;
}

// Parse XLSX
$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    echo json_encode(['ok'=>false,'msg'=>'Gagal buka XLSX']); exit;
}
$ss = [];
$ssX = $zip->getFromName('xl/sharedStrings.xml');
if ($ssX) {
    $xml = simplexml_load_string($ssX);
    foreach ($xml->si as $si) {
        $txt = '';
        foreach ($si->xpath('.//t') as $t) $txt .= (string)$t;
        $ss[] = $txt;
    }
}
$shX = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
if (!$shX) { echo json_encode(['ok'=>false,'msg'=>'Sheet tidak ditemukan']); exit; }

$sheet = simplexml_load_string($shX);
$rows  = [];
foreach ($sheet->sheetData->row as $row) {
    $rowData = [];
    foreach ($row->c as $cell) {
        $type  = (string)($cell['t'] ?? '');
        $value = (string)($cell->v ?? '');
        if ($type === 's') $value = $ss[(int)$value] ?? '';
        preg_match('/([A-Z]+)/', (string)$cell['r'], $m);
        $col = 0;
        foreach (str_split($m[1]) as $ch)
            $col = $col * 26 + (ord($ch) - ord('A') + 1);
        $rowData[$col - 1] = $value;
    }
    $rows[] = $rowData;
}

// Buat map: kode → DER dari XLSX
$headers = $rows[0] ?? [];
$derMap  = [];
for ($i = 1; $i < count($rows); $i++) {
    $r    = $rows[$i];
    $d    = [];
    foreach ($headers as $ci => $hdr) $d[$hdr] = $r[$ci] ?? null;
    $kode = trim((string)($d['Kode Saham'] ?? $d['kode'] ?? ''));
    if (!$kode || strlen($kode) < 2 || strlen($kode) > 6) continue;

    // Cari kolom DER
    $derRaw = $d['DER'] ?? $d['der'] ?? $d['Debt to Equity'] ?? null;
    if ($derRaw === null || $derRaw === '' || $derRaw === '-') continue;
    $derVal = (float) str_replace(',', '.', (string)$derRaw);
    if (!is_nan($derVal)) $derMap[$kode] = round($derVal, 6);
}

if (count($derMap) < 5) {
    echo json_encode(['ok'=>false,'msg'=>'DER tidak ditemukan di XLSX. Pastikan ada kolom "DER" atau "Debt to Equity"']); exit;
}

// ── Update HANYA DER di data.json ─────────────────────────
$updated  = 0;
$notFound = 0;
$stocks   = &$dataRaw['stocks'];

foreach ($stocks as &$s) {
    $kode = $s['kode'] ?? '';
    if (!isset($derMap[$kode])) { $notFound++; continue; }
    $s['der'] = $derMap[$kode];  // ← HANYA ini yang berubah
    $updated++;
}
unset($s);

$dataRaw['updated'] = date('c');

// Simpan kembali
$saved = file_put_contents(
    DATA_FILE,
    json_encode($dataRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo json_encode([
    'ok'        => $saved !== false,
    'msg'       => $saved !== false
        ? "✅ DER berhasil diupdate untuk {$updated} saham. Field lain tidak disentuh."
        : '❌ Gagal tulis data.json (cek permission)',
    'der_updated' => $updated,
    'not_found'   => $notFound,
    'der_in_xlsx' => count($derMap),
    'sample'      => array_slice(
        array_map(fn($k,$v)=>"{$k}: {$v}", array_keys($derMap), $derMap),
        0, 5
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
