<?php
/**
 * Simplified Configuration - Local Storage Only
 */

// Load environment variables from .env file
function loadEnv($file = '.env') {
    if (!file_exists($file)) {
        throw new Exception("Environment file {$file} not found");
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue; // Skip comments
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

// Load environment
try {
    loadEnv();
} catch (Exception $e) {
    die("Configuration error: " . $e->getMessage());
}

// Basic configuration
define('ADMIN_KEY', $_ENV['ADMIN_KEY'] ?? 'changeme');
define('BASE_URL', $_ENV['BASE_URL'] ?? 'http://localhost');

// Upload method configuration
define('UPLOAD_METHOD', $_ENV['UPLOAD_METHOD'] ?? 'local');
define('LOCAL_STORAGE_PATH', $_ENV['LOCAL_STORAGE_PATH'] ?? './uploads');

// HTTP Upload configuration
if (!empty($_ENV['HTTP_UPLOAD_URL'])) {
    define('HTTP_UPLOAD_URL', $_ENV['HTTP_UPLOAD_URL']);
    define('HTTP_UPLOAD_TOKEN', $_ENV['HTTP_UPLOAD_TOKEN'] ?? '');
    define('CHUNK_SIZE', (int)($_ENV['CHUNK_SIZE'] ?? 1048576));
}

// FTP configuration (backup method)
if (!empty($_ENV['FTP_HOST'])) {
    define('FTP_HOST', $_ENV['FTP_HOST']);
    define('FTP_PORT', (int)($_ENV['FTP_PORT'] ?? 21));
    define('FTP_USER', $_ENV['FTP_USER'] ?? '');
    define('FTP_PASS', $_ENV['FTP_PASS'] ?? '');
    define('FTP_MODE', $_ENV['FTP_MODE'] ?? 'explicit');
    define('FTP_ROOTDIR', $_ENV['FTP_ROOTDIR'] ?? '/');
    define('FTP_CONNECT_TIMEOUT', (int)($_ENV['FTP_CONNECT_TIMEOUT'] ?? 30));
    define('FTP_TOTAL_TIMEOUT', (int)($_ENV['FTP_TOTAL_TIMEOUT'] ?? 300));
    define('FTP_LOW_SPEED_TIME', (int)($_ENV['FTP_LOW_SPEED_TIME'] ?? 60));
}

// Upload limits
define('MAX_BYTES', (int)($_ENV['MAX_BYTES'] ?? 500 * 1024 * 1024));
define('ALLOW_EXT', explode(',', $_ENV['ALLOWED_EXTENSIONS'] ?? 'pdf,jpg,jpeg,png,zip,txt,mp3,wav,mov,mp4,avi'));
define('TOKEN_TTL_HOURS', (int)($_ENV['TOKEN_TTL_HOURS'] ?? 72));

// Email configuration
define('EMAIL_TO', $_ENV['EMAIL_TO'] ?? 'admin@example.com');
define('EMAIL_FROM', $_ENV['EMAIL_FROM'] ?? 'uploader@example.com');

// SMTP configuration (optional)
if (!empty($_ENV['SMTP_HOST'])) {
    define('SMTP_HOST', $_ENV['SMTP_HOST']);
    define('SMTP_PORT', (int)($_ENV['SMTP_PORT'] ?? 587));
    define('SMTP_USER', $_ENV['SMTP_USER'] ?? '');
    define('SMTP_PASS', $_ENV['SMTP_PASS'] ?? '');
    define('SMTP_SECURE', $_ENV['SMTP_SECURE'] ?? 'tls');
}

// Debug configuration
define('DEBUG_UPLOAD', filter_var($_ENV['DEBUG_UPLOAD'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('DEBUG_LOG_FILE', $_ENV['DEBUG_LOG_FILE'] ?? __DIR__ . '/data/upload.log');
define('FRONTEND_DEBUG', filter_var($_ENV['FRONTEND_DEBUG'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Data directory
define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

// Uploads directory
if (!is_dir(LOCAL_STORAGE_PATH)) {
    @mkdir(LOCAL_STORAGE_PATH, 0775, true);
}
?>