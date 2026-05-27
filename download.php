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

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => isset($_SERVER['HTTPS'])]);
session_start();

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
    'SELECT id, original_name, stored_name, mime_type, file_size, expiry, link_password_hash
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

// ── Passwortschutz ────────────────────────────────────────────────────────────
if (!empty($file['link_password_hash'])) {
    $sessionKey = 'dl_auth_' . $token;

    if (empty($_SESSION[$sessionKey])) {
        $pwError = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_password'])) {
            if (password_verify($_POST['link_password'], $file['link_password_hash'])) {
                $_SESSION[$sessionKey] = true;
                // Nach korrektem Passwort: GET-Redirect verhindert Formular-Resubmit
                header('Location: download.php?token=' . urlencode($token));
                exit;
            }
            $pwError = true;
        }

        showPasswordForm($token, $file['original_name'], $pwError);
    }
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
$uploadDir = realpath(UPLOAD_DIR);
$realPath  = realpath($filePath);
if ($uploadDir === false || $realPath === false || strpos($realPath, $uploadDir) !== 0) {
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

// ── Passwort-Formular ─────────────────────────────────────────────────────────
function showPasswordForm(string $token, string $filename, bool $error): never
{
    $h    = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $err  = $error ? '<p style="color:#ef4444;font-size:.88rem;margin-bottom:12px;">Falsches Passwort. Bitte erneut versuchen.</p>' : '';
    http_response_code(200);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Passwort erforderlich – UploadEz</title>'
        . '<style>'
        . 'body{font-family:system-ui,sans-serif;background:#f0f2ff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.card{background:#fff;border-radius:12px;box-shadow:0 8px 40px rgba(99,102,241,.18);padding:40px;width:100%;max-width:380px}'
        . 'h2{margin:0 0 4px;font-size:1.2rem} p.sub{color:#64748b;font-size:.88rem;margin:0 0 24px}'
        . 'label{display:block;font-size:.78rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}'
        . 'input[type=password]{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.95rem;box-sizing:border-box;outline:none}'
        . 'input[type=password]:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}'
        . 'button{width:100%;margin-top:16px;padding:13px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer}'
        . '.icon{font-size:2.5rem;text-align:center;margin-bottom:12px}'
        . '</style></head><body>'
        . '<div class="card">'
        . '<div class="icon">🔒</div>'
        . '<h2>Passwort erforderlich</h2>'
        . '<p class="sub">Die Datei <strong>' . $h($filename) . '</strong> ist passwortgeschützt.</p>'
        . $err
        . '<form method="POST" action="download.php?token=' . $h($token) . '">'
        . '<label for="pw">Passwort</label>'
        . '<input type="password" id="pw" name="link_password" autofocus required placeholder="••••••••">'
        . '<button type="submit">Zugriff bestätigen</button>'
        . '</form>'
        . '</div></body></html>';
    exit;
}

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
