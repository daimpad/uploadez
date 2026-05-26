<?php
declare(strict_types=1);

/**
 * UploadEz – Zentrale Konfiguration
 *
 * php.ini-Empfehlungen (in php.ini oder per .htaccess via php_value setzen):
 *   upload_max_filesize = 2G
 *   post_max_size       = 2G
 *   max_execution_time  = 3600
 *   memory_limit        = 256M
 *   file_uploads        = On
 */

// ── Datenbankverbindung ──────────────────────────────────────────────────────
define('DB_HOST',    getenv('DB_HOST') ?: 'localhost');
define('DB_PORT',    (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME',    getenv('DB_NAME') ?: 'uploadez');
define('DB_USER',    getenv('DB_USER') ?: 'uploadez_user');
define('DB_PASS',    getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ── Verzeichnisse ────────────────────────────────────────────────────────────
define('BASE_DIR',   __DIR__);
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('TEMP_DIR',   __DIR__ . '/tmp/');

// ── Upload-Limits ────────────────────────────────────────────────────────────
define('MAX_FILE_SIZE',  2 * 1024 * 1024 * 1024);  // 2 GB
define('CHUNK_SIZE',     5 * 1024 * 1024);          // 5 MB pro Chunk (clientseitig)
define('EXPIRY_DAYS',    7);                         // Dateien nach 7 Tagen löschen
define('MAX_CHUNKS',     500);                       // Maximal 500 Chunks (= 2,5 GB bei 5 MB)

// ── SMTP / E-Mail ────────────────────────────────────────────────────────────
// PHPMailer wird verwendet wenn vendor/autoload.php existiert, sonst PHP mail()
define('SMTP_HOST',      getenv('SMTP_HOST') ?: 'smtp.example.com');
define('SMTP_PORT',      (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER',      getenv('SMTP_USER') ?: 'noreply@example.com');
define('SMTP_PASS',      getenv('SMTP_PASS') ?: '');
define('SMTP_FROM',      getenv('SMTP_FROM') ?: 'noreply@example.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'UploadEz');
define('SMTP_SECURE',    getenv('SMTP_SECURE') ?: 'tls'); // 'tls' oder 'ssl'

// ── App-URL (ohne trailing slash) ───────────────────────────────────────────
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost', '/'));

// ── Erlaubte MIME-Types (Whitelist) ─────────────────────────────────────────
define('ALLOWED_MIME_TYPES', [
    // Bilder
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    // Dokumente
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    // Text
    'text/plain', 'text/csv',
    // Archive
    'application/zip',
    'application/x-zip-compressed',
    'application/x-rar-compressed',
    'application/x-7z-compressed',
    'application/x-tar',
    'application/gzip',
    // Audio
    'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4',
    // Video
    'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
]);

// ── Erlaubte Dateiendungen (korrespondierend zur MIME-Whitelist) ─────────────
define('ALLOWED_EXTENSIONS', [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv',
    'zip', 'rar', '7z', 'tar', 'gz',
    'mp3', 'wav', 'ogg', 'm4a',
    'mp4', 'webm', 'ogv', 'mov',
]);
