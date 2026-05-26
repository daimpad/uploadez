<?php
declare(strict_types=1);

/**
 * PHPUnit Bootstrap – definiert alle Konstanten die config.php normalerweise setzt,
 * damit die includes ohne laufende Datenbank geladen werden können.
 */

// Verhindert dass config.php doppelt ausgeführt wird
if (!defined('APP_URL')) {
    define('APP_URL',               'http://localhost');
    define('DB_HOST',               'localhost');
    define('DB_PORT',               3306);
    define('DB_NAME',               'uploadez_test');
    define('DB_USER',               'root');
    define('DB_PASS',               '');
    define('DB_CHARSET',            'utf8mb4');
    define('BASE_DIR',              dirname(__DIR__));
    define('UPLOAD_DIR',            sys_get_temp_dir() . '/uploadez_test_uploads/');
    define('TEMP_DIR',              sys_get_temp_dir() . '/uploadez_test_tmp/');
    define('MAX_FILE_SIZE',         2 * 1024 * 1024 * 1024);
    define('CHUNK_SIZE',            5 * 1024 * 1024);
    define('MAX_CHUNKS',            500);
    define('EXPIRY_DAYS',           7);
    define('UPLOAD_TOKEN',          '');
    define('RATE_LIMIT_MAX_UPLOADS', 20);
    define('RATE_LIMIT_WINDOW',     3600);
    define('ALLOWED_EXTENSIONS',    ['jpg', 'jpeg', 'png', 'pdf', 'zip', 'txt']);
    define('ALLOWED_MIME_TYPES',    ['image/jpeg', 'image/png', 'application/pdf']);
}

// Test-Verzeichnisse anlegen
foreach ([UPLOAD_DIR, TEMP_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}

require_once dirname(__DIR__) . '/includes/uploader.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/rate_limiter.php';
