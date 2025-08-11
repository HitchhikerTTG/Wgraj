<?php
/**
 * HTTP Chunked Upload Receiver
 * Endpoint for receiving file chunks via HTTP POST
 */

require_once 'config.php';

// Debug logging
error_log("RECEIVER: Request received - Method: " . $_SERVER['REQUEST_METHOD'] . ", URL: " . $_SERVER['REQUEST_URI']);
error_log("RECEIVER: Headers: " . json_encode(apache_request_headers()));
error_log("RECEIVER: Server vars: " . json_encode($_SERVER));

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    error_log("RECEIVER: OPTIONS request handled");
    exit(0);
}

// Handle GET requests for testing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    error_log("RECEIVER: GET request - returning status");
    echo json_encode([
        'ok' => true,
        'status' => 'receiver.php is working',
        'time' => date('Y-m-d H:i:s'),
        'upload_method' => UPLOAD_METHOD,
        'local_storage_path' => LOCAL_STORAGE_PATH
    ]);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    error_log("RECEIVER: Method not allowed - " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check authorization
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
error_log("RECEIVER: Auth header: " . $authHeader);

if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    error_log("RECEIVER: Missing or invalid authorization header");
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid authorization']);
    exit;
}

$token = $matches[1];
error_log("RECEIVER: Token received: " . substr($token, 0, 10) . "...");

if ($token !== HTTP_UPLOAD_TOKEN) {
    error_log("RECEIVER: Invalid token - expected: " . substr(HTTP_UPLOAD_TOKEN, 0, 10) . "..., got: " . substr($token, 0, 10) . "...");
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

// Parse JSON input
$input = file_get_contents('php://input');
error_log("RECEIVER: Raw input length: " . strlen($input));
$data = json_decode($input, true);

if (!$data) {
    error_log("RECEIVER: Failed to decode JSON input.");
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Required fields
$required = ['upload_id', 'remote_path'];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        error_log("RECEIVER: Missing required field: $field");
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Missing field: $field"]);
        exit;
    }
}

$uploadId = $data['upload_id'];
$remotePath = $data['remote_path'];
error_log("RECEIVER: Processing upload ID: $uploadId, Remote path: $remotePath");

// Create uploads directory
$uploadsDir = LOCAL_STORAGE_PATH ?: './uploads';
if (!is_dir($uploadsDir)) {
    error_log("RECEIVER: Creating uploads directory: $uploadsDir");
    @mkdir($uploadsDir, 0775, true);
}

// Create temp directory for chunks
$tempDir = $uploadsDir . '/temp/' . $uploadId;
if (!is_dir($tempDir)) {
    error_log("RECEIVER: Creating temp directory: $tempDir");
    @mkdir($tempDir, 0775, true);
}

// Handle finalize request
if (isset($data['action']) && $data['action'] === 'finalize') {
    error_log("RECEIVER: Finalizing upload for ID: $uploadId");
    $result = finalizeUpload($uploadId, $remotePath, $tempDir, $uploadsDir);
    echo json_encode($result);
    exit;
}

// Handle chunk upload
if (!isset($data['chunk_number']) || !isset($data['total_chunks']) || !isset($data['chunk_data'])) {
    error_log("RECEIVER: Missing chunk fields for upload ID: $uploadId");
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing chunk fields']);
    exit;
}

$chunkNumber = (int)$data['chunk_number'];
$totalChunks = (int)$data['total_chunks'];
$chunkData = base64_decode($data['chunk_data']);
$chunkHash = $data['chunk_hash'] ?? '';

error_log("RECEIVER: Received chunk $chunkNumber/$totalChunks for upload ID $uploadId. Data length: " . strlen($chunkData) . ", Hash: $chunkHash");

// Verify chunk hash if provided
if ($chunkHash && md5($chunkData) !== $chunkHash) {
    error_log("RECEIVER: Chunk hash mismatch for chunk $chunkNumber, upload ID $uploadId.");
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Chunk hash mismatch']);
    exit;
}

// Save chunk
$chunkFile = $tempDir . '/chunk_' . str_pad($chunkNumber, 6, '0', STR_PAD_LEFT);
$bytesWritten = file_put_contents($chunkFile, $chunkData);

if ($bytesWritten === false) {
    error_log("RECEIVER: Failed to save chunk $chunkNumber for upload ID $uploadId to $chunkFile.");
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
error_log("RECEIVER: Received chunk $chunkNumber/$totalChunks for upload $uploadId");

echo json_encode([
    'ok' => true,
    'chunk_number' => $chunkNumber,
    'chunks_received' => count($metadata['chunks_received']),
    'total_chunks' => $totalChunks,
    'complete' => count($metadata['chunks_received']) === $totalChunks
]);

function finalizeUpload($uploadId, $remotePath, $tempDir, $uploadsDir) {
    $metaFile = $tempDir . '/metadata.json';
    error_log("RECEIVER: Finalizing upload for ID $uploadId. Metadata file: $metaFile");

    if (!file_exists($metaFile)) {
        error_log("RECEIVER: Metadata file not found for upload ID $uploadId.");
        return ['ok' => false, 'error' => 'Upload metadata not found'];
    }

    $metadata = json_decode(file_get_contents($metaFile), true);
    if (!$metadata) {
        error_log("RECEIVER: Invalid metadata content for upload ID $uploadId.");
        return ['ok' => false, 'error' => 'Invalid metadata'];
    }

    $totalChunks = $metadata['total_chunks'];
    $chunksReceived = $metadata['chunks_received'] ?? [];
    error_log("RECEIVER: Upload ID $uploadId: Total chunks expected: $totalChunks, Chunks received: " . count($chunksReceived));

    // Check if all chunks received
    if (count($chunksReceived) !== $totalChunks) {
        error_log("RECEIVER: Missing chunks for upload ID $uploadId. Received: " . count($chunksReceived) . ", Expected: $totalChunks");
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
    error_log("RECEIVER: Combining chunks for upload ID $uploadId into: $finalPath");

    if (!is_dir($finalDir)) {
        error_log("RECEIVER: Creating final directory: $finalDir");
        @mkdir($finalDir, 0775, true);
    }

    $finalFile = fopen($finalPath, 'wb');
    if (!$finalFile) {
        error_log("RECEIVER: Failed to open final file for writing: $finalPath");
        return ['ok' => false, 'error' => 'Cannot create final file'];
    }

    $combinedHash = hash_init('md5');

    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkFile = $tempDir . '/chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);

        if (!file_exists($chunkFile)) {
            error_log("RECEIVER: Missing chunk file for finalization: $chunkFile");
            fclose($finalFile);
            @unlink($finalPath); // Clean up partially created file
            return ['ok' => false, 'error' => "Missing chunk $i"];
        }

        $chunkData = file_get_contents($chunkFile);
        if ($chunkData === false) {
             error_log("RECEIVER: Failed to read chunk file: $chunkFile");
             fclose($finalFile);
             @unlink($finalPath);
             return ['ok' => false, 'error' => "Failed to read chunk $i"];
        }
        fwrite($finalFile, $chunkData);
        hash_update($combinedHash, $chunkData);
        error_log("RECEIVER: Appended chunk $i to final file.");
    }

    fclose($finalFile);
    $finalHash = hash_final($combinedHash);
    error_log("RECEIVER: Combined hash for upload ID $uploadId: $finalHash");

    // Verify file integrity
    $integrityVerified = true;
    if (isset($metadata['file_hash']) && $metadata['file_hash'] !== $finalHash) {
        $integrityVerified = false;
        error_log("RECEIVER: File integrity check failed for upload ID $uploadId. Expected: " . $metadata['file_hash'] . ", Got: $finalHash");
    } else {
         error_log("RECEIVER: File integrity check passed for upload ID $uploadId.");
    }

    // Clean up temp files
    cleanupTempFiles($tempDir);

    error_log("RECEIVER: Finalized upload $uploadId -> $finalPath (verified: " . ($integrityVerified ? 'yes' : 'no') . ")");

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
    error_log("RECEIVER: Cleaning up temporary directory: $tempDir");

    $files = glob($tempDir . '/*');
    if ($files === false) {
        error_log("RECEIVER: Failed to glob files in temporary directory: $tempDir");
        return;
    }
    foreach ($files as $file) {
        if (is_file($file)) {
            if (!@unlink($file)) {
                 error_log("RECEIVER: Failed to delete temporary file: $file");
            }
        }
    }
    if (!@rmdir($tempDir)) {
         error_log("RECEIVER: Failed to remove temporary directory: $tempDir");
    }
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
?>