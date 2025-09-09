<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

@set_time_limit(60); // 60s

// Entrada
$url = trim($_GET['url'] ?? '');
if ($url === '' || !preg_match('~^https?://(www\.)?(youtube\.com|youtu\.be)/~i', $url)) {
  http_response_code(400);
  echo json_encode(['error' => 'Informe uma URL válida do YouTube/youtu.be em ?url=']);
  exit;
}

// Configuração
$yt = '/usr/local/bin/yt-dlp';
$ua = getenv('YTDLP_UA') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$proxy = getenv('YTDLP_PROXY') ?: '';
$cookieB64 = getenv('YTDLP_COOKIES') ?: '';
$envPrefix = 'HOME=/tmp XDG_CACHE_HOME=/tmp';

// Cookies (se houver)
$cookiesFile = '';
if ($cookieB64) {
  $cookiesFile = '/tmp/cookies.txt';
  file_put_contents($cookiesFile, base64_decode($cookieB64));
  if (!filesize($cookiesFile)) $cookiesFile = '';
}

// Cache simples
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

// Tenta diferentes player clients
$clients = ['web', 'android', 'ios', 'tv', 'web_embedded'];
$maxAttempts = 3;
$timeout = 40;
$audioUrl = '';
$errors = [];

foreach ($clients as $client) {
  for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

    $parts = [
      $envPrefix,
      escapeshellcmd($yt),
      '-f', 'bestaudio',
      '--no-playlist',
      '--force-ipv4',
      '--no-cache',
      '--no-warnings',
      '--socket-timeout', '20',
      '--sleep-requests', '2',
      '--retries', '2',
      '--user-agent', escapeshellarg($ua),
      '--extractor-args', "youtube:player_client={$client}",
      '--get-url',
      escapeshellarg($url)
    ];

    if ($cookiesFile) { $parts[] = '--cookies'; $parts[] = escapeshellarg($cookiesFile); }
    if ($proxy)       { $parts[] = '--proxy';   $parts[] = escapeshellarg($proxy); }

    $cmd = implode(' ', $parts) . ' 2>&1';

    // Salva log de tentativa
    file_put_contents("/tmp/ytlog_{$vid}.log", "Tentativa com client={$client}, tentativa={$attempt}\nCMD: $cmd\n", FILE_APPEND);

    // Executa processo
    $desc = [1 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);

    if (!is_resource($proc)) {
      $errors[] = "Erro ao iniciar processo (client=$client)";
      continue;
    }

    stream_set_blocking($pipes[1], false);
    $start = microtime(true);
    $buf = '';

    while (true) {
      $buf .= stream_get_contents($pipes[1]);
      $st = proc_get_status($proc);

      if (!$st['running']) {
        fclose($pipes[1]);
        $code = proc_close($proc);
        break;
      }

      if ((microtime(true) - $start) > $timeout) {
        proc_terminate($proc);
        fclose($pipes[1]);
        proc_close($proc);
        $errors[] = "Timeout com client={$client}";
        break 2; // Não continue após timeout
      }

      usleep(150000);
    }

    $lines = preg_split('~\r?\n~', trim($buf));
    foreach ($lines as $line) {
      $line = trim($line);
      if (filter_var($line, FILTER_VALIDATE_URL)) {
        $audioUrl = $line;
        break 2; // achou, sai dos loops
      }
    }

    $errors[] = "Tentativa falhou com client={$client}, tentativa={$attempt}, saída: " . trim($buf);
  }
}

// Se não achou
if (!$audioUrl) {
  http_response_code(502);
  echo json_encode([
    'error' => 'Falha ao extrair o áudio após múltiplas tentativas',
    'errors' => $errors
  ]);
  exit;
}

// Sucesso
$response = [
  'video_url' => $url,
  'audio_url' => $audioUrl,
  'expires_hint' => 'URL temporária, use em até 1h.'
];

// Salva no cache
if ($cacheFile) @file_put_contents($cacheFile, json_encode($response));

// Retorna
echo json_encode($response);
