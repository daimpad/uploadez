<?php
declare(strict_types=1);

/**
 * UploadEz – Cleanup-Cronjob
 *
 * Löscht abgelaufene Dateien vom Dateisystem und aus der Datenbank.
 * Zusätzlich werden veraltete Chunk-Verzeichnisse in /tmp/ bereinigt.
 *
 * Cronjob-Einrichtung (täglich um 02:30 Uhr):
 *   30 2 * * * php /var/www/html/cleanup.php >> /var/log/uploadez-cleanup.log 2>&1
 *
 * WICHTIG: Diese Datei ist über .htaccess von außen gesperrt.
 * Nur CLI-Ausführung erlaubt.
 */

// Direktzugriff über Webserver verweigern
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Nur CLI-Ausführung erlaubt.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$startTime = microtime(true);
$log       = [];

function logLine(string $msg): void
{
    global $log;
    $line  = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $log[] = $line;
    echo $line . PHP_EOL;
}

logLine('=== UploadEz Cleanup gestartet ===');

// ── 1. Abgelaufene Dateien aus der DB laden ──────────────────────────────────
try {
    $pdo  = getDb();
    $stmt = $pdo->query(
        'SELECT id, stored_name FROM files WHERE expiry < NOW()'
    );
    $expired = $stmt->fetchAll();
} catch (Throwable $e) {
    logLine('FEHLER: DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
    exit(1);
}

logLine(count($expired) . ' abgelaufene Datei(en) gefunden.');

$deletedFiles = 0;
$failedFiles  = 0;

foreach ($expired as $row) {
    $storedName = basename($row['stored_name']); // Traversal-Schutz
    $filePath   = UPLOAD_DIR . $storedName;

    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            $deletedFiles++;
            logLine("  ✓ Gelöscht: $storedName");
        } else {
            $failedFiles++;
            logLine("  ✗ Konnte nicht löschen: $storedName");
        }
    } else {
        logLine("  ~ Datei nicht auf Disk: $storedName (DB-Eintrag wird trotzdem entfernt)");
    }

    // DB-Eintrag entfernen (auch wenn Datei nicht mehr existiert)
    $pdo->prepare('DELETE FROM files WHERE id = :id')
        ->execute([':id' => $row['id']]);
}

// ── 2. Verwaiste Chunk-Verzeichnisse bereinigen ──────────────────────────────
// Verzeichnisse, die älter als 24h sind → Upload wurde nie abgeschlossen
$cutoff      = time() - 86400; // 24 Stunden
$chunkDirs   = glob(TEMP_DIR . '*', GLOB_ONLYDIR) ?: [];
$cleanedDirs = 0;

foreach ($chunkDirs as $dir) {
    if (filemtime($dir) < $cutoff) {
        // Alle Chunks im Verzeichnis löschen
        foreach (glob($dir . '/*') ?: [] as $chunk) {
            @unlink($chunk);
        }
        if (@rmdir($dir)) {
            $cleanedDirs++;
            logLine('  ✓ Chunk-Verzeichnis bereinigt: ' . basename($dir));
        }
    }
}

// ── 3. Abgelaufene Rate-Limit-Einträge löschen ───────────────────────────────
try {
    $stmt = $pdo->prepare(
        'DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL :window SECOND)'
    );
    $stmt->execute([':window' => RATE_LIMIT_WINDOW]);
    $deletedRateLimits = $stmt->rowCount();
    logLine("  ✓ Rate-Limit-Einträge bereinigt: $deletedRateLimits");
} catch (Throwable $e) {
    logLine('  ~ rate_limits-Cleanup übersprungen: ' . $e->getMessage());
    $deletedRateLimits = 0;
}

// ── 4. Zusammenfassung ───────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $startTime, 3);
logLine('');
logLine("Zusammenfassung:");
logLine("  Dateien gelöscht:          $deletedFiles");
logLine("  Dateien fehlgeschlagen:    $failedFiles");
logLine("  Chunk-Verzeichnisse:       $cleanedDirs");
logLine("  Rate-Limit-Einträge:       $deletedRateLimits");
logLine("  Laufzeit:                  {$elapsed}s");
logLine('=== Cleanup abgeschlossen ===');

exit($failedFiles > 0 ? 1 : 0);
