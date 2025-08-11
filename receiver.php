
<?php
/**
 * HTTP Chunked Upload Receiver
 * Endpoint for receiving file chunks via HTTP POST
 */

require_once 'config.php';

// CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check authorization
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($authHeader, 'Bearer ')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing authorization']);
    exit;
}

$token = substr($authHeader, 7);
if ($token !== HTTP_UPLOAD_TOKEN) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
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

// Required fields
$required = ['upload_id', 'remote_path'];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Missing field: $field"]);
        exit;
    }
}

$uploadId = $data['upload_id'];
$remotePath = $data['remote_path'];

// Create uploads directory
$uploadsDir = LOCAL_STORAGE_PATH ?: './uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
}

// Create temp directory for chunks
$tempDir = $uploadsDir . '/temp/' . $uploadId;
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0775, true);
}

// Handle finalize request
if (isset($data['action']) && $data['action'] === 'finalize') {
    $result = finalizeUpload($uploadId, $remotePath, $tempDir, $uploadsDir);
    echo json_encode($result);
    exit;
}

// Handle chunk upload
if (!isset($data['chunk_number']) || !isset($data['total_chunks']) || !isset($data['chunk_data'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing chunk fields']);
    exit;
}

$chunkNumber = (int)$data['chunk_number'];
$totalChunks = (int)$data['total_chunks'];
$chunkData = base64_decode($data['chunk_data']);
$chunkHash = $data['chunk_hash'] ?? '';

// Verify chunk hash if provided
if ($chunkHash && md5($chunkData) !== $chunkHash) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Chunk hash mismatch']);
    exit;
}

// Save chunk
$chunkFile = $tempDir . '/chunk_' . str_pad($chunkNumber, 6, '0', STR_PAD_LEFT);
$bytesWritten = file_put_contents($chunkFile, $chunkData);

if ($bytesWritten === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save chunk']);
    exit;
}

// Save metadata
$metadata = [
    'upload_id' => $uploadId,
    'remote_path' => $remotePath,
    'total_chunks' => $totalChunks,
    'file_hash' => $data['file_hash'] ?? null,
    'file_size' => $data['file_size'] ?? null,
    'chunks_received' => [],
    'created_at' => time()
];

$metaFile = $tempDir . '/metadata.json';
if (file_exists($metaFile)) {
    $existing = json_decode(file_get_contents($metaFile), true);
    if ($existing) {
        $metadata = array_merge($existing, $metadata);
    }
}

$metadata['chunks_received'][] = $chunkNumber;
$metadata['chunks_received'] = array_unique($metadata['chunks_received']);
sort($metadata['chunks_received']);

file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));

// Log the chunk reception
error_log("Received chunk $chunkNumber/$totalChunks for upload $uploadId");

echo json_encode([
    'ok' => true,
    'chunk_number' => $chunkNumber,
    'chunks_received' => count($metadata['chunks_received']),
    'total_chunks' => $totalChunks,
    'complete' => count($metadata['chunks_received']) === $totalChunks
]);

function finalizeUpload($uploadId, $remotePath, $tempDir, $uploadsDir) {
    $metaFile = $tempDir . '/metadata.json';
    
    if (!file_exists($metaFile)) {
        return ['ok' => false, 'error' => 'Upload metadata not found'];
    }
    
    $metadata = json_decode(file_get_contents($metaFile), true);
    if (!$metadata) {
        return ['ok' => false, 'error' => 'Invalid metadata'];
    }
    
    $totalChunks = $metadata['total_chunks'];
    $chunksReceived = $metadata['chunks_received'] ?? [];
    
    // Check if all chunks received
    if (count($chunksReceived) !== $totalChunks) {
        return [
            'ok' => false, 
            'error' => 'Missing chunks',
            'received' => count($chunksReceived),
            'expected' => $totalChunks
        ];
    }
    
    // Combine chunks
    $finalPath = $uploadsDir . '/' . ltrim($remotePath, '/');
    $finalDir = dirname($finalPath);
    
    if (!is_dir($finalDir)) {
        @mkdir($finalDir, 0775, true);
    }
    
    $finalFile = fopen($finalPath, 'wb');
    if (!$finalFile) {
        return ['ok' => false, 'error' => 'Cannot create final file'];
    }
    
    $combinedHash = hash_init('md5');
    
    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkFile = $tempDir . '/chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
        
        if (!file_exists($chunkFile)) {
            fclose($finalFile);
            @unlink($finalPath);
            return ['ok' => false, 'error' => "Missing chunk $i"];
        }
        
        $chunkData = file_get_contents($chunkFile);
        fwrite($finalFile, $chunkData);
        hash_update($combinedHash, $chunkData);
    }
    
    fclose($finalFile);
    $finalHash = hash_final($combinedHash);
    
    // Verify file integrity
    $integrityVerified = true;
    if (isset($metadata['file_hash']) && $metadata['file_hash'] !== $finalHash) {
        $integrityVerified = false;
    }
    
    // Clean up temp files
    cleanupTempFiles($tempDir);
    
    error_log("Finalized upload $uploadId -> $finalPath (verified: " . ($integrityVerified ? 'yes' : 'no') . ")");
    
    return [
        'ok' => true,
        'file_path' => $finalPath,
        'file_size' => filesize($finalPath),
        'integrity_verified' => $integrityVerified,
        'remote_hash' => $finalHash,
        'expected_hash' => $metadata['file_hash'] ?? null
    ];
}

function cleanupTempFiles($tempDir) {
    if (!is_dir($tempDir)) return;
    
    $files = glob($tempDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($tempDir);
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
?>
