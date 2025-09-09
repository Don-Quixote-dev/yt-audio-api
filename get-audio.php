<?php
// get-audio.php
// Retorna a URL temporária do áudio de um vídeo do YouTube usando yt-dlp

// CORS e JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Tempo máximo (segundos)
@set_time_limit(25);

$videoUrl = $_GET['url'] ?? '';
$videoUrl = trim($videoUrl);

// Validação básica
if ($videoUrl === '') {
  http_response_code(400);
  echo json_encode(['error' => "Parâmetro 'url' é obrigatório"]);
  exit;
}
if (!preg_match('~^https?://(www\.)?(youtube\.com|youtu\.be)/~i', $videoUrl)) {
  http_response_code(400);
  echo json_encode(['error' => 'URL inválida. Use um link do YouTube ou youtu.be']);
  exit;
}

// Monta comando com escapes
$cmd = 'yt-dlp -f bestaudio --no-playlist --get-url ' . escapeshellarg($videoUrl);

// Executa capturando stderr (2>&1) para log
$out = [];
$code = 0;
exec($cmd . ' 2>&1', $out, $code);

if ($code !== 0 || empty($out)) {
  http_response_code(500);
  echo json_encode([
    'error' => 'Não foi possível extrair o áudio',
    'details' => implode("\n", $out)
  ]);
  exit;
}

// yt-dlp pode retornar múltiplas linhas em alguns casos; pega a primeira válida
$audioUrl = trim($out[0]);

// Sanity check
if (!preg_match('~^https?://.*googlevideo\.com/.*~i', $audioUrl)) {
  http_response_code(502);
  echo json_encode([
    'error' => 'Resposta inesperada do extrator',
    'audio_url_raw' => $audioUrl
  ]);
  exit;
}

echo json_encode([
  'video_url' => $videoUrl,
  'audio_url' => $audioUrl,
  'expires_hint' => 'URL temporária; use imediatamente (expira em poucas horas).'
]);
