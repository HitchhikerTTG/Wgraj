
<?php
/**
 * File Uploader with Enhanced Debugging
 */

require_once 'config.php';

/***** UTILITY FUNCTIONS *****/
function now() { return time(); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

function slugify($s){
    $s = trim($s);
    if ($s==='') return '';
    $s = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    $s = preg_replace('~[^A-Za-z0-9]+~','-',$s);
    $s = trim($s,'-');
    $s = strtolower($s);
    return substr($s,0,64);
}

function sanitize_rel($rel){
    $rel = str_replace('\\','/',$rel);
    $rel = preg_replace('~/+~','/',$rel);
    $parts=[];
    foreach(explode('/',$rel) as $p){
        if($p===''||$p==='.'||$p==='..') continue;
        $parts[] = preg_replace('/[^\w\.\-]+/u','_', $p);
    }
    return implode('/',$parts);
}

function ext_ok($name, $allow){
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $ext==='' || in_array($ext, $allow, true);
}

function tok_path($token){ 
    return DATA_DIR.'/tok_'.$token.'.json'; 
}

/***** ENHANCED DEBUG LOGGING *****/
function debug_log($tag, $data, $level = 'INFO'){
    if (!DEBUG_UPLOAD) return;
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = [
        'timestamp' => $timestamp,
        'level' => $level,
        'tag' => $tag,
        'data' => $data,
        'memory_usage' => memory_get_usage(true),
        'request_id' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)
    ];
    
    $line = json_encode($logEntry, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
    @file_put_contents(DEBUG_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function debug_error($tag, $message, $context = []){
    debug_log($tag, array_merge(['error' => $message], $context), 'ERROR');
}

function debug_info($tag, $message, $context = []){
    debug_log($tag, array_merge(['info' => $message], $context), 'INFO');
}

/***** FTP FUNCTIONS WITH ENHANCED DEBUGGING *****/
function curl_opts_for_mode(&$ch) {
    debug_info('FTP_CONFIG', 'Setting cURL options', [
        'mode' => FTP_MODE,
        'host' => FTP_HOST,
        'port' => FTP_PORT
    ]);
    
    if (FTP_MODE === 'explicit') {
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
        curl_setopt($ch, CURLOPT_FTPSSLAUTH, CURLFTPAUTH_TLS);
    } elseif (FTP_MODE === 'implicit') {
        // ftps:// scheme handles this
    }
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}

function build_url($remoteFullPath) {
    $scheme = (FTP_MODE === 'implicit') ? 'ftps' : 'ftp';
    $path   = $remoteFullPath[0] === '/' ? $remoteFullPath : "/$remoteFullPath";
    $url = sprintf('%s://%s:%d%s', $scheme, FTP_HOST, FTP_PORT, $path);
    
    debug_info('FTP_URL', 'Built FTP URL', [
        'scheme' => $scheme,
        'host' => FTP_HOST,
        'port' => FTP_PORT,
        'path' => $path,
        'full_url' => $url
    ]);
    
    return $url;
}

function ftp_ensure_dir($remoteDir) {
    debug_info('FTP_MKDIR', 'Ensuring directory exists', ['path' => $remoteDir]);
    
    $ch = curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL => build_url('/'),
        CURLOPT_USERPWD => FTP_USER.':'.FTP_PASS,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_QUOTE => ["MKD ".$remoteDir],
    ]);
    curl_opts_for_mode($ch);
    
    $ok = curl_exec($ch);
    $err = $ok===false ? curl_error($ch) : null;
    curl_close($ch);
    
    debug_info('FTP_MKDIR_RESULT', 'Directory creation result', [
        'success' => $ok !== false,
        'error' => $err
    ]);
    
    return ['ok' => $ok !== false, 'error' => $err];
}

function ftp_put_file($localPath, $remoteFullPath, $retryCount = 0) {
    debug_info('UPLOAD_START', 'Starting file upload', [
        'local_path' => $localPath,
        'remote_path' => $remoteFullPath,
        'file_size' => file_exists($localPath) ? filesize($localPath) : 'N/A',
        'retry_attempt' => $retryCount
    ]);
    
    // Ensure directory exists
    $remoteDir = dirname($remoteFullPath);
    if ($remoteDir !== '.' && $remoteDir !== '/') {
        ftp_ensure_dir($remoteDir);
    }
    
    $fp = fopen($localPath,'rb');
    if(!$fp) {
        debug_error('UPLOAD_ERROR', 'Cannot open local file', ['path' => $localPath]);
        return ['ok'=>false,'error'=>'Cannot open local file'];
    }

    $vstream = fopen('php://temp', 'w+');
    $ch = curl_init();
    
    $url = build_url($remoteFullPath);
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_USERPWD => FTP_USER.':'.FTP_PASS,
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => filesize($localPath),
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        CURLOPT_CONNECTTIMEOUT => 60,
        CURLOPT_TIMEOUT => 1200,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR  => $vstream,
    ]);
    
    curl_opts_for_mode($ch);

    $start_time = microtime(true);
    $ok = curl_exec($ch);
    $duration = microtime(true) - $start_time;
    
    $err = $ok ? null : curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $info = curl_getinfo($ch);

    rewind($vstream);
    $verbose = stream_get_contents($vstream);
    fclose($vstream);

    curl_close($ch);
    fclose($fp);

    // Check if upload was actually successful despite 451 error
    $uploadCompleted = strpos($verbose, 'We are completely uploaded and fine') !== false;
    $bytesUploaded = $info['size_upload'] ?? 0;
    $expectedSize = filesize($localPath);

    // Enhanced logging
    debug_log('UPLOAD_RESULT', [
        'success' => (bool)$ok,
        'duration' => round($duration, 2),
        'target' => $remoteFullPath,
        'curl_error' => $err,
        'http_code' => $code,
        'curl_info' => $info,
        'verbose_output' => $verbose,
        'file_size' => $bytesUploaded,
        'expected_size' => $expectedSize,
        'upload_completed' => $uploadCompleted,
        'retry_attempt' => $retryCount
    ], $ok ? 'INFO' : 'ERROR');

    $resp = [];
    if(!$ok) {
        $resp = ['ok'=>false,'error'=>"cURL error: $err"];
        debug_error('UPLOAD_FAILED', 'Upload failed', [
            'curl_error' => $err,
            'verbose' => $verbose
        ]);
    } elseif($code >= 400) {
        // Special handling for 451 error when upload actually completed
        if ($code == 451 && $uploadCompleted && $bytesUploaded == $expectedSize) {
            debug_info('UPLOAD_451_BUT_SUCCESS', 'Upload completed despite 451 error', [
                'bytes_uploaded' => $bytesUploaded,
                'expected_size' => $expectedSize
            ]);
            $resp = ['ok'=>true, 'warning'=>'Upload completed despite server error 451'];
        } else {
            $errorMsg = "FTP response code: $code";
            if ($code == 451) {
                $errorMsg .= " (Transfer aborted by server)";
                // Retry once for 451 errors
                if ($retryCount < 1) {
                    debug_info('UPLOAD_RETRY', 'Retrying upload after 451 error');
                    sleep(1); // Brief pause before retry
                    return ftp_put_file($localPath, $remoteFullPath, $retryCount + 1);
                }
            }
            $resp = ['ok'=>false,'error'=>$errorMsg];
            debug_error('UPLOAD_FAILED', 'Bad FTP response', [
                'response_code' => $code,
                'verbose' => $verbose,
                'upload_completed' => $uploadCompleted,
                'bytes_uploaded' => $bytesUploaded,
                'expected_size' => $expectedSize,
                'hint' => $code == 451 ? 'Server aborted transfer - may be server-side timeout' : null
            ]);
        }
    } else {
        $resp = ['ok'=>true];
        debug_info('UPLOAD_SUCCESS', 'Upload completed successfully', [
            'duration' => $duration,
            'bytes_uploaded' => $bytesUploaded
        ]);
    }

    // Include debug info in response if requested
    if (isset($GLOBALS['_REQ_DEBUG']) && $GLOBALS['_REQ_DEBUG']) {
        $resp['debug'] = [
            'duration' => $duration,
            'curl_info' => $info,
            'verbose' => mb_substr($verbose, -DEBUG_VERBOSE_LIMIT),
            'upload_completed' => $uploadCompleted,
            'bytes_uploaded' => $bytesUploaded,
            'expected_size' => $expectedSize,
            'retry_attempt' => $retryCount,
            'config' => [
                'ftp_mode' => FTP_MODE,
                'ftp_host' => FTP_HOST,
                'ftp_port' => FTP_PORT,
                'url' => $url
            ]
        ];
    }

    return $resp;
}

function ftp_get_string($remoteFullPath) {
    debug_info('FTP_GET', 'Getting file content', ['path' => $remoteFullPath]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => build_url($remoteFullPath),
        CURLOPT_USERPWD => FTP_USER.':'.FTP_PASS,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 60,
    ]);
    curl_opts_for_mode($ch);
    
    $out = curl_exec($ch);
    $err = $out===false ? curl_error($ch) : null;
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    
    if ($out===false) {
        debug_error('FTP_GET_ERROR', 'GET failed', ['error' => $err]);
        return ['ok'=>false,'error'=>"GET error: $err"];
    }
    if ($code && $code>=400) {
        debug_error('FTP_GET_ERROR', 'GET bad response', ['code' => $code]);
        return ['ok'=>false,'error'=>"GET code: $code"];
    }
    
    debug_info('FTP_GET_SUCCESS', 'GET completed', ['content_length' => strlen($out)]);
    return ['ok'=>true,'data'=>$out];
}

function ftp_delete_file($remoteFullPath){
    debug_info('FTP_DELETE', 'Deleting file', ['path' => $remoteFullPath]);
    
    $ch = curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL => build_url('/'),
        CURLOPT_USERPWD => FTP_USER.':'.FTP_PASS,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_QUOTE => ["DELE ".$remoteFullPath],
    ]);
    curl_opts_for_mode($ch);
    
    $ok = curl_exec($ch);
    $err = $ok===false ? curl_error($ch) : null;
    curl_close($ch);
    
    if ($ok === false) {
        debug_error('FTP_DELETE_ERROR', 'Delete failed', ['error' => $err]);
        return ['ok'=>false,'error'=>"DELE error: $err"];
    }
    
    debug_info('FTP_DELETE_SUCCESS', 'File deleted');
    return ['ok'=>true];
}

function ftp_remove_dir($remoteDir){
    debug_info('FTP_RMDIR', 'Removing directory', ['path' => $remoteDir]);
    
    $ch = curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL => build_url('/'),
        CURLOPT_USERPWD => FTP_USER.':'.FTP_PASS,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_QUOTE => ["RMD ".$remoteDir],
    ]);
    curl_opts_for_mode($ch);
    
    $ok = curl_exec($ch);
    $err = $ok===false ? curl_error($ch) : null;
    curl_close($ch);
    
    if ($ok === false) {
        debug_error('FTP_RMDIR_ERROR', 'Remove directory failed', ['error' => $err]);
        return ['ok'=>false,'error'=>"RMD error: $err"];
    }
    
    debug_info('FTP_RMDIR_SUCCESS', 'Directory removed');
    return ['ok'=>true];
}

function ftp_upload_string($content, $remoteFullPath) {
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $content);
    $res = ftp_put_file($tmp, $remoteFullPath);
    @unlink($tmp);
    return $res;
}

/***** RESPONSE HELPERS *****/
function send_mail($to,$from,$subject,$body){
    debug_info('EMAIL', 'Sending notification', ['to' => $to, 'subject' => $subject]);
    $headers = "From: $from\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,$headers);
}

function json_response($arr,$code=200){
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

/***** REQUEST PROCESSING *****/
$action = $_GET['action'] ?? null;
$token  = $_GET['t'] ?? null;

if (!$token) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri,'?')!==false) $uri = strstr($uri,'?', true);
    $uri = trim($uri,'/');
    if ($uri !== '' && strpos($uri,'index.php')===false) {
        $token = basename($uri);
    }
}

// Debug mode detection
$_REQ_DEBUG = false;
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $_REQ_DEBUG = true;
}
if (isset($_GET['debug_key']) && $_GET['debug_key'] === ADMIN_KEY) {
    $_REQ_DEBUG = true;
}
$GLOBALS['_REQ_DEBUG'] = $_REQ_DEBUG;

debug_info('REQUEST', 'Processing request', [
    'action' => $action,
    'token' => $token,
    'method' => $_SERVER['REQUEST_METHOD'],
    'debug_mode' => $_REQ_DEBUG,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
]);

/** 1) ADMIN: Create new upload link */
if ($action==='new' && $_SERVER['REQUEST_METHOD']==='POST') {
    $key   = $_POST['key'] ?? '';
    $label = $_POST['label'] ?? '';
    
    debug_info('ADMIN_NEW', 'Creating new link', ['label' => $label]);
    
    if ($key !== ADMIN_KEY) {
        debug_error('ADMIN_NEW', 'Unauthorized access attempt');
        json_response(['error'=>'unauthorized'],401);
    }

    $slug = slugify($label);
    if ($slug==='') {
        debug_error('ADMIN_NEW', 'Empty slug');
        json_response(['error'=>'Podaj etykietę'],400);
    }

    if (file_exists(tok_path($slug))) {
        debug_error('ADMIN_NEW', 'Slug already exists', ['slug' => $slug]);
        json_response(['error'=>'Taka etykieta już istnieje. Wybierz inną.'],409);
    }

    $folderName = $slug . '-' . date('Y-m-d_H-i');
    $remoteDir  = rtrim(FTP_ROOTDIR,'/').'/'.$folderName;

    $meta = [
        'token'      => $slug,
        'label'      => $label,
        'created'    => now(),
        'expires'    => now() + 3600*TOKEN_TTL_H,
        'used'       => false,
        'remote_dir' => $remoteDir,
        'files'      => []
    ];
    
    file_put_contents(tok_path($slug), json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

    $pretty = rtrim(BASE_URL,'/').'/'.$slug;
    
    debug_info('ADMIN_NEW_SUCCESS', 'Link created', [
        'url' => $pretty,
        'remote_dir' => $remoteDir,
        'expires' => date('Y-m-d H:i:s', $meta['expires'])
    ]);
    
    json_response(['ok'=>true,'url'=>$pretty,'remote_dir'=>$remoteDir]);
}

/** 2) Upload file */
if ($action==='upload' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$token) {
        debug_error('UPLOAD', 'No token provided');
        json_response(['ok'=>false,'error'=>'Brak etykiety w URL'],400);
    }
    
    $path = tok_path($token);
    if (!is_file($path)) {
        debug_error('UPLOAD', 'Token file not found', ['token' => $token]);
        json_response(['ok'=>false,'error'=>'Taki link nie istnieje'],404);
    }
    
    $meta = json_decode(file_get_contents($path), true);
    if (!$meta) {
        debug_error('UPLOAD', 'Invalid metadata', ['token' => $token]);
        json_response(['ok'=>false,'error'=>'Błąd metadanych'],500);
    }
    
    if (!empty($meta['used'])) {
        debug_error('UPLOAD', 'Link already used', ['token' => $token]);
        json_response(['ok'=>false,'error'=>'Link został już użyty'],410);
    }
    
    if (now() > ($meta['expires']??0)) {
        debug_error('UPLOAD', 'Link expired', ['token' => $token, 'expired_at' => date('Y-m-d H:i:s', $meta['expires'])]);
        json_response(['ok'=>false,'error'=>'Link wygasł'],410);
    }

    if (!isset($_FILES['file'])) {
        debug_error('UPLOAD', 'No file in request');
        json_response(['ok'=>false,'error'=>'Brak pliku'],400);
    }

    $name = $_FILES['file']['name'];
    $tmp  = $_FILES['file']['tmp_name'];
    $err  = $_FILES['file']['error'];
    $size = $_FILES['file']['size'];

    debug_info('UPLOAD', 'Processing file', [
        'name' => $name,
        'size' => $size,
        'error_code' => $err,
        'tmp_name' => $tmp
    ]);

    if ($err !== UPLOAD_ERR_OK) {
        debug_error('UPLOAD', 'PHP upload error', ['error_code' => $err]);
        json_response(['ok'=>false,'msg'=>"Błąd uploadu (kod $err)"],200);
    }
    
    if ($size > MAX_BYTES) {
        debug_error('UPLOAD', 'File too large', ['size' => $size, 'limit' => MAX_BYTES]);
        json_response(['ok'=>false,'msg'=>"Przekroczono limit rozmiaru"],200);
    }

    $rel = $_POST['relpath'] ?? $name;
    $rel = sanitize_rel($rel);
    
    if (!ext_ok($rel, ALLOW_EXT)) {
        debug_error('UPLOAD', 'Invalid extension', ['file' => $rel, 'allowed' => ALLOW_EXT]);
        json_response(['ok'=>false,'msg'=>"Niedozwolone rozszerzenie"],200);
    }

    $dst = rtrim($meta['remote_dir'],'/').'/'.$rel;
    $up  = ftp_put_file($tmp,$dst);

    if ($up['ok']) {
        $meta['files'][] = ['name'=>$name,'rel'=>$rel,'remote'=>$dst,'size'=>$size,'ts'=>now()];
        file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        
        debug_info('UPLOAD_SUCCESS', 'File uploaded successfully', [
            'file' => $name,
            'destination' => $dst,
            'size' => $size
        ]);
        
        $response = ['ok'=>true,'msg'=>'OK'];
        if (isset($up['debug'])) {
            $response['debug'] = $up['debug'];
        }
        json_response($response);
    } else {
        debug_error('UPLOAD_FAILED', 'Upload failed', ['error' => $up['error']]);
        $response = ['ok'=>false,'msg'=>'Błąd: '.$up['error']];
        if (isset($up['debug'])) {
            $response['debug'] = $up['debug'];
        }
        json_response($response, 200);
    }
}

/** 3) Finalize upload */
if ($action==='finalize' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$token) json_response(['ok'=>false,'error'=>'Brak etykiety'],400);
    
    $path = tok_path($token);
    if (!is_file($path)) json_response(['ok'=>false,'error'=>'Link nie istnieje'],404);
    
    $meta = json_decode(file_get_contents($path), true);
    if (!$meta) json_response(['ok'=>false,'error'=>'Błąd metadanych'],500);

    debug_info('FINALIZE', 'Finalizing upload', [
        'token' => $token,
        'file_count' => count($meta['files'] ?? [])
    ]);

    $meta['used'] = true;
    $meta['used_at'] = now();
    file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

    $files = $meta['files'] ?? [];
    $body  = "Zakończono wysyłkę.\n".
             "Etykieta: ".($meta['label'] ?? $meta['token'])."\n".
             "Zdalny katalog: ".$meta['remote_dir']."\n".
             "Plików: ".count($files)."\n\n";
    
    foreach($files as $f){
        $body .= ' - '.$f['rel'].' ('.number_format($f['size']/1024/1024,2).' MB)'."\n";
    }
    
    send_mail(EMAIL_TO, EMAIL_FROM, 'Uploader: zakończono wysyłkę', $body);

    debug_info('FINALIZE_SUCCESS', 'Upload finalized', ['file_count' => count($files)]);
    json_response(['ok'=>true,'count'=>count($files)]);
}

/** 4) Autotest */
if ($action==='autotest' && $_SERVER['REQUEST_METHOD']==='POST') {
    $key = $_POST['key'] ?? '';
    $lab = $_POST['label'] ?? '';
    
    if ($key !== ADMIN_KEY) json_response(['ok'=>false,'error'=>'unauthorized'],401);
    
    $slug = slugify($lab);
    if ($slug==='') json_response(['ok'=>false,'error'=>'Podaj etykietę'],400);

    $path = tok_path($slug);
    if (!is_file($path)) json_response(['ok'=>false,'error'=>'Taki link nie istnieje'],404);
    
    $meta = json_decode(file_get_contents($path), true);
    $base = rtrim($meta['remote_dir'],'/');

    debug_info('AUTOTEST', 'Starting autotest', ['slug' => $slug, 'base_dir' => $base]);

    $testDir  = $base.'/'."_permtest_".date('Ymd_His');
    $testFile = $testDir.'/ping.txt';
    $probeTxt = "uploader perm test @ ".date('c');

    $up   = ftp_upload_string($probeTxt, $testFile);
    $get  = $up['ok'] ? ftp_get_string($testFile) : ['ok'=>false,'error'=>'Pominięto GET – upload się nie powiódł'];
    $delF = $up['ok'] ? ftp_delete_file($testFile) : ['ok'=>false,'error'=>'Pominięto DELE – upload się nie powiódł'];
    $delD = $delF['ok'] ? ftp_remove_dir($testDir) : ['ok'=>false,'error'=>'Pominięto RMD – brak pliku lub błąd'];

    $verified = ($get['ok'] ?? false) && (isset($get['data']) && $get['data'] === $probeTxt);

    $result = [
        'ok' => $up['ok'] && ($get['ok'] ?? true),
        'remote_dir' => $meta['remote_dir'],
        'config' => [
            'ftp_mode' => FTP_MODE,
            'ftp_host' => FTP_HOST,
            'ftp_port' => FTP_PORT,
            'ftp_rootdir' => FTP_ROOTDIR
        ],
        'steps' => [
            'upload' => $up,
            'download' => $get,
            'content_match' => $verified,
            'delete_file' => $delF,
            'remove_dir' => $delD,
        ],
        'hint' => $up['ok'] ? null : 'Sprawdź konfigurację FTP: tryb, port, ścieżkę i uprawnienia.'
    ];

    debug_log('AUTOTEST_RESULT', $result);
    json_response($result);
}

// Include the UI
require_once 'ui.php';
?>
