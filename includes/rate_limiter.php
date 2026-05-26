<?php
declare(strict_types=1);

/**
 * UploadEz – IP-basiertes Rate Limiting
 *
 * Prüft ob eine IP-Adresse innerhalb des konfigurierten Zeitfensters
 * (RATE_LIMIT_WINDOW Sekunden) mehr als RATE_LIMIT_MAX_UPLOADS Uploads
 * gestartet hat. Wirft RuntimeException(429) bei Überschreitung.
 *
 * Konfiguration (config.php / .env):
 *   RATE_LIMIT_MAX    = 20     (Uploads pro Zeitfenster)
 *   RATE_LIMIT_WINDOW = 3600   (Sekunden, Standard: 1 Stunde)
 */

function checkRateLimit(PDO $pdo, string $ip): void
{
    // Probabilistisches Cleanup (≈10 % der Anfragen) verhindert Tabellenwachstum
    // ohne jeden Request zu bremsen
    if (random_int(1, 10) === 1) {
        $pdo->prepare(
            'DELETE FROM rate_limits
             WHERE created_at < DATE_SUB(NOW(), INTERVAL :window SECOND)'
        )->execute([':window' => RATE_LIMIT_WINDOW]);
    }

    // Anzahl aktueller Versuche für diese IP im Zeitfenster
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM rate_limits
         WHERE ip = INET6_ATON(:ip)
           AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND)'
    );
    $stmt->execute([':ip' => $ip, ':window' => RATE_LIMIT_WINDOW]);
    $count = (int) $stmt->fetchColumn();

    if ($count >= RATE_LIMIT_MAX_UPLOADS) {
        $minutes = (int) ceil(RATE_LIMIT_WINDOW / 60);
        throw new RuntimeException(
            "Rate-Limit erreicht: Maximal " . RATE_LIMIT_MAX_UPLOADS
            . " Uploads pro $minutes Minuten erlaubt. Bitte später erneut versuchen.",
            429
        );
    }

    // Versuch registrieren
    $pdo->prepare('INSERT INTO rate_limits (ip) VALUES (INET6_ATON(:ip))')
        ->execute([':ip' => $ip]);
}

/**
 * Liest die Client-IP aus dem Request (X-Forwarded-For oder REMOTE_ADDR).
 * Gibt null zurück wenn keine gültige IP ermittelt werden kann.
 */
function getClientIp(): ?string
{
    $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $candidate = trim(explode(',', $raw)[0]);
    $validated = filter_var($candidate, FILTER_VALIDATE_IP);
    return $validated !== false ? $validated : null;
}
