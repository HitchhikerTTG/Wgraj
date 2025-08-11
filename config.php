
<?php
/**
 * Configuration loader with environment variable support
 */

// Load environment variables from .env file
function loadEnv($file = '.env') {
    if (!file_exists($file)) {
        throw new Exception("Environment file {$file} not found");
    }
    
    $content = file_get_contents($file);
    error_log("=== LOADING .ENV FILE ===");
    error_log("File exists: " . ($file));
    error_log("File size: " . strlen($content) . " bytes");
    error_log("File encoding: " . mb_detect_encoding($content));
    
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    error_log("Total lines after filtering: " . count($lines));
    
    foreach ($lines as $lineNum => $line) {
        error_log("--- Processing line " . ($lineNum + 1) . " ---");
        error_log("Raw line: '" . addslashes($line) . "'");
        error_log("Line length: " . strlen($line));
        error_log("Line bytes: " . bin2hex($line));
        
        if (strpos($line, '#') === 0) {
            error_log("Skipping comment line");
            continue; // Skip comments
        }
        
        $parts = explode('=', $line, 2);
        error_log("Parts count: " . count($parts));
        
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            error_log("Key: '" . addslashes($key) . "'");
            error_log("Value: '" . addslashes($value) . "'");
            
            try {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
                error_log("SUCCESS: Set {$key}");
            } catch (Exception $e) {
                error_log("ERROR setting env var {$key}: " . $e->getMessage());
                throw new Exception("Error setting environment variable {$key}: " . $e->getMessage());
            }
        } else {
            error_log("WARNING: Line doesn't contain exactly one '=' character");
            if (count($parts) === 1 && trim($parts[0]) !== '') {
                error_log("ERROR: Line '" . addslashes($line) . "' is malformed - missing '=' character");
                throw new Exception("Malformed line in .env file (line " . ($lineNum + 1) . "): missing '=' character in '" . $line . "'");
            }
        }
    }
    error_log("=== .ENV LOADING COMPLETED ===");
}

// Load environment
try {
    loadEnv();
} catch (Exception $e) {
    die("Configuration error: " . $e->getMessage());
}

// Configuration constants
define('ADMIN_KEY', $_ENV['ADMIN_KEY'] ?? 'changeme');
define('BASE_URL', $_ENV['BASE_URL'] ?? 'http://localhost');

// FTP Configuration
define('FTP_MODE', $_ENV['FTP_MODE'] ?? 'explicit');
define('FTP_HOST', $_ENV['FTP_HOST'] ?? 'localhost');
define('FTP_PORT', (int)($_ENV['FTP_PORT'] ?? 21));
define('FTP_USER', $_ENV['FTP_USER'] ?? 'user');
define('FTP_PASS', $_ENV['FTP_PASS'] ?? 'pass');
define('FTP_ROOTDIR', $_ENV['FTP_ROOTDIR'] ?? '/uploads');

// Upload limits
define('MAX_BYTES', (int)($_ENV['MAX_BYTES'] ?? 500 * 1024 * 1024));
define('ALLOW_EXT', explode(',', $_ENV['ALLOWED_EXTENSIONS'] ?? 'pdf,jpg,jpeg,png,zip,txt'));
define('TOKEN_TTL_H', (int)($_ENV['TOKEN_TTL_HOURS'] ?? 72));

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
define('DEBUG_UPLOAD', filter_var($_ENV['DEBUG_UPLOAD'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('DEBUG_LOG_FILE', $_ENV['DEBUG_LOG_FILE'] ?? __DIR__ . '/data/upload.log');
define('DEBUG_VERBOSE_LIMIT', (int)($_ENV['DEBUG_VERBOSE_LIMIT'] ?? 2000));

// Data directory
define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}
