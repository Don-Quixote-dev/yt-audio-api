<?php
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(45);

function run($cmd, $timeout=10) {
  $desc=[1=>['pipe','w'],2=>['pipe','w']];
  $p=proc_open($cmd.' 2>&1', $desc, $pipes);
  if(!is_resource($p)) return ['code'=>null,'out'=>'proc_open failed','cmd'=>$cmd];
  stream_set_blocking($pipes[1],false);
  $start=microtime(true); $buf='';
  while(true){
    $buf .= stream_get_contents($pipes[1]);
    $st = proc_get_status($p);
    if(!$st['running']){ fclose($pipes[1]); $code=proc_close($p); return ['code'=>$code,'out'=>trim($buf),'cmd'=>$cmd]; }
    if((microtime(true)-$start)>$timeout){ proc_terminate($p); fclose($pipes[1]); proc_close($p); return ['code'=>'timeout','out'=>trim($buf),'cmd'=>$cmd]; }
    usleep(120000);
  }
}

$ts = fn()=>date('H:i:s');

$checks = [];
$checks[] = ['t'=>$ts(),'step'=>'php_version','val'=>PHP_VERSION];

// DNS youtube
$ip = gethostbyname('www.youtube.com');
$checks[] = ['t'=>$ts(),'step'=>'dns_youtube','val'=>$ip];

// HEAD em youtube.com (5s)
[$codeCurl,$outCurl] = (function(){
  $desc=[1=>['pipe','w'],2=>['pipe','w']];
  $p=proc_open('curl -sS -I -m 5 https://www.youtube.com', $desc, $pipes);
  if(!is_resource($p)) return [null,'curl start fail'];
  $buf = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $code = proc_close($p);
  return [$code, trim($buf)];
})();
$checks[] = ['t'=>$ts(),'step'=>'curl_head_youtube','code'=>$codeCurl,'out'=>$outCurl];

// yt-dlp --version (5s)
$checks[] = array_merge(['t'=>$ts(),'step'=>'yt_dlp_version'], run('/usr/local/bin/yt-dlp --version', 5));

// yt-dlp get-url com vídeo simples (20s)
$video = $_GET['v'] ?? 'dQw4w9WgXcQ';
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$cmd = "HOME=/tmp XDG_CACHE_HOME=/tmp /usr/local/bin/yt-dlp -f bestaudio --no-playlist --force-ipv4 --no-cache --no-warnings --socket-timeout 10 --sleep-requests 1 --retries 1 --user-agent ".escapeshellarg($ua)." --extractor-args ".escapeshellarg('youtube:player_client=android')." --get-url https://www.youtube.com/watch?v={$video}";
$checks[] = array_merge(['t'=>$ts(),'step'=>'yt_dlp_get_url','video'=>$video], run($cmd, 20));

echo json_encode(['diag'=>$checks], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
