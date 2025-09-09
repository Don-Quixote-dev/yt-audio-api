<?php
header('Content-Type: application/json; charset=utf-8');

// Versão do PHP
$phpVersion = PHP_VERSION;

// Testa se yt-dlp está disponível
$ytOutput = [];
$exitCode = 0;
exec('yt-dlp --version 2>&1', $ytOutput, $exitCode);

echo json_encode([
    'php' => $phpVersion,
    'yt_dlp' => $ytOutput[0] ?? 'não encontrado',
    'exit_code' => $exitCode,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);