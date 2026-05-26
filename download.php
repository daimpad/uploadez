<?php
declare(strict_types=1);

/**
 * UploadEz – Download-Handler
 *
 * URL: /download.php?token=<64-hex-zeichen>
 *
 * Sicherheitsmaßnahmen:
 *  • Token-Validierung (Format + DB-Lookup)
 *  • Ablauf-Prüfung (expiry)
 *  • Dateiname-Validierung vor Dateizugriff (Traversal-Schutz)
 *  • Content-Type aus DB (kein User-Input)
 *  • Download-Counter inkrementieren
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// ── Token lesen & validieren ─────────────────────────────────────────────────
$token = trim($_GET['token'] ?? '');

if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    respondWithError(400, 'Ungültiger Download-Link.');
}

// ── Datenbankabfrage ─────────────────────────────────────────────────────────
try {
    $pdo = getDb();
} catch (Throwable $e) {
    error_log('UploadEz DB-Fehler (download): ' . $e->getMessage());
    respondWithError(503, 'Dienst vorübergehend nicht verfügbar.');
}

$stmt = $pdo->prepare(
    'SELECT id, original_name, stored_name, mime_type, file_size, expiry
     FROM files
     WHERE token = :token
     LIMIT 1'
);
$stmt->execute([':token' => $token]);
$file = $stmt->fetch();

if ($file === false) {
    respondWithError(404, 'Datei nicht gefunden oder Link ungültig.');
}

// Ablauf prüfen
$expiryUtc = new DateTimeImmutable($file['expiry'], new DateTimeZone('UTC'));
$nowUtc    = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($nowUtc > $expiryUtc) {
    respondWithError(410, 'Dieser Download-Link ist abgelaufen.');
}

// ── Datei validieren ─────────────────────────────────────────────────────────
// Stored-Name darf kein Pfadteil enthalten (Traversal-Schutz)
$storedName = basename($file['stored_name']);
if ($storedName !== $file['stored_name'] || $storedName === '') {
    error_log('UploadEz: Ungültiger stored_name in DB: ' . $file['stored_name']);
    respondWithError(500, 'Interner Fehler.');
}

$filePath = UPLOAD_DIR . $storedName;

// realpath() schlägt fehl wenn Datei nicht existiert → sicher
$realPath = realpath($filePath);
if ($realPath === false || strpos($realPath, realpath(UPLOAD_DIR)) !== 0) {
    error_log('UploadEz: Datei nicht gefunden auf Disk: ' . $filePath);
    respondWithError(404, 'Datei nicht auf dem Server gefunden.');
}

if (!is_file($realPath) || !is_readable($realPath)) {
    respondWithError(403, 'Datei kann nicht gelesen werden.');
}

// ── Download-Counter inkrementieren ──────────────────────────────────────────
$pdo->prepare('UPDATE files SET download_count = download_count + 1 WHERE id = :id')
    ->execute([':id' => $file['id']]);

// ── HTTP-Headers für Download ─────────────────────────────────────────────────
// Content-Type aus DB (validiert beim Upload), nie vom User-Agent übernehmen
$mimeType = $file['mime_type'];

// Sicherer Dateiname für Content-Disposition (RFC 5987)
$safeOriginal = rawurlencode(
    preg_replace('/[\r\n\t]/', '', $file['original_name'])
);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . $safeOriginal);
header('Content-Length: ' . $file['file_size']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// ── Datei ausgeben (chunk-weise für große Dateien) ────────────────────────────
$fp = fopen($realPath, 'rb');
if ($fp === false) {
    respondWithError(500, 'Datei konnte nicht geöffnet werden.');
}

while (!feof($fp)) {
    echo fread($fp, 8192);
    flush();
}

fclose($fp);
exit;

// ── Fehler-Handler ────────────────────────────────────────────────────────────
function respondWithError(int $code, string $message): never
{
    http_response_code($code);
    // Minimales HTML ohne externe Ressourcen
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<title>Fehler ' . $code . ' – UploadEz</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:80px auto;padding:20px;text-align:center}'
        . 'h1{color:#ef4444}p{color:#64748b}a{color:#6366f1}</style></head><body>'
        . '<h1>' . $code . '</h1>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="/">← Zurück zum Upload</a></p>'
        . '</body></html>';
    exit;
}
