<?php
declare(strict_types=1);

/**
 * UploadEz – Health-Check-Endpunkt
 *
 * Gibt den Status der Anwendung als JSON zurück.
 * HTTP 200 = alles in Ordnung, HTTP 503 = mindestens ein Check fehlgeschlagen.
 *
 * Einsatz:
 *  • Load-Balancer: GET /health.php → prüft ob Instanz bereit ist
 *  • Monitoring (z. B. UptimeRobot, Zabbix): auf HTTP 200 prüfen
 *  • Cronjob-Vorprüfung: php health.php && php cleanup.php
 *
 * Beispiel-Response (HTTP 200):
 *  {
 *    "status": "ok",
 *    "checks": {
 *      "database":    { "status": "ok", "latency_ms": 3 },
 *      "uploads_dir": { "status": "ok" },
 *      "tmp_dir":     { "status": "ok" }
 *    },
 *    "php_version": "8.2.0",
 *    "timestamp":   "2026-05-26T14:00:00+00:00"
 *  }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$checks     = [];
$httpStatus = 200;

// ── 1. Datenbank ─────────────────────────────────────────────────────────────
$dbStart = hrtime(true);
try {
    $pdo = getDb();
    $pdo->query('SELECT 1');
    $dbMs = intdiv(hrtime(true) - $dbStart, 1_000_000); // ns → ms
    $checks['database'] = ['status' => 'ok', 'latency_ms' => $dbMs];
} catch (Throwable $e) {
    $checks['database'] = ['status' => 'error', 'message' => 'Verbindung fehlgeschlagen'];
    $httpStatus = 503;
}

// ── 2. uploads/-Verzeichnis schreibbar ───────────────────────────────────────
$probe = UPLOAD_DIR . '.healthcheck_' . bin2hex(random_bytes(4));
if (@file_put_contents($probe, '1') !== false) {
    @unlink($probe);
    $checks['uploads_dir'] = ['status' => 'ok'];
} else {
    $checks['uploads_dir'] = ['status' => 'error', 'message' => 'Kein Schreibzugriff'];
    $httpStatus = 503;
}

// ── 3. tmp/-Verzeichnis schreibbar ────────────────────────────────────────────
$probe = TEMP_DIR . '.healthcheck_' . bin2hex(random_bytes(4));
if (@file_put_contents($probe, '1') !== false) {
    @unlink($probe);
    $checks['tmp_dir'] = ['status' => 'ok'];
} else {
    $checks['tmp_dir'] = ['status' => 'error', 'message' => 'Kein Schreibzugriff'];
    $httpStatus = 503;
}

// ── Antwort ───────────────────────────────────────────────────────────────────
http_response_code($httpStatus);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode([
    'status'      => $httpStatus === 200 ? 'ok' : 'error',
    'checks'      => $checks,
    'php_version' => PHP_VERSION,
    'timestamp'   => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
