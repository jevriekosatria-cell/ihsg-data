<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$action   = $_GET['action'] ?? 'status';
$dataFile = __DIR__ . '/data.json';
$progFile = __DIR__ . '/fetch_progress.json';
$logFile  = __DIR__ . '/fetch_log.txt';

// ── Load / Save progress ──
function loadProg($f){ return file_exists($f)?json_decode(file_get_contents($f),true):['batch'=>0,'total'=>0,'success'=>0,'failed'=>0,'saved'=>0]; }
function saveProg($f,$p){ file_put_contents($f,json_encode($p)); }

// ── Load stocks_meta ──
function loadMeta(){
    $f=__DIR__.'/stocks_meta.json';
    if(!file_exists($f)) return [];
    return json_decode(file_get_contents($f),true)??[];
}

// ── Fetch Yahoo Finance ──
function fetchYahoo($kode){
    $ticker=urlencode($kode.'.JK');
    $url="https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?interval=1d&range=1y";
    $ctx=stream_context_create(['http'=>['timeout'=>15,'user_agent'=>'Mozilla/5.0','ignore_errors'=>true]]);
    $raw=@file_get_contents($url,false,$ctx);
    if(!$raw) return null;
    $j=json_decode($raw,true);
    $r=$j['chart']['result'][0]??null;
    if(!$r) return null;
    $meta=$r['meta']??[];
    $closes=$r['indicators']['quote'][0]['close']??[];
    $closes=array_values(array_filter($closes,fn($v)=>$v!==null));
    $n=count($closes);
    if($n===0) return null;
    $last=$closes[$n-1];
    $prev=$meta['chartPreviousClose']??($n>1?$closes[$n-2]:$last);
    $chg1d=$prev>0?round(($last-$prev)/$prev*100,2):null;
    $hi52=$n>0?max($closes):null;
    $lo52=$n>0?min($closes):null;
    // chg periods
    $chg=fn($d)=>$n>$d&&$closes[$n-$d-1]>0?round(($last-$closes[$n-$d-1])/$closes[$n-$d-1]*100,2):null;
    return[
        'harga'=>round($last,0),'chg1d'=>$chg1d,
        'hi52w'=>$hi52,'lo52w'=>$lo52,
        'chg4w'=>$chg(20),'chg13w'=>$chg(65),
        'chg26w'=>$chg(130),'chg52w'=>$chg(252),
        'updated'=>date('c'),
    ];
}

switch($action){

case 'status':
    $p=loadProg($progFile);
    $done=isset($p['done'])&&$p['done'];
    echo json_encode([
        'total'  =>$p['total']??0,
        'next'   =>$p['next']??0,
        'success'=>$p['success']??0,
        'failed' =>$p['failed']??0,
        'saved'  =>$p['saved']??0,
        'done'   =>$done,
    ]);
    break;

case 'reset':
    saveProg($progFile,['batch'=>0,'next'=>0,'total'=>0,'success'=>0,'failed'=>0,'saved'=>0,'done'=>false]);
    echo json_encode(['ok'=>true,'message'=>'Progress reset']);
    break;

case 'fetch':
    $batchSize=5;
    $p=loadProg($progFile);

    // Load semua kode dari data.json
    $stocks=[];
    if(file_exists($dataFile)){
        $d=json_decode(file_get_contents($dataFile),true);
        $stocks=$d['stocks']??[];
    }
    if(empty($stocks)){ echo json_encode(['status'=>'error','message'=>'data.json kosong']); exit; }

    $total=count($stocks);
    if(!isset($p['total'])||$p['total']!==$total) $p['total']=$total;
    if(!isset($p['next'])) $p['next']=0;

    if($p['next']>=$total){ 
        $p['done']=true; saveProg($progFile,$p);
        echo json_encode(['status'=>'done','total'=>$total,'fetched'=>$p['success']??0]);
        exit;
    }

    // Ambil batch
    $batch=array_slice($stocks,$p['next'],$batchSize);
    $updated=[];
    foreach($batch as $s){
        $kode=$s['kode']??'';
        if(!$kode) continue;
        $y=fetchYahoo($kode);
        if($y){ $p['success']=($p['success']??0)+1; $updated[$kode]=$y; }
        else   { $p['failed']=($p['failed']??0)+1; }
    }

    // Merge ke data.json
    if(!empty($updated)){
        foreach($stocks as &$s){
            $kode=$s['kode']??'';
            if(isset($updated[$kode])) $s=array_merge($s,$updated[$kode]);
        }
        unset($s);
        $save=['updated'=>date('c'),'stocks'=>$stocks];
        file_put_contents($dataFile,json_encode($save));
        $p['saved']=($p['saved']??0)+count($updated);
    }

    $p['batch']=($p['batch']??0)+1;
    $p['next']+=count($batch);
    if($p['next']>=$total) $p['done']=true;
    saveProg($progFile,$p);

    echo json_encode([
        'status' =>$p['done']?'done':'progress',
        'batch'  =>$p['batch'],
        'total'  =>$total,
        'next'   =>$p['next'],
        'fetched'=>$p['success']??0,
        'failed' =>$p['failed']??0,
    ]);
    break;

case 'save_meta':
    // Terima data fundamental dari browser setelah upload Excel
    $raw=file_get_contents('php://input');
    $payload=json_decode($raw,true);
    if(!$payload||!isset($payload['stocks'])||!count($payload['stocks'])){
        echo json_encode(['success'=>false,'message'=>'Data kosong']); exit;
    }
    $newMeta=[];
    foreach($payload['stocks'] as $s) $newMeta[$s['kode']]=$s;

    // Load harga existing dari data.json
    $existing=[];
    if(file_exists($dataFile)){
        $old=json_decode(file_get_contents($dataFile),true);
        if($old&&isset($old['stocks'])) foreach($old['stocks'] as $s) $existing[$s['kode']]=$s;
    }

    // Merge: fundamental baru + harga lama
    $merged=[];
    foreach($newMeta as $kode=>$meta){
        $h=$existing[$kode]??[];
        $merged[]=array_merge($meta,[
            'harga'  =>$h['harga']??null,  'chg1d' =>$h['chg1d']??null,
            'chg52w' =>$h['chg52w']??null, 'chg4w' =>$h['chg4w']??null,
            'chg13w' =>$h['chg13w']??null, 'chg26w'=>$h['chg26w']??null,
            'hi52w'  =>$h['hi52w']??null,  'lo52w' =>$h['lo52w']??null,
            'vol'    =>$h['vol']??null,     'updated'=>$h['updated']??null,
        ]);
    }

    $save=['updated'=>$payload['updated']??date('c'),'stocks'=>$merged];
    $ok=file_put_contents($dataFile,json_encode($save));
    if($ok===false){ echo json_encode(['success'=>false,'message'=>'Gagal tulis data.json']); }
    else{ echo json_encode(['success'=>true,'saved'=>count($merged)]); }
    break;

default:
    echo json_encode(['error'=>'Unknown action: '.$action]);
}
