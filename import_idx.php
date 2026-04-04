<?php
/**
 * import_idx.php
 * ─────────────────────────────────────────────────────────────
 * Upload XLSX dari IDX Screener → ambil info + DER dari IDX
 * → semua fundamental (PER,PBV,ROE,ROA,NPM,MktCap,EPS) dari Yahoo Finance
 * → simpan ke data.json (permanen, tidak hilang saat refresh)
 *
 * POST: upload file XLSX
 * GET ?action=status  → cek data.json
 * GET ?action=enrich  → update Yahoo data saja (tanpa re-upload)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
set_time_limit(300);

define('DATA_FILE', __DIR__ . '/data.json');

// ════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════
function http_get(string $url, array $headers = [], int $timeout = 20): ?string {
    $ctx = stream_context_create(['http' => [
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => implode("\r\n", $headers),
        'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
    ]]);
    $r = @file_get_contents($url, false, $ctx);
    return ($r !== false && strlen($r) > 5) ? $r : null;
}

function sf($v): ?float {
    if ($v === null || $v === '' || $v === '-') return null;
    $n = (float) str_replace(',', '.', (string)$v);
    return is_nan($n) ? null : round($n, 6);
}

function fix_mktcap($v): ?float {
    if ($v === null) return null;
    $v = (float)$v;
    // Yahoo kadang return mktcap IDX dalam satuan sen (x100)
    return ($v > 1e14) ? round($v / 100) : round($v);
}

// ════════════════════════════════════════════════
//  PARSE XLSX (tanpa library eksternal)
// ════════════════════════════════════════════════
function parse_xlsx(string $path): array {
    if (!file_exists($path)) return ['error' => 'File tidak ditemukan'];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return ['error' => 'Gagal buka file XLSX'];

    // Shared strings
    $ss  = [];
    $ssX = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssX) {
        $xml = simplexml_load_string($ssX);
        foreach ($xml->si as $si) {
            $txt = '';
            foreach ($si->xpath('.//t') as $t) $txt .= (string)$t;
            $ss[] = $txt;
        }
    }

    // Sheet1
    $shX = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$shX) return ['error' => 'Sheet tidak ditemukan'];

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
    if (empty($rows)) return ['error' => 'Sheet kosong'];

    $headers = $rows[0];
    $stocks  = [];
    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $d = [];
        foreach ($headers as $ci => $hdr) $d[$hdr] = $r[$ci] ?? null;

        $kode = trim((string)($d['Kode Saham'] ?? $d['kode'] ?? ''));
        if (!$kode || strlen($kode) < 2 || strlen($kode) > 6) continue;

        $stocks[] = [
            'kode'        => $kode,
            'nama'        => (string)($d['Nama Perusahaan'] ?? ''),
            'sektor'      => (string)($d['Sektor']          ?? '-'),
            'subsektor'   => (string)($d['Subsektor']       ?? '-'),
            'industri'    => (string)($d['Industri']        ?? '-'),
            'subindustri' => (string)($d['Subindustri']     ?? '-'),
            'index'       => (string)($d['Index']           ?? ''),
            // DER → HANYA dari IDX (Yahoo tidak reliable untuk bank IDX)
            'der'         => sf($d['DER'] ?? $d['der']),
            // Pergerakan harga historis → dari IDX
            'chg4w'       => sf($d['4-wk %Pr. Chg.']  ?? null),
            'chg13w'      => sf($d['13-wk %Pr. Chg.'] ?? null),
            'chg26w'      => sf($d['26-wk %Pr. Chg.'] ?? null),
            'chg52w'      => sf($d['52-wk %Pr. Chg.'] ?? null),
            'ytd'         => sf($d['YTD'] ?? null),
            'rev'         => sf($d['Total Rev'] ?? null),
            // Field berikut diisi Yahoo Finance
            'per'    => null, 'pbv'   => null, 'roe'   => null,
            'roa'    => null, 'npm'   => null, 'eps'   => null,
            'mktcap' => null, 'harga' => null, 'chg1d' => null,
            'hi52w'  => null, 'lo52w' => null, 'vol'   => null,
        ];
    }
    return $stocks;
}

// ════════════════════════════════════════════════
//  YAHOO FINANCE — cookie + crumb
// ════════════════════════════════════════════════
function yahoo_auth(): array {
    $ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36';
    $ctx = stream_context_create(['http' => [
        'timeout' => 15, 'ignore_errors' => true, 'user_agent' => $ua,
        'header'  => "Accept: text/html\r\nAccept-Language: en-US,en;q=0.9\r\n",
    ]]);
    @file_get_contents('https://finance.yahoo.com/', false, $ctx);
    $cookie = '';
    foreach ($http_response_header ?? [] as $h) {
        if (stripos($h, 'set-cookie:') === 0) {
            $p = explode(';', substr($h, 11));
            $cookie .= trim($p[0]) . '; ';
        }
    }
    if (!$cookie) return ['cookie' => '', 'crumb' => null];

    $crumbRaw = http_get(
        'https://query1.finance.yahoo.com/v1/test/getcrumb',
        ["Cookie: {$cookie}", "Accept: */*", "Referer: https://finance.yahoo.com/"]
    );
    $crumb = ($crumbRaw && strlen($crumbRaw) < 50) ? trim($crumbRaw) : null;
    return ['cookie' => $cookie, 'crumb' => $crumb];
}

// ════════════════════════════════════════════════
//  YAHOO FINANCE — batch quote (harga + fundamental)
// ════════════════════════════════════════════════
function yahoo_batch(array $tickers, string $cookie, ?string $crumb): array {
    $symbols = implode(',', $tickers);
    $fields  = implode(',', [
        'regularMarketPrice',
        'regularMarketChangePercent',
        'fiftyTwoWeekHigh',
        'fiftyTwoWeekLow',
        'regularMarketVolume',
        'trailingEps',
        'marketCap',
        'trailingPE',       // PER
        'priceToBook',      // PBV
        'returnOnEquity',   // ROE
        'returnOnAssets',   // ROA
        'profitMargins',    // NPM
    ]);

    $url = 'https://query1.finance.yahoo.com/v7/finance/quote'
         . '?symbols=' . urlencode($symbols)
         . '&fields='  . urlencode($fields);
    if ($crumb) $url .= '&crumb=' . urlencode($crumb);

    $raw = http_get($url, [
        "Cookie: {$cookie}",
        "Accept: application/json",
        "Referer: https://finance.yahoo.com/",
    ]);
    if (!$raw) return [];

    $j    = json_decode($raw, true);
    $list = $j['quoteResponse']['result'] ?? [];
    $out  = [];

    foreach ($list as $q) {
        $kode = strtoupper(str_replace('.JK', '', $q['symbol'] ?? ''));

        // ROE / ROA / NPM di v7 sudah dalam desimal (0.21 = 21%) → kali 100
        $roe = isset($q['returnOnEquity'])  ? round($q['returnOnEquity']  * 100, 2) : null;
        $roa = isset($q['returnOnAssets'])  ? round($q['returnOnAssets']  * 100, 2) : null;
        $npm = isset($q['profitMargins'])   ? round($q['profitMargins']   * 100, 2) : null;

        $out[$kode] = [
            'harga'  => $q['regularMarketPrice']           ?? null,
            'chg1d'  => isset($q['regularMarketChangePercent'])
                            ? round($q['regularMarketChangePercent'], 2) : null,
            'hi52w'  => $q['fiftyTwoWeekHigh']             ?? null,
            'lo52w'  => $q['fiftyTwoWeekLow']              ?? null,
            'vol'    => $q['regularMarketVolume']           ?? null,
            'eps'    => $q['trailingEps']                   ?? null,
            'mktcap' => isset($q['marketCap'])   ? fix_mktcap($q['marketCap'])   : null,
            'per'    => $q['trailingPE']                    ?? null,
            'pbv'    => $q['priceToBook']                   ?? null,
            'roe'    => $roe,
            'roa'    => $roa,
            'npm'    => $npm,
        ];
    }
    return $out;
}

// ════════════════════════════════════════════════
//  ENRICH: isi field Yahoo ke array stocks
// ════════════════════════════════════════════════
function enrich_yahoo(array &$stocks): array {
    $auth = yahoo_auth();
    if (!$auth['cookie']) return ['ok'=>false,'msg'=>'Gagal dapat cookie Yahoo Finance'];

    $tickers = array_map(fn($s) => $s['kode'].'.JK', $stocks);
    $chunks  = array_chunk($tickers, 40);
    $allQ    = [];
    $bOk = $bFail = 0;

    foreach ($chunks as $chunk) {
        $q = yahoo_batch($chunk, $auth['cookie'], $auth['crumb']);
        if ($q) { $allQ = array_merge($allQ, $q); $bOk++; }
        else $bFail++;
        if (count($chunks) > 1) usleep(350000);
    }

    // Field yang diisi dari Yahoo
    $yahooFields = ['harga','chg1d','hi52w','lo52w','vol','eps','mktcap','per','pbv','roe','roa','npm','der','rev'];
    $enriched = 0;

    foreach ($stocks as &$s) {
        $q = $allQ[$s['kode']] ?? null;
        if (!$q) continue;
        foreach ($yahooFields as $f) {
            $newVal = $q[$f] ?? null;
            if ($newVal === null) continue;
            // Jangan overwrite dengan 0 — Yahoo v7 return 0 saat data unavailable
            if (in_array($f, ['roe','roa','npm','per','pbv']) && $newVal == 0) continue;
            $s[$f] = $newVal;
        }
        $enriched++;
    }
    unset($s);

    return [
        'ok'       => true,
        'enriched' => $enriched,
        'total'    => count($stocks),
        'batches'  => ['ok' => $bOk, 'fail' => $bFail],
        'msg'      => "{$enriched}/".count($stocks)." saham di-enrich dari Yahoo Finance",
    ];
}

// ════════════════════════════════════════════════
//  SAVE / LOAD data.json
// ════════════════════════════════════════════════
function save_data(array $stocks, array $meta = []): bool {
    return file_put_contents(DATA_FILE, json_encode([
        'updated' => date('c'),
        'source'  => 'IDX Screener (info+DER) + Yahoo Finance (fundamental+harga)',
        'total'   => count($stocks),
        'meta'    => $meta,
        'stocks'  => $stocks,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

function load_data(): ?array {
    if (!file_exists(DATA_FILE)) return null;
    $d = json_decode(file_get_contents(DATA_FILE), true);
    return $d['stocks'] ?? null;
}

// ════════════════════════════════════════════════
//  ROUTING
// ════════════════════════════════════════════════
$action = strtolower($_GET['action'] ?? 'upload');

// GET ?action=status
if ($action === 'status') {
    if (!file_exists(DATA_FILE)) {
        echo json_encode(['status'=>'no_data','msg'=>'Belum ada data. Upload file XLSX IDX dulu.']);
    } else {
        $d = json_decode(file_get_contents(DATA_FILE), true);
        echo json_encode([
            'status'  => 'ok',
            'updated' => $d['updated'] ?? null,
            'total'   => $d['total']   ?? 0,
            'source'  => $d['source']  ?? null,
        ]);
    }
    exit;
}

// GET ?action=enrich — update Yahoo data tanpa re-upload
if ($action === 'enrich') {
    $stocks = load_data();
    if (!$stocks) { echo json_encode(['ok'=>false,'msg'=>'Belum ada data. Upload XLSX dulu.']); exit; }
    $res = enrich_yahoo($stocks);
    save_data($stocks, ['enrich' => $res]);
    echo json_encode(['ok'=>true,'enrich'=>$res], JSON_PRETTY_PRINT);
    exit;
}

// ════════════════════════════════════════════════
//  PRESERVE: Salin data Yahoo lama ke stocks baru
//  Tujuan: upload XLSX baru TIDAK menghapus ROE/ROA/NPM dll
// ════════════════════════════════════════════════
function preserve_yahoo_data(array &$stocks): int {
    $oldStocks = load_data();
    if (!$oldStocks) return 0;

    $oldMap = [];
    foreach ($oldStocks as $s) $oldMap[$s['kode']] = $s;

    $yahooFields = ['harga','chg1d','hi52w','lo52w','vol','eps','mktcap','per','pbv','roe','roa','npm','der','rev'];
    $preserved   = 0;

    foreach ($stocks as &$s) {
        $old = $oldMap[$s['kode']] ?? null;
        if (!$old) continue;
        $any = false;
        foreach ($yahooFields as $f) {
            if ($s[$f] !== null) continue;              // data baru sudah ada, skip
            $oldVal = $old[$f] ?? null;
            if ($oldVal === null) continue;             // data lama juga kosong, skip
            // Jangan preserve 0 untuk field fundamental — Yahoo return 0 saat data unavailable
            if (in_array($f, ['roe','roa','npm','per','pbv']) && $oldVal == 0) continue;
            $s[$f] = $oldVal;
            $any   = true;
        }
        if ($any) $preserved++;
    }
    unset($s);
    return $preserved;
}

// POST — upload XLSX
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'msg'=>'POST multipart/form-data dengan field "file" (.xlsx)']);
    exit;
}

$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'msg'=>'File error: '.($file['error'] ?? 'tidak ada file')]);
    exit;
}
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
    echo json_encode(['ok'=>false,'msg'=>'Hanya .xlsx yang diterima']);
    exit;
}

// 1. Parse XLSX → ambil info + DER dari IDX
$stocks = parse_xlsx($file['tmp_name']);
if (isset($stocks['error'])) { echo json_encode(['ok'=>false,'msg'=>$stocks['error']]); exit; }
if (count($stocks) < 5)      { echo json_encode(['ok'=>false,'msg'=>'Terlalu sedikit data valid ('.count($stocks).')']); exit; }

// 2. ✅ PRESERVE: Salin data Yahoo lama (ROE/ROA/NPM/PER/PBV/harga dll) ke stocks baru
//    Supaya upload Excel TIDAK menghapus data fundamental yang sudah ada
$preserved = preserve_yahoo_data($stocks);

// 3. Enrich fundamental + harga dari Yahoo Finance
$enrichRes = enrich_yahoo($stocks);

// 3. Simpan ke data.json
$saved = save_data($stocks, ['source_file'=>$file['name'], 'enrich'=>$enrichRes]);

echo json_encode([
    'ok'        => $saved,
    'msg'       => $saved ? '✅ Berhasil disimpan ke data.json' : '❌ Gagal tulis data.json (cek permission)',
    'parsed'    => count($stocks),
    'preserved' => $preserved,
    'enrich'    => $enrichRes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
