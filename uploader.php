
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

function build_https_url($remoteFullPath) {
    // Convert FTP path to HTTPS URL
    // Assumes files are accessible via HTTPS on the same host
    $path = $remoteFullPath[0] === '/' ? $remoteFullPath : "/$remoteFullPath";
    $url = sprintf('https://%s%s', FTP_HOST, $path);
    
    debug_info('HTTPS_URL', 'Built HTTPS URL', [
        'host' => FTP_HOST,
        'path' => $path,
        'full_url' => $url
    ]);
    
    return $url;
}

function ftp_ensure_dir($remoteDir) {
    debug_info('FTP_MKDIR', 'Ensuring directory exists', ['path' => $remoteDir]);
    
    // Try to create directory structure recursively
    $pathParts = array_filter(explode('/', trim($remoteDir, '/')));
    $currentPath = '';
    
    foreach ($pathParts as $part) {
        $currentPath .= '/' . $part;
        
        $ch = curl_init();
        curl_setopt_array($ch,[
            CURLOPT_URL => build_url('/'),
            CURLOPT_USERPWD => FTP_USER.':'.FTP_PASS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_QUOTE => ["MKD ".$currentPath],
        ]);
        curl_opts_for_mode($ch);
        
        $ok = curl_exec($ch);
        $err = $ok===false ? curl_error($ch) : null;
        curl_close($ch);
        
        debug_info('FTP_MKDIR_STEP', 'Directory creation step', [
            'path' => $currentPath,
            'success' => $ok !== false,
            'error' => $err
        ]);
    }
    
    debug_info('FTP_MKDIR_RESULT', 'Directory creation completed', [
        'final_path' => $remoteDir
    ]);
    
    return ['ok' => true, 'error' => null];
}

function ftp_put_file($localPath, $remoteFullPath, $retryCount = 0) {
    debug_info('UPLOAD_START', 'Starting file upload', [
        'local_path' => $localPath,
        'remote_path' => $remoteFullPath,
        'file_size' => file_exists($localPath) ? filesize($localPath) : 'N/A',
        'retry_attempt' => $retryCount
    ]);
    
    // Calculate local file hash for verification
    $localHash = hash_file('md5', $localPath);
    debug_info('UPLOAD_HASH', 'Calculated local file hash', [
        'file' => $localPath,
        'hash' => $localHash
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
    $fileSize = filesize($localPath);
    
    // Zwiększone timeouty dla większych plików
    $connectTimeout = 60;
    $totalTimeout = max(1800, $fileSize / 1024); // minimum 30 min lub 1KB/s
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_USERPWD => FTP_USER.':'.FTP_PASS,
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => $fileSize,
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $totalTimeout,
        CURLOPT_LOW_SPEED_LIMIT => 1024, // 1KB/s minimum speed
        CURLOPT_LOW_SPEED_TIME => 300,   // 5 minut przy niskiej prędkości
        CURLOPT_NOPROGRESS => false,
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR  => $vstream,
        // Dodatkowe opcje dla stabilności
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_TCP_KEEPIDLE => 600,
        CURLOPT_TCP_KEEPINTVL => 60,
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

    // Lepsze sprawdzanie czy upload się udał
    $uploadCompleted = strpos($verbose, 'We are completely uploaded and fine') !== false;
    $bytesUploaded = $info['size_upload'] ?? 0;
    $expectedSize = $fileSize;
    $uploadSuccess = $uploadCompleted && ($bytesUploaded >= $expectedSize * 0.99); // 99% tolerancja

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
        'upload_success' => $uploadSuccess,
        'retry_attempt' => $retryCount
    ], ($ok || $uploadSuccess) ? 'INFO' : 'ERROR');

    $resp = [];
    if(!$ok) {
        // Jeśli cURL failed ale upload się udał, traktuj jako sukces
        if ($uploadSuccess) {
            debug_info('UPLOAD_CURL_FAILED_BUT_SUCCESS', 'cURL failed but upload completed', [
                'curl_error' => $err,
                'bytes_uploaded' => $bytesUploaded,
                'expected_size' => $expectedSize
            ]);
            $resp = ['ok'=>true, 'warning'=>'Upload completed despite cURL error'];
        } else {
            $resp = ['ok'=>false,'error'=>"cURL error: $err"];
            debug_error('UPLOAD_FAILED', 'Upload failed', [
                'curl_error' => $err,
                'verbose' => $verbose
            ]);
        }
    } elseif($code >= 400) {
        // Obsługa błędów 4xx/5xx
        if ($code == 451) {
            if ($uploadSuccess) {
                debug_info('UPLOAD_451_BUT_SUCCESS', 'Upload completed despite 451 error', [
                    'bytes_uploaded' => $bytesUploaded,
                    'expected_size' => $expectedSize
                ]);
                $resp = ['ok'=>true, 'warning'=>'Upload completed despite server error 451'];
            } else {
                // Retry dla błędu 451 z eksponencjalnym backoff
                if ($retryCount < 3) {
                    $waitTime = pow(2, $retryCount); // 1s, 2s, 4s
                    debug_info('UPLOAD_RETRY_451', 'Retrying upload after 451 error', [
                        'retry_count' => $retryCount + 1,
                        'wait_time' => $waitTime
                    ]);
                    sleep($waitTime);
                    return ftp_put_file($localPath, $remoteFullPath, $retryCount + 1);
                }
                $resp = ['ok'=>false,'error'=>"FTP response code: 451 (Transfer aborted by server) - wszystkie próby wyczerpane"];
            }
        } else {
            $resp = ['ok'=>false,'error'=>"FTP response code: $code"];
        }
        
        if (!$resp['ok']) {
            debug_error('UPLOAD_FAILED', 'Bad FTP response', [
                'response_code' => $code,
                'verbose' => $verbose,
                'upload_completed' => $uploadCompleted,
                'upload_success' => $uploadSuccess,
                'bytes_uploaded' => $bytesUploaded,
                'expected_size' => $expectedSize,
                'retry_count' => $retryCount,
                'hint' => $code == 451 ? 'Server aborted transfer - możliwy timeout serwera' : null
            ]);
        }
    } else {
        $resp = ['ok'=>true];
        debug_info('UPLOAD_SUCCESS', 'Upload completed successfully', [
            'duration' => $duration,
            'bytes_uploaded' => $bytesUploaded
        ]);
    }

    // Verify file integrity if upload was successful
    $integrityCheck = ['verified' => false, 'local_hash' => $localHash, 'remote_hash' => null];
    if ($resp['ok'] && $uploadSuccess) {
        debug_info('INTEGRITY_CHECK', 'Starting file verification', ['remote_path' => $remoteFullPath]);
        
        $downloadResult = ftp_get_string($remoteFullPath);
        if ($downloadResult['ok']) {
            $remoteHash = hash('md5', $downloadResult['data']);
            $integrityCheck['remote_hash'] = $remoteHash;
            $integrityCheck['verified'] = ($localHash === $remoteHash);
            
            debug_info('INTEGRITY_RESULT', 'File verification completed', [
                'local_hash' => $localHash,
                'remote_hash' => $remoteHash,
                'verified' => $integrityCheck['verified']
            ]);
            
            if (!$integrityCheck['verified']) {
                debug_error('INTEGRITY_FAILED', 'File integrity check failed', [
                    'local_hash' => $localHash,
                    'remote_hash' => $remoteHash,
                    'local_size' => $expectedSize,
                    'remote_size' => strlen($downloadResult['data'])
                ]);
                
                // If integrity failed and we haven't exhausted retries, try again
                if ($retryCount < 3) {
                    debug_info('INTEGRITY_RETRY', 'Retrying upload due to integrity failure', [
                        'retry_count' => $retryCount + 1
                    ]);
                    return ftp_put_file($localPath, $remoteFullPath, $retryCount + 1);
                } else {
                    $resp = ['ok' => false, 'error' => 'Integralność pliku nie została zachowana po 3 próbach'];
                }
            }
        } else {
            debug_error('INTEGRITY_DOWNLOAD_FAILED', 'Cannot download file for verification', [
                'error' => $downloadResult['error']
            ]);
            $integrityCheck['download_error'] = $downloadResult['error'];
        }
    }

    // Include debug info in response if requested
    if (isset($GLOBALS['_REQ_DEBUG']) && $GLOBALS['_REQ_DEBUG']) {
        $resp['debug'] = [
            'duration' => $duration,
            'curl_info' => $info,
            'verbose' => mb_substr($verbose, -DEBUG_VERBOSE_LIMIT),
            'upload_completed' => $uploadCompleted,
            'upload_success' => $uploadSuccess,
            'bytes_uploaded' => $bytesUploaded,
            'expected_size' => $expectedSize,
            'retry_attempt' => $retryCount,
            'integrity_check' => $integrityCheck,
            'timeouts' => [
                'connect' => $connectTimeout,
                'total' => $totalTimeout
            ],
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
            // Add file URLs in debug mode
            if ($_REQ_DEBUG) {
                $ftpUrl = build_url($dst);
                $httpsUrl = build_https_url($dst);
                $response['debug']['file_url_ftp'] = $ftpUrl;
                $response['debug']['file_url_https'] = $httpsUrl;
                $response['debug']['file_path'] = $dst;
            }
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

/** 4) Manual retry upload */
if ($action==='retry' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$token) {
        debug_error('RETRY', 'No token provided');
        json_response(['ok'=>false,'error'=>'Brak etykiety w URL'],400);
    }
    
    $path = tok_path($token);
    if (!is_file($path)) {
        debug_error('RETRY', 'Token file not found', ['token' => $token]);
        json_response(['ok'=>false,'error'=>'Taki link nie istnieje'],404);
    }
    
    $meta = json_decode(file_get_contents($path), true);
    if (!$meta) {
        debug_error('RETRY', 'Invalid metadata', ['token' => $token]);
        json_response(['ok'=>false,'error'=>'Błąd metadanych'],500);
    }

    if (!isset($_FILES['file'])) {
        debug_error('RETRY', 'No file in request');
        json_response(['ok'=>false,'error'=>'Brak pliku'],400);
    }

    $name = $_FILES['file']['name'];
    $tmp  = $_FILES['file']['tmp_name'];
    $err  = $_FILES['file']['error'];
    $size = $_FILES['file']['size'];

    debug_info('RETRY', 'Manual retry upload', [
        'name' => $name,
        'size' => $size,
        'token' => $token
    ]);

    if ($err !== UPLOAD_ERR_OK) {
        debug_error('RETRY', 'PHP upload error', ['error_code' => $err]);
        json_response(['ok'=>false,'msg'=>"Błąd uploadu (kod $err)"],200);
    }
    
    if ($size > MAX_BYTES) {
        debug_error('RETRY', 'File too large', ['size' => $size, 'limit' => MAX_BYTES]);
        json_response(['ok'=>false,'msg'=>"Przekroczono limit rozmiaru"],200);
    }

    $rel = $_POST['relpath'] ?? $name;
    $rel = sanitize_rel($rel);
    
    if (!ext_ok($rel, ALLOW_EXT)) {
        debug_error('RETRY', 'Invalid extension', ['file' => $rel, 'allowed' => ALLOW_EXT]);
        json_response(['ok'=>false,'msg'=>"Niedozwolone rozszerzenie"],200);
    }

    $dst = rtrim($meta['remote_dir'],'/').'/'.$rel;
    
    // Force retry (reset retry count)
    $up = ftp_put_file($tmp, $dst, 0);

    if ($up['ok']) {
        // Update or add file to metadata
        $fileUpdated = false;
        foreach ($meta['files'] as &$file) {
            if ($file['rel'] === $rel) {
                $file['size'] = $size;
                $file['ts'] = now();
                $file['retried'] = true;
                $fileUpdated = true;
                break;
            }
        }
        
        if (!$fileUpdated) {
            $meta['files'][] = ['name'=>$name,'rel'=>$rel,'remote'=>$dst,'size'=>$size,'ts'=>now(),'retried'=>true];
        }
        
        file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        
        debug_info('RETRY_SUCCESS', 'Manual retry successful', [
            'file' => $name,
            'destination' => $dst,
            'size' => $size
        ]);
        
        $response = ['ok'=>true,'msg'=>'Plik ponownie przesłany pomyślnie'];
        if (isset($up['debug'])) {
            $response['debug'] = $up['debug'];
            if ($_REQ_DEBUG) {
                $ftpUrl = build_url($dst);
                $httpsUrl = build_https_url($dst);
                $response['debug']['file_url_ftp'] = $ftpUrl;
                $response['debug']['file_url_https'] = $httpsUrl;
                $response['debug']['file_path'] = $dst;
            }
        }
        json_response($response);
    } else {
        debug_error('RETRY_FAILED', 'Manual retry failed', ['error' => $up['error']]);
        $response = ['ok'=>false,'msg'=>'Błąd ponownego przesłania: '.$up['error']];
        if (isset($up['debug'])) {
            $response['debug'] = $up['debug'];
        }
        json_response($response, 200);
    }
}

/** 5) Autotest */
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
