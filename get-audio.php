<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
@set_time_limit(45);

$url = trim($_GET['url'] ?? '');
if ($url === '' || !preg_match('~^https?://(www\.)?(youtube\.com|youtu\.be)/~i', $url)) {
  http_response_code(400);
  echo json_encode(['error' => 'Informe uma URL válida do YouTube/youtu.be em ?url=']);
  exit;
}

$yt = '/usr/local/bin/yt-dlp';
$ua = getenv('YTDLP_UA') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$proxy = getenv('YTDLP_PROXY') ?: '';
$cookieB64 = getenv('YTDLP_COOKIES') ?: '';
$envPrefix = 'HOME=/tmp XDG_CACHE_HOME=/tmp';

$cookiesFile = '';
if ($cookieB64) {
  $cookiesFile = '/tmp/cookies.txt';
  file_put_contents($cookiesFile, base64_decode($cookieB64));
  // sanity: se ficou vazio, não use
  if (filesize($cookiesFile) === 0) $cookiesFile = '';
}

function buildCmd($yt,$url,$ua,$client,$cookiesFile,$proxy,$envPrefix) {
  $parts = [
    $envPrefix,
    escapeshellcmd($yt),
    '-f','bestaudio',
    '--no-playlist',
    '--force-ipv4',
    '--no-cache',
    '--no-warnings',
    '--socket-timeout','12',
    '--sleep-requests','1',
    '--retries','1',
    '--user-agent', escapeshellarg($ua),
    '--extractor-args', escapeshellarg("youtube:player_client=$client"),
  ];
  if ($cookiesFile) { $parts[]='--cookies'; $parts[]=escapeshellarg($cookiesFile); }
  if ($proxy) { $parts[]='--proxy'; $parts[]=escapeshellarg($proxy); }
  $parts[]='--get-url'; $parts[]=escapeshellarg($url);
  return implode(' ',$parts).' 2>&1';
}

function run($cmd,$timeout=35){
  $desc=[1=>['pipe','w']];
  $p=proc_open($cmd,$desc,$pipes);
  if(!is_resource($p)) return [null,"failed to start"];
  stream_set_blocking($pipes[1],false);
  $start=microtime(true); $buf='';
  while(true){
    $buf.=stream_get_contents($pipes[1]);
    $st=proc_get_status($p);
    if(!$st['running']){ fclose($pipes[1]); $code=proc_close($p); return [$code,trim($buf)]; }
    if((microtime(true)-$start)>$timeout){ proc_terminate($p); fclose($pipes[1]); proc_close($p); return ['timeout',trim($buf)]; }
    usleep(120000);
  }
}

$clients = [
  'android',
  'web',          // tentar o web normal
  'web_creator',  // criador
  'ios',
  'tv',           // clientes de TV às vezes escapam do block
];

$last = [];
foreach ($clients as $client) {
  $cmd = buildCmd($yt,$url,$ua,$client,$cookiesFile,$proxy,$envPrefix);
  [$code,$out] = run($cmd, 35);
  $last = ['client'=>$client,'code'=>$code,'out'=>$out,'cmd'=>$cmd];
  if ($code===0 && $out && stripos($out,'ERROR:')===false) {
    $line = trim(preg_split('~\r?\n~',$out)[0] ?? '');
    if (filter_var($line, FILTER_VALIDATE_URL)) {
      echo json_encode([
        'video_url'=>$url,
        'audio_url'=>$line,
        'client_used'=>$client,
        'expires_hint'=>'URL temporária; use em poucas horas.'
      ]);
      exit;
    }
  }
  // Se for 429/bot-check, nem adianta tentar outros muitas vezes
  if (stripos($out,'HTTP Error 429')!==false || stripos($out,'confirm you’re not a bot')!==false) break;
}

// Falhou
$hints=[];
if ($last['code']==='timeout') $hints[]='Rede lenta/YouTube instável. Considere proxy (YTDLP_PROXY) e cookies (YTDLP_COOKIES).';
if (stripos($last['out'],'Failed to extract any player response')!==false) $hints[]='Use yt-dlp nightly (ajuste Dockerfile) ou atualize com yt-dlp -U.';
if (stripos($last['out'],'429')!==false) $hints[]='YouTube limitou (429). Use cookies e/ou proxy. Reduza frequência e adote cache.';

http_response_code($last['code']==='timeout'?504:502);
echo json_encode([
  'error'=>'Não foi possível extrair o áudio',
  'exit_code'=>$last['code'],
  'client_tried'=>$last['client'] ?? null,
  'details'=>$last['out'] ?? null,
  'cmd'=>$last['cmd'] ?? null,
  'hints'=>$hints,
]);
