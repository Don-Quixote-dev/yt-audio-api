<?php
header('Content-Type: application/json; charset=utf-8');

function run($cmd) {
  $out=[]; $code=0; exec($cmd.' 2>&1', $out, $code);
  return [$code, implode("\n",$out)];
}

[$c1,$o1] = run('/usr/local/bin/yt-dlp --version');
[$c2,$o2] = run('which yt-dlp');

echo json_encode([
  'php'     => PHP_VERSION,
  'yt_dlp'  => $c1===0 ? trim($o1) : 'não encontrado',
  'which'   => trim($o2),
  'exit'    => $c1
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
