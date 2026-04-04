<?php
/**
 * fundamental_idx.php — Multi-source fundamental data saham IDX
 *
 * Usage:
 *   ?kode=BBCA                → ambil semua source
 *   ?kode=BBCA&source=yahoo   → paksa 1 source
 *   ?kode=BBCA&debug_idx=1    → lihat raw response dari semua endpoint IDX
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$CONFIG = [
    'sectors_key' => getenv('SECTORS_KEY') ?: '',
    'fmp_key'     => getenv('FMP_KEY')     ?: '',
];

$kode     = strtoupper(trim($_GET['kode']   ?? 'BBCA'));
$forceSrc = strtolower($_GET['source']      ?? '');
$ticker   = $kode . '.JK';

function http_get(string $url, array $headers = [], int $timeout = 15): ?string {
    $ctx = stream_context_create(['http' => [
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => implode("\r\n", $headers),
        'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return ($raw !== false && strlen($raw) > 5) ? $raw : null;
}

function fix_mktcap($v): ?float {
    if ($v === null) return null;
    $v = (float)$v;
    return ($v > 1e14) ? round($v / 100) : round($v);
}

function fmt_mktcap(?float $v): ?string {
    if ($v === null) return null;
    if ($v >= 1e12) return round($v / 1e12, 2) . ' T';
    if ($v >= 1e9)  return round($v / 1e9,  2) . ' B';
    if ($v >= 1e6)  return round($v / 1e6,  2) . ' M';
    return (string)$v;
}

// SOURCE 1: Sectors.app
function source_sectors(string $kode, string $apiKey): ?array {
    if (!$apiKey) return null;
    $url = "https://api.sectors.app/v1/company/report/{$kode}/?sections=overview,financials,valuation";
    $raw = http_get($url, ["Authorization: {$apiKey}", "Accept: application/json"]);
    if (!$raw) return null;
    $j = json_decode($raw, true);
    if (!$j || isset($j['error'])) return null;
    $val = $j['valuation']  ?? [];
    $fin = $j['financials'] ?? [];
    $ov  = $j['overview']   ?? [];
    return [
        'source' => 'sectors.app',
        'per'    => $val['pe_ratio']          ?? $val['trailing_pe']   ?? null,
        'pbv'    => $val['pb_ratio']          ?? $val['price_to_book'] ?? null,
        'roe'    => $fin['roe']               ?? null,
        'roa'    => $fin['roa']               ?? null,
        'npm'    => $fin['net_profit_margin'] ?? $fin['profit_margin'] ?? null,
        'der'    => $fin['debt_to_equity']    ?? null,
        'eps'    => $fin['eps']               ?? null,
        'mktcap' => $ov['market_cap']         ?? null,
    ];
}

// SOURCE 2: IDX/BEI — 3 endpoint + debug mode
function source_idx(string $kode): ?array {
    $ref = ["Accept: application/json", "Referer: https://www.idx.co.id/"];

    $urlA = "https://www.idx.co.id/umbraco/Surface/Helper/GetStockSummary?IDXCode={$kode}";
    $rawA = http_get($urlA, array_merge($ref, ["Origin: https://www.idx.co.id"]));
    $jA   = $rawA ? json_decode($rawA, true) : null;

    $urlB = "https://www.idx.co.id/umbraco/Surface/StockData/GetStockScreener?" .
            http_build_query(['start'=>0,'length'=>1,'code'=>$kode]);
    $rawB = http_get($urlB, $ref);
    $jB   = $rawB ? json_decode($rawB, true) : null;

    $urlC = "https://idx.co.id/api/v1/company-profiles?code={$kode}";
    $rawC = http_get($urlC, $ref);
    $jC   = $rawC ? json_decode($rawC, true) : null;

    // Debug: tampilkan semua raw response IDX
    if (isset($_GET['debug_idx'])) {
        echo json_encode([
            'debug_idx' => true,
            'A_GetStockSummary'  => ['url'=>$urlA, 'raw'=>$rawA ? substr($rawA,0,1000) : null, 'json'=>$jA],
            'B_GetStockScreener' => ['url'=>$urlB, 'raw'=>$rawB ? substr($rawB,0,1000) : null, 'json'=>$jB],
            'C_APIv1'            => ['url'=>$urlC, 'raw'=>$rawC ? substr($rawC,0,1000) : null, 'json'=>$jC],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Parse A
    if (!empty($jA)) {
        $s = isset($jA[0]) ? $jA[0] : $jA;
        if (is_array($s) && !empty($s)) {
            return [
                'source' => 'idx (summary)',
                'per'    => $s['PER'] ?? $s['per'] ?? $s['PriceEarningRatio'] ?? null,
                'pbv'    => $s['PBV'] ?? $s['pbv'] ?? $s['PriceBookValue']    ?? null,
                'roe'    => $s['ROE'] ?? $s['roe'] ?? null,
                'roa'    => $s['ROA'] ?? $s['roa'] ?? null,
                'npm'    => $s['NPM'] ?? $s['npm'] ?? $s['NetProfitMargin']   ?? null,
                'der'    => $s['DER'] ?? $s['der'] ?? $s['DebtEquityRatio']   ?? null,
                'eps'    => $s['EPS'] ?? $s['eps'] ?? $s['EarningPerShare']   ?? null,
                'mktcap' => $s['MarketCap'] ?? $s['market_cap']               ?? null,
                '_raw'   => $s,
            ];
        }
    }

    // Parse B
    $s2 = $jB['data'][0] ?? null;
    if ($s2) {
        return [
            'source' => 'idx (screener)',
            'per'    => $s2['PER'] ?? $s2['per'] ?? null,
            'pbv'    => $s2['PBV'] ?? $s2['pbv'] ?? null,
            'roe'    => $s2['ROE'] ?? $s2['roe'] ?? null,
            'roa'    => $s2['ROA'] ?? $s2['roa'] ?? null,
            'npm'    => $s2['NPM'] ?? $s2['npm'] ?? null,
            'der'    => $s2['DER'] ?? $s2['der'] ?? null,
            'eps'    => $s2['EPS'] ?? $s2['eps'] ?? null,
            'mktcap' => $s2['MarketCap']          ?? null,
            '_raw'   => $s2,
        ];
    }

    // Parse C
    $s3 = $jC['data'][0] ?? $jC[0] ?? null;
    if ($s3) {
        return [
            'source' => 'idx (api v1)',
            'per'    => $s3['PER'] ?? $s3['per'] ?? null,
            'pbv'    => $s3['PBV'] ?? $s3['pbv'] ?? null,
            'roe'    => $s3['ROE'] ?? $s3['roe'] ?? null,
            'roa'    => $s3['ROA'] ?? $s3['roa'] ?? null,
            'npm'    => $s3['NPM'] ?? $s3['npm'] ?? null,
            'der'    => $s3['DER'] ?? $s3['der'] ?? null,
            'eps'    => $s3['EPS'] ?? $s3['eps'] ?? null,
            'mktcap' => $s3['MarketCap'] ?? $s3['market_cap'] ?? null,
            '_raw'   => $s3,
        ];
    }

    return null;
}

// SOURCE 3: Yahoo Finance
function source_yahoo(string $ticker): ?array {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $ctx = stream_context_create(['http'=>[
        'timeout'=>15,'ignore_errors'=>true,'user_agent'=>$ua,
        'header'=>"Accept: text/html\r\nAccept-Language: en-US,en;q=0.9\r\n",
    ]]);
    @file_get_contents("https://finance.yahoo.com/quote/{$ticker}/", false, $ctx);
    $cookie = '';
    foreach ($http_response_header ?? [] as $h) {
        if (stripos($h,'set-cookie:')===0) {
            $p = explode(';', substr($h,11));
            $cookie .= trim($p[0]).'; ';
        }
    }
    if (!$cookie) return null;

    $crumbRaw = http_get(
        "https://query1.finance.yahoo.com/v1/test/getcrumb",
        ["Cookie: {$cookie}", "Accept: */*", "Referer: https://finance.yahoo.com/"]
    );
    $crumb = ($crumbRaw && strlen($crumbRaw) < 50) ? trim($crumbRaw) : null;
    if (!$crumb) return null;

    $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}"
         . "?modules=financialData,defaultKeyStatistics,summaryDetail&crumb=".urlencode($crumb);
    $raw = http_get($url, ["Cookie: {$cookie}", "Accept: application/json", "Referer: https://finance.yahoo.com/"]);
    if (!$raw) return null;

    $j = json_decode($raw, true);
    $r = $j['quoteSummary']['result'][0] ?? null;
    if (!$r) return null;

    $fd = $r['financialData']        ?? [];
    $ks = $r['defaultKeyStatistics'] ?? [];
    $sd = $r['summaryDetail']        ?? [];

    return [
        'source' => 'yahoo_finance',
        'per'    => $sd['trailingPE']['raw']  ?? null,
        'pbv'    => $ks['priceToBook']['raw'] ?? null,
        'roe'    => isset($fd['returnOnEquity']['raw'])  ? round($fd['returnOnEquity']['raw']*100, 2)  : null,
        'roa'    => isset($fd['returnOnAssets']['raw'])  ? round($fd['returnOnAssets']['raw']*100, 2)  : null,
        'npm'    => isset($fd['profitMargins']['raw'])   ? round($fd['profitMargins']['raw']*100, 2)   : null,
        'der'    => $ks['debtToEquity']['raw'] ?? null,
        'eps'    => $ks['trailingEps']['raw']  ?? null,
        'mktcap' => isset($sd['marketCap']['raw']) ? fix_mktcap($sd['marketCap']['raw']) : null,
    ];
}

// SOURCE 4: FMP
function source_fmp(string $ticker, string $apiKey): ?array {
    if (!$apiKey) return null;
    $base = "https://financialmodelingprep.com/api/v3";
    $r = json_decode(http_get("{$base}/ratios-ttm/{$ticker}?apikey={$apiKey}", ["Accept: application/json"]) ?? '[]', true)[0] ?? [];
    $p = json_decode(http_get("{$base}/profile/{$ticker}?apikey={$apiKey}",    ["Accept: application/json"]) ?? '[]', true)[0] ?? [];
    if (!$r && !$p) return null;
    return [
        'source' => 'fmp',
        'per'    => $r['peRatioTTM']              ?? null,
        'pbv'    => $r['priceToBookRatioTTM']      ?? null,
        'roe'    => isset($r['returnOnEquityTTM'])  ? round($r['returnOnEquityTTM']*100,  2) : null,
        'roa'    => isset($r['returnOnAssetsTTM'])  ? round($r['returnOnAssetsTTM']*100,  2) : null,
        'npm'    => isset($r['netProfitMarginTTM']) ? round($r['netProfitMarginTTM']*100, 2) : null,
        'der'    => $r['debtEquityRatioTTM']       ?? null,
        'eps'    => $p['eps']                      ?? null,
        'mktcap' => $p['mktCap']                   ?? null,
    ];
}

// MERGE
function merge_results(array $sources): array {
    $merged = ['per'=>null,'pbv'=>null,'roe'=>null,'roa'=>null,'npm'=>null,'der'=>null,'eps'=>null,'mktcap'=>null];
    $used   = [];
    foreach ($sources as $src) {
        if (!$src) continue;
        $used[] = $src['source'];
        foreach (array_keys($merged) as $k) {
            if ($merged[$k] === null && isset($src[$k]) && $src[$k] !== null) {
                $merged[$k] = $src[$k];
            }
        }
    }
    $merged['_sources'] = $used;
    return $merged;
}

// MAIN
if (isset($_GET['debug_idx'])) {
    source_idx($kode);
    exit;
}

$sourceOrder = match($forceSrc) {
    'sectors' => ['sectors'],
    'idx'     => ['idx'],
    'yahoo'   => ['yahoo'],
    'fmp'     => ['fmp'],
    default   => ['yahoo', 'idx', 'sectors', 'fmp'],
};

$rawSources = [];
foreach ($sourceOrder as $src) {
    $rawSources[$src] = match($src) {
        'sectors' => source_sectors($kode, $CONFIG['sectors_key']),
        'idx'     => source_idx($kode),
        'yahoo'   => source_yahoo($ticker),
        'fmp'     => source_fmp($ticker, $CONFIG['fmp_key']),
        default   => null,
    };
}

$merged      = merge_results(array_values($rawSources));
$finalMktcap = fix_mktcap($merged['mktcap']);
$hasData     = array_filter(
    array_intersect_key($merged, array_flip(['per','pbv','roe','roa','npm','der','eps','mktcap'])),
    fn($v) => $v !== null
);

echo json_encode([
    'kode'   => $kode,
    'ticker' => $ticker,
    'status' => $hasData ? 'ok' : 'no_data',
    'data'   => [
        'per'              => $merged['per'],
        'pbv'              => $merged['pbv'],
        'roe'              => $merged['roe'],
        'roa'              => $merged['roa'],
        'npm'              => $merged['npm'],
        'der'              => $merged['der'],
        'eps'              => $merged['eps'],
        'mktcap'           => $finalMktcap,
        'mktcap_formatted' => fmt_mktcap($finalMktcap),
    ],
    'sources_tried'   => $sourceOrder,
    'sources_success' => $merged['_sources'],
    'debug' => array_map(
        fn($v) => $v ? ['ok'=>true, 'source'=>$v['source']] : null,
        $rawSources
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
