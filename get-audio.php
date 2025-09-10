<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

@set_time_limit(90);

$url = trim($_GET['url'] ?? '');
if ($url === '' || !preg_match('~^https?://(www\.)?(youtube\.com|youtu\.be)/~i', $url)) {
  http_response_code(400);
  echo json_encode(['error' => 'Informe uma URL válida do YouTube/youtu.be em ?url='], JSON_PRETTY_PRINT);
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
$cookiesOption = $cookiesFile ? "--cookies " . escapeshellarg($cookiesFile) : "";

// 1) Obter informações do vídeo
$cmdInfo = "$envPrefix $yt --quiet --no-warnings $cookiesOption --user-agent " . escapeshellarg($ua) . " --dump-json " . escapeshellarg($url);
exec($cmdInfo . " 2>&1", $outInfo, $codeInfo);

if ($codeInfo !== 0 || empty($outInfo)) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível obter informações do vídeo', 'debug' => $outInfo], JSON_PRETTY_PRINT);
    exit;
}

$videoInfo = json_decode(implode("\n", $outInfo), true);
$duration  = $videoInfo['duration'] ?? 0;
$title     = $videoInfo['title'] ?? 'Sem título';

// 2) Bloquear vídeos restritos
if (!empty($videoInfo['age_limit']) && $videoInfo['age_limit'] > 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Este vídeo é restrito por idade ou requer login.'], JSON_PRETTY_PRINT);
    exit;
}

// 3) Limitar duração (10 min)
$limiteSegundos = 600;
if ($duration > $limiteSegundos) {
    http_response_code(400);
    echo json_encode([
        'error'   => 'Vídeo muito longo. Limite: 10 minutos.',
        'duracao' => gmdate("i:s", $duration)
    ], JSON_PRETTY_PRINT);
    exit;
}

// 4) Extrair URL de áudio
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

$desc=[1=>['pipe','w']];
$proc=proc_open($cmd,$desc,$pipes);
if (!is_resource($proc)) {
    http_response_code(500);
    echo json_encode(['error'=>'Falha ao iniciar extrator'], JSON_PRETTY_PRINT);
    exit;
}

stream_set_blocking($pipes[1], true);
$timeout = 60;
$start = microtime(true);
$audioUrl = '';

while ((microtime(true) - $start) < $timeout && ($line = fgets($pipes[1])) !== false) {
    $line = trim($line);
    if (filter_var($line, FILTER_VALIDATE_URL)) {
        $audioUrl = $line;
        proc_terminate($proc);
        break;
    }
}
fclose($pipes[1]);
$code = proc_close($proc);

if (!$audioUrl) {
    http_response_code(502);
    echo json_encode([
      'error' => 'Não foi possível extrair o áudio',
      'exit_code' => $code
    ], JSON_PRETTY_PRINT);
    exit;
}

// 5) Retorno final
$response = [
  'title'    => $title,
  'duration' => gmdate("i:s", $duration), // minutos:segundos
  'audio'    => $audioUrl
];
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
