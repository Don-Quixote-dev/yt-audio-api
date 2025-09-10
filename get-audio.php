<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

@set_time_limit(90);

$url = trim($_GET['url'] ?? '');
if ($url === '' || !preg_match('~^https?://(www\.)?(youtube\.com|youtu\.be)/~i', $url)) {
  http_response_code(400);
  echo json_encode(['error' => 'Informe uma URL válida do YouTube/youtu.be em ?url=']);
  exit;
}

$yt    = '/usr/local/bin/yt-dlp';
$ua    = getenv('YTDLP_UA') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$proxy = getenv('YTDLP_PROXY') ?: '';
$cookieB64 = getenv('YTDLP_COOKIES') ?: '';

$envPrefix = 'HOME=/tmp XDG_CACHE_HOME=/tmp';

// cookies
$cookiesFile = '';
if ($cookieB64) {
  $cookiesFile = '/tmp/cookies.txt';
  file_put_contents($cookiesFile, base64_decode($cookieB64));
  if (!filesize($cookiesFile)) $cookiesFile = '';
}

// cache (10 min)
$vid = null;
if (preg_match('~v=([A-Za-z0-9_-]{6,})~', $url, $m)) $vid = $m[1];
if (!$vid && preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)) $vid = $m[1];

$cacheTtl = 600;
$cacheFile = $vid ? "/tmp/ytcache_{$vid}.json" : '';
if ($cacheFile && is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
  $cached = json_decode(file_get_contents($cacheFile), true);
  if (!empty($cached['audio_url'])) {
    echo json_encode($cached);
    exit;
  }
}

// monta comando
$clients = 'android,web,ios,web_creator,tv,web_embedded';
$parts = [
  $envPrefix,
  escapeshellcmd($yt),
  '--quiet',
  '-f', 'bestaudio',
  '--no-playlist',
  '--force-ipv4',
  '--no-cache',
  '--socket-timeout','20',
  '--sleep-requests','2',
  '--retries','2',
  '--user-agent', escapeshellarg($ua),
  '--extractor-args', escapeshellarg("youtube:player_client={$clients}"),
  '--get-url',
  escapeshellarg($url),
];
if ($cookiesFile) { $parts[]='--cookies'; $parts[]=escapeshellarg($cookiesFile); }
if ($proxy)       { $parts[]='--proxy';   $parts[]=escapeshellarg($proxy); }

$cmd = implode(' ', $parts) . ' 2>&1';

// executa
$desc = [1 => ['pipe','w']];
$proc = proc_open($cmd, $desc, $pipes);
if (!is_resource($proc)) {
  http_response_code(500);
  echo json_encode(['error'=>'Falha ao iniciar extrator']); exit;
}
stream_set_blocking($pipes[1], true);

$timeout = 60;
$start = microtime(true);
$audioUrl = '';
$lastLine = '';

while ((microtime(true) - $start) < $timeout && ($line = fgets($pipes[1])) !== false) {
  $lastLine = trim($line);
  if (filter_var($lastLine, FILTER_VALIDATE_URL)) {
    $audioUrl = $lastLine;
    proc_terminate($proc);
    break;
  }
  usleep(100000);
}

fclose($pipes[1]);
$code = proc_close($proc);

if (!$audioUrl) {
  http_response_code($code === 'timeout' ? 504 : 502);
  echo json_encode([
    'error' => $code === 'timeout' ? 'Timeout ao extrair áudio' : 'Não foi possível extrair o áudio',
    'exit_code' => $code,
    'last_output' => $lastLine,
    'cmd' => $cmd
  ]);
  exit;
}

// sucesso
$response = [
  'video_url'   => $url,
  'audio_url'   => $audioUrl,
  'expires_hint'=> 'URL temporária; use em poucas horas.'
];

if ($cacheFile) @file_put_contents($cacheFile, json_encode($response));

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
