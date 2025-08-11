
<?php
/**
 * HTTP Upload Receiver
 * Handles chunked file uploads via HTTP POST
 */

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS headers if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load configuration
require_once 'config.php';

// Authentication
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$provided_token = '';

if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    $provided_token = $matches[1];
}

$expected_token = $_ENV['HTTP_UPLOAD_TOKEN'] ?? 'secure-upload-token-2024';

if ($provided_token !== $expected_token) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Parse JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Ensure upload directory exists
$upload_dir = LOCAL_STORAGE_PATH;
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0775, true);
}

// Handle finalize action
if (isset($data['action']) && $data['action'] === 'finalize') {
    $upload_id = $data['upload_id'] ?? '';
    $temp_dir = $upload_dir . '/temp/' . $upload_id;
    
    if (!is_dir($temp_dir)) {
        echo json_encode(['ok' => false, 'error' => 'Upload not found']);
        exit;
    }
    
    // Read metadata
    $meta_file = $temp_dir . '/metadata.json';
    if (!file_exists($meta_file)) {
        echo json_encode(['ok' => false, 'error' => 'Upload metadata not found']);
        exit;
    }
    
    $metadata = json_decode(file_get_contents($meta_file), true);
    $remote_path = $metadata['remote_path'];
    $expected_hash = $metadata['file_hash'];
    $total_chunks = $metadata['total_chunks'];
    
    // Combine chunks
    $final_file = $upload_dir . '/' . ltrim($remote_path, '/');
    $final_dir = dirname($final_file);
    
    if (!is_dir($final_dir)) {
        @mkdir($final_dir, 0775, true);
    }
    
    $output = fopen($final_file, 'wb');
    
    for ($i = 0; $i < $total_chunks; $i++) {
        $chunk_file = $temp_dir . '/chunk_' . $i;
        if (!file_exists($chunk_file)) {
            fclose($output);
            @unlink($final_file);
            echo json_encode(['ok' => false, 'error' => "Missing chunk $i"]);
            exit;
        }
        
        $chunk_data = file_get_contents($chunk_file);
        fwrite($output, $chunk_data);
    }
    
    fclose($output);
    
    // Verify integrity
    $actual_hash = hash_file('md5', $final_file);
    $integrity_verified = ($actual_hash === $expected_hash);
    
    // Cleanup temp directory
    array_map('unlink', glob($temp_dir . '/*'));
    @rmdir($temp_dir);
    
    echo json_encode([
        'ok' => true,
        'integrity_verified' => $integrity_verified,
        'local_hash' => $expected_hash,
        'remote_hash' => $actual_hash,
        'final_path' => $final_file
    ]);
    exit;
}

// Handle chunk upload
$upload_id = $data['upload_id'] ?? '';
$chunk_number = (int)($data['chunk_number'] ?? 0);
$total_chunks = (int)($data['total_chunks'] ?? 1);
$chunk_hash = $data['chunk_hash'] ?? '';
$file_hash = $data['file_hash'] ?? '';
$file_size = (int)($data['file_size'] ?? 0);
$remote_path = $data['remote_path'] ?? '';
$chunk_data = $data['chunk_data'] ?? '';

if (!$upload_id || !$remote_path || !$chunk_data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Decode chunk data
$chunk_binary = base64_decode($chunk_data);
if ($chunk_binary === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid base64 data']);
    exit;
}

// Verify chunk hash
$actual_chunk_hash = md5($chunk_binary);
if ($actual_chunk_hash !== $chunk_hash) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Chunk hash mismatch']);
    exit;
}

// Create temp directory for this upload
$temp_dir = $upload_dir . '/temp/' . $upload_id;
if (!is_dir($temp_dir)) {
    @mkdir($temp_dir, 0775, true);
}

// Save chunk
$chunk_file = $temp_dir . '/chunk_' . $chunk_number;
file_put_contents($chunk_file, $chunk_binary);

// Save metadata on first chunk
if ($chunk_number === 0) {
    $metadata = [
        'upload_id' => $upload_id,
        'remote_path' => $remote_path,
        'file_hash' => $file_hash,
        'file_size' => $file_size,
        'total_chunks' => $total_chunks,
        'created_at' => time()
    ];
    file_put_contents($temp_dir . '/metadata.json', json_encode($metadata));
}

echo json_encode([
    'ok' => true,
    'chunk_received' => $chunk_number,
    'chunk_hash' => $actual_chunk_hash
]);
?>
