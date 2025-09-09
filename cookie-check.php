<?php
header('Content-Type: application/json; charset=utf-8');

$cookieB64 = getenv('YTDLP_COOKIES') ?: '';
$lenB64 = strlen($cookieB64);

$bytes = 0;
$head = $tail = '';
if ($cookieB64) {
  $raw = base64_decode($cookieB64, true);
  if ($raw !== false) {
    $bytes = strlen($raw);
    $lines = preg_split('~\r?\n~', $raw);
    $head = $lines[0] ?? '';
    $tail = $lines[count($lines)-1] ?? '';
  }
}

echo json_encode([
  'has_env' => $cookieB64 !== '',
  'env_length' => $lenB64,
  'decoded_bytes' => $bytes,
  'first_line' => $head,     // deve começar com "# Netscape HTTP Cookie File" ou domínio
  'last_line' => $tail
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
