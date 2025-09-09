<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

@set_time_limit(40);

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

// Env/opcionais
$ua = getenv('YTDLP_UA') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$proxy = getenv('YTDLP_PROXY'); // ex: http://user:pass@host:port
$cookieB64 = getenv('YTDLP_COOKIES'); // cookies em base64 (Netscape)
// cache em /tmp para evitar permissão negada
$envPrefix = 'HOME=/tmp XDG_CACHE_HOME=/tmp';

$cookiesFile = '';
if ($cookieB64) {
  $cookiesFile = '/tmp/cookies.txt';
  file_put_contents($cookiesFile, base64_decode($cookieB64));
}

function buildCmd($yt, $url, $ua, $cookiesFile, $proxy, $envPrefix) {
  $parts = [
    $envPrefix,
    escapeshellcmd($yt),
    '-f', 'bestaudio',
    '--no-playlist',
    '--force-ipv4',
    '--no-cache',
    '--no-warnings',
    '--socket-timeout', '10',
    '--sleep-requests', '1',
    '--retries', '1',
    '--user-agent', escapeshellarg($ua),
    '--extractor-args', escapeshellarg('youtube:player_client=android'),
  ];
  if ($cookiesFile) { $parts[] = '--cookies'; $parts[] = escapeshellarg($cookiesFile); }
  if ($proxy)       { $parts[] = '--proxy';   $parts[] = escapeshellarg($proxy); }
  $parts[] = '--get-url';
  $parts[] = escapeshellarg($url);
  return implode(' ', $parts) . ' 2>&1';
}

function runWithTimeout($cmd, $timeoutSec = 32) {
  $desc = [1 => ['pipe','w']];
  $proc = proc_open($cmd, $desc, $pipes);
  if (!is_resource($proc)) return [null, "failed to start process"];
  stream_set_blocking($pipes[1], false);
  $start = microtime(true);
  $buf = '';
  while (true) {
    $buf .= stream_get_contents($pipes[1]);
    $st = proc_get_status($proc);
    if (!$st['running']) {
      fclose($pipes[1]);
      $code = proc_close($proc);
      return [$code, trim($buf)];
    }
    if ((microtime(true) - $start) > $timeoutSec) {
      proc_terminate($proc);
      fclose($pipes[1]); proc_close($proc);
      return ['timeout', trim($buf)];
    }
    usleep(120000);
  }
}

$attempts = 2; // tenta 2x
$last = ['code' => null, 'out' => null, 'cmd' => null];
for ($i = 1; $i <= $attempts; $i++) {
  $cmd = buildCmd($yt, $url, $ua, $cookiesFile, $proxy ?? '', $envPrefix);
  [$code, $out] = runWithTimeout($cmd, 32);
  $last = ['code' => $code, 'out' => $out, 'cmd' => $cmd];

  if ($code === 0 && $out && stripos($out, 'ERROR:') === false) {
    $lines = preg_split('~\r?\n~', $out);
    $audioUrl = trim($lines[0] ?? '');
    if (filter_var($audioUrl, FILTER_VALIDATE_URL)) {
      echo json_encode([
        'video_url' => $url,
        'audio_url' => $audioUrl,
        'expires_hint' => 'URL temporária; use em poucas horas.'
      ]);
      exit;
    }
  }

  // Se pegou 429/bot-check, não adianta insistir logo em seguida
  if (stripos($out, 'HTTP Error 429') !== false || stripos($out, 'Sign in to confirm you’re not a bot') !== false) {
    break;
  }

  // pequeno backoff entre tentativas
  usleep(500000); // 0.5s
}

// Se chegou aqui, erro
$hint = [];
if ($last['code'] === 'timeout') {
  $hint[] = 'Aumente YTDLP_PROXY (use um proxy) ou adicione cookies (YTDLP_COOKIES).';
}
if (stripos($last['out'], 'HTTP Error 429') !== false) {
  $hint[] = 'YouTube respondeu 429 (rate limit). Use cookies e/ou proxy.';
}
if (stripos($last['out'], 'Sign in to confirm') !== false) {
  $hint[] = 'Use cookies do seu navegador (YTDLP_COOKIES em base64).';
}

http_response_code($last['code'] === 'timeout' ? 504 : 502);
echo json_encode([
  'error' => $last['code'] === 'timeout' ? 'Timeout ao extrair áudio' : 'Não foi possível extrair o áudio',
  'exit_code' => $last['code'],
  'details' => $last['out'],
  'cmd' => $last['cmd'],
  'hints' => $hint,
]);
