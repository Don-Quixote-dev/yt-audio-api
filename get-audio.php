<?php
header('Content-Type: application/json; charset=utf-8');

// URL do vídeo recebida via GET
$videoUrl = $_GET['url'] ?? null;

if (!$videoUrl) {
    echo json_encode(["error" => "Parâmetro 'url' é obrigatório"]);
    exit;
}

// Extrai URL de áudio temporária
$cmd = "yt-dlp -f bestaudio --get-url " . escapeshellarg($videoUrl);
$output = shell_exec($cmd);

if (!$output) {
    echo json_encode(["error" => "Não foi possível extrair o áudio"]);
    exit;
}

// Limpa saída
$audioUrl = trim($output);

echo json_encode([
    "video_url" => $videoUrl,
    "audio_url" => $audioUrl
]);
