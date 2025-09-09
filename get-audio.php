<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

@set_time_limit(50);

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

// Salva cookies se existir env
$cookiesFile = '';
if ($cookieB64) {
  $cookiesFile = '/tmp/cookies.txt';
  file_put_contents($cookiesFile, base64_decode($cookieB64));
  if (!filesize($cookiesFile)) $cookiesFile = '';
}

// Cache simples por 10 minutos
$vid = null;
if (preg_match('~v=([A-Za-z0-9_-]{6,})~', $url, $m)) $vid = $m[1];
if (!$vid && preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)) $vid = $m[1];

$cacheTtl = 600; // 10 min
$cacheFile = $vid ? "/tmp/ytcache_{$vid}.json" : '';
if ($cacheFile && is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
  $cached = json_decode(file_get_contents($cacheFile), true);
  if (!empty($cached['audio_url'])) {
    echo json_encode($cached);
    exit;
  }
}

// Monta um comando único com múltiplos clients
$clients = 'android,web,ios,web_creator,tv,web_embedded';
$parts = [
  $envPrefix,
  escapeshellcmd($yt),
  '-f', 'bestaudio',
  '--no-playlist',
  '--force-ipv4',
  '--no-cache',
  '--no-warnings',
  '--socket-timeout', '12',
  '--sleep-requests', '1',
  '--retries', '1',
  '--user-agent', escapeshellarg($ua),
  '--extractor-args', escapeshellarg("youtube:player_client={$clients}"),
  '--get-url',
  escapeshellarg($url),
];
if ($cookiesFile) { $parts[]='--cookies'; $parts[]=escapeshellarg($cookiesFile); }
if ($proxy)       { $parts[]='--proxy';   $parts[]=escapeshellarg($proxy); }

$cmd = implode(' ', $parts) . ' 2>&1';

// Executa com timeout total de ~40s
$desc=[1=>['pipe','w']];
$proc=proc_open($cmd,$desc,$pipes);
if(!is_resource($proc)){
  http_response_code(500);
  echo json_encode(['error'=>'Falha ao iniciar extrator']); exit;
}
stream_set_blocking($pipes[1], false);
$start=microtime(true); $buf='';
$timeout=40;

while(true){
  $buf .= stream_get_contents($pipes[1]);
  $st = proc_get_status($proc);
  if(!$st['running']){ fclose($pipes[1]); $code=proc_close($proc); break; }
  if((microtime(true)-$start)>$timeout){
    proc_terminate($proc); fclose($pipes[1]); proc_close($proc);
    http_response_code(504);
    echo json_encode(['error'=>'Timeout ao extrair áudio']); exit;
  }
  usleep(150000);
}

$out = trim($buf);
if ($code!==0 || $out==='' || stripos($out,'ERROR:')!==false) {
  http_response_code(502);
  echo json_encode([
    'error'=>'Não foi possível extrair o áudio',
    'exit_code'=>$code,
    'details'=>$out,
    'cmd'=>$cmd,
  ]);
  exit;
}

// Pega a primeira linha que seja URL
$lines = preg_split('~\r?\n~', $out);
$audioUrl='';
foreach ($lines as $ln) {
  $ln = trim($ln);
  if (filter_var($ln, FILTER_VALIDATE_URL)) { $audioUrl = $ln; break; }
}

if (!$audioUrl) {
  http_response_code(502);
  echo json_encode(['error'=>'Saída inesperada do extrator','details'=>$out]);
  exit;
}

$response = [
  'video_url' => $url,
  'audio_url' => $audioUrl,
  'expires_hint' => 'URL temporária; use em poucas horas.'
];

// grava cache
if ($cacheFile) @file_put_contents($cacheFile, json_encode($response));

echo json_encode($response);
