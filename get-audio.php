<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

@set_time_limit(25);

$url = trim($_GET['url'] ?? '');
if ($url === '') {
  http_response_code(400);
  echo json_encode(['error' => "Parâmetro 'url' é obrigatório"]);
  exit;
}
if (!preg_match('~^https?://(www\.)?(youtube\.com|youtu\.be)/~i', $url)) {
  http_response_code(400);
  echo json_encode(['error' => 'URL inválida (YouTube/youtu.be)']);
  exit;
}

$yt = '/usr/local/bin/yt-dlp';

// prepara cookies se existir env var
$cookiesFile = '';
$cookieB64 = getenv('YTDLP_COOKIES'); // conteúdo do cookies.txt em base64 (opcional)
if ($cookieB64) {
  $cookiesFile = '/tmp/cookies.txt';
  file_put_contents($cookiesFile, base64_decode($cookieB64));
}

// user-agent “real”
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

// monta comando
$parts = [
  escapeshellcmd($yt),
  '-f', 'bestaudio',
  '--no-playlist',
  '--force-ipv4',
  '--no-cache',
  '--no-warnings',
  '--socket-timeout', '6',
  '--sleep-requests', '1',
  '--retries', '1',
  '--user-agent', escapeshellarg($ua),
  '--extractor-args', escapeshellarg('youtube:player_client=android'),
  '--get-url',
  escapeshellarg($url),
];

if ($cookiesFile) {
  $parts = array_merge(['XDG_CACHE_HOME=/tmp'], $parts, ['--cookies', escapeshellarg($cookiesFile)]);
} else {
  $parts = array_merge(['XDG_CACHE_HOME=/tmp'], $parts);
}

$cmd = implode(' ', $parts) . ' 2>&1';

// executa com timeout curto
$descriptors = [1 => ['pipe', 'w']];
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
  http_response_code(500);
  echo json_encode(['error' => 'Falha ao iniciar extrator']);
  exit;
}
$timeout = 12;
$start = microtime(true);
$stdout = '';
stream_set_blocking($pipes[1], false);

while (true) {
  $stdout .= stream_get_contents($pipes[1]);
  $st = proc_get_status($proc);
  if (!$st['running']) break;
  if ((microtime(true) - $start) > $timeout) {
    proc_terminate($proc);
    fclose($pipes[1]); proc_close($proc);
    http_response_code(504);
    echo json_encode(['error' => 'Timeout ao extrair áudio']);
    exit;
  }
  usleep(100000);
}
fclose($pipes[1]);
$code = proc_close($proc);

$stdout = trim($stdout);
if ($code !== 0 || $stdout === '' || stripos($stdout, 'ERROR:') !== false) {
  http_response_code(502);
  echo json_encode([
    'error' => 'Não foi possível extrair o áudio',
    'details' => $stdout
  ]);
  exit;
}

// pode vir múltiplas linhas
$lines = preg_split('~\r?\n~', $stdout);
$audioUrl = trim($lines[0] ?? '');

echo json_encode([
  'video_url' => $url,
  'audio_url' => $audioUrl,
  'expires_hint' => 'URL temporária; use em poucas horas.'
]);
