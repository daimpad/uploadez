<?php
declare(strict_types=1);

/**
 * UploadEz – Chunk-Upload-Handler
 *
 * Ablauf:
 *  1. Client sendet Chunks einzeln via POST (chunk_index, total_chunks, …).
 *  2. Beim ersten Chunk (index 0): Upload-Auth + Rate-Limit prüfen.
 *  3. Jeder Chunk wird unter tmp/{file_id}/chunk_{index} gespeichert.
 *  4. Nach dem letzten Chunk: Chunks zusammenführen → MIME validieren →
 *     in uploads/{stored_name} ablegen → DB-Eintrag erstellen.
 */

function handleUploadChunk(PDO $pdo): void
{
    // ── Eingaben lesen & validieren ──────────────────────────────────────────
    $chunkIndex  = filter_input(INPUT_POST, 'chunk_index',  FILTER_VALIDATE_INT);
    $totalChunks = filter_input(INPUT_POST, 'total_chunks', FILTER_VALIDATE_INT);
    $totalSize   = filter_input(INPUT_POST, 'total_size',   FILTER_VALIDATE_INT);
    $fileId      = trim($_POST['file_id']        ?? '');
    $origName    = trim($_POST['original_name']  ?? '');
    $email       = trim($_POST['email']          ?? '');
    $linkPw      = $_POST['link_password']       ?? '';

    if ($chunkIndex === false || $totalChunks === false || $totalSize === false) {
        throw new RuntimeException('Ungültige Chunk-Parameter.', 400);
    }

    if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
        throw new RuntimeException('chunk_index außerhalb des gültigen Bereichs.', 400);
    }

    if ($totalChunks < 1 || $totalChunks > MAX_CHUNKS) {
        throw new RuntimeException('Ungültige Chunk-Anzahl.', 400);
    }

    if ($totalSize <= 0 || $totalSize > MAX_FILE_SIZE) {
        throw new RuntimeException('Dateigröße überschreitet das erlaubte Maximum (2 GB).', 400);
    }

    // UUID v4 validieren (clientseitig erzeugt)
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $fileId)) {
        throw new RuntimeException('Ungültige file_id.', 400);
    }

    // Dateiname bereinigen (kein Verzeichnis-Traversal möglich)
    $origName = sanitizeFilename($origName);
    if ($origName === '' || strlen($origName) > 255) {
        throw new RuntimeException('Ungültiger Dateiname.', 400);
    }

    // Endung prüfen
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        throw new RuntimeException("Dateiendung '.$ext' ist nicht erlaubt.", 400);
    }

    // E-Mail validieren
    $emailRecipient = null;
    if ($email !== '') {
        $emailRecipient = filter_var($email, FILTER_VALIDATE_EMAIL);
        if ($emailRecipient === false) {
            throw new RuntimeException('Ungültige E-Mail-Adresse.', 400);
        }
    }

    // ── Erster Chunk: Auth + Rate-Limit ─────────────────────────────────────
    if ($chunkIndex === 0) {
        // Upload-Authentifizierung (falls UPLOAD_TOKEN konfiguriert)
        if (UPLOAD_TOKEN !== '') {
            $submitted = $_POST['upload_token'] ?? '';
            if (!hash_equals(UPLOAD_TOKEN, $submitted)) {
                throw new RuntimeException('Ungültiger Zugangscode.', 401);
            }
        }

        // Rate Limiting
        $clientIp = getClientIp() ?? '0.0.0.0';
        checkRateLimit($pdo, $clientIp);
    }

    // ── Chunk prüfen & speichern ─────────────────────────────────────────────
    if (
        !isset($_FILES['chunk']) ||
        $_FILES['chunk']['error'] !== UPLOAD_ERR_OK ||
        !is_uploaded_file($_FILES['chunk']['tmp_name'])
    ) {
        $errCode = $_FILES['chunk']['error'] ?? -1;
        throw new RuntimeException('Chunk-Upload fehlgeschlagen (PHP-Error: ' . $errCode . ').', 400);
    }

    $chunkDir  = TEMP_DIR . $fileId;
    $chunkFile = $chunkDir . '/chunk_' . $chunkIndex;

    if (!is_dir($chunkDir)) {
        if (!mkdir($chunkDir, 0750, true)) {
            throw new RuntimeException('Temp-Verzeichnis konnte nicht erstellt werden.', 500);
        }
        // Direktzugriff verhindern
        file_put_contents($chunkDir . '/.htaccess', "Require all denied\n");
    }

    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
        throw new RuntimeException('Chunk konnte nicht gespeichert werden.', 500);
    }

    // ── Prüfen ob alle Chunks angekommen sind ────────────────────────────────
    $received = count(glob($chunkDir . '/chunk_*'));

    if ($received < $totalChunks) {
        echo json_encode([
            'success'  => true,
            'complete' => false,
            'received' => $received,
            'total'    => $totalChunks,
        ]);
        return;
    }

    // ── Datei zusammenfügen & finalisieren ───────────────────────────────────
    $result = assembleAndStore($pdo, $chunkDir, $fileId, $totalChunks, $origName, $ext, $totalSize, $emailRecipient, $linkPw);

    echo json_encode([
        'success'      => true,
        'complete'     => true,
        'token'        => $result['token'],
        'download_url' => APP_URL . '/download.php?token=' . $result['token'],
        'expiry'       => $result['expiry'],
    ]);
}

// ── Hilfsfunktionen ──────────────────────────────────────────────────────────

/**
 * Chunks zusammenführen, MIME validieren, in uploads/ ablegen, DB-Eintrag erstellen.
 */
function assembleAndStore(
    PDO     $pdo,
    string  $chunkDir,
    string  $fileId,
    int     $totalChunks,
    string  $origName,
    string  $ext,
    int     $totalSize,
    ?string $emailRecipient,
    string  $linkPassword = ''
): array {
    // Alle Chunks in Reihenfolge zusammenführen
    // tempnam im eigenen TEMP_DIR (nicht im globalen /tmp des OS)
    $tmpAssembled = tempnam(TEMP_DIR, 'asm_');
    if ($tmpAssembled === false) {
        throw new RuntimeException('Temp-Datei konnte nicht erstellt werden.', 500);
    }

    try {
        $out = fopen($tmpAssembled, 'wb');
        if ($out === false) {
            throw new RuntimeException('Assembled-File konnte nicht geöffnet werden.', 500);
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $chunkDir . '/chunk_' . $i;
            if (!file_exists($chunkFile)) {
                fclose($out);
                throw new RuntimeException("Chunk $i fehlt – Upload unvollständig.", 400);
            }

            $in = fopen($chunkFile, 'rb');
            if ($in === false) {
                fclose($out);
                throw new RuntimeException("Chunk $i konnte nicht gelesen werden.", 500);
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
        }

        fclose($out);

        // Dateigröße validieren (Toleranz: ±1 KB wegen Encoding-Overhead)
        $assembledSize = filesize($tmpAssembled);
        if (abs($assembledSize - $totalSize) > 1024) {
            throw new RuntimeException('Zusammengeführte Datei hat unerwartete Größe.', 400);
        }

        // MIME-Type der tatsächlichen Datei prüfen (nicht vom Client übernehmen)
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpAssembled);

        if ($mimeType === false || !in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException("MIME-Typ '$mimeType' ist nicht erlaubt.", 400);
        }

        // Kreuz-Validierung: MIME-Typ muss zur Dateiendung passen
        $mimeExtMap = [
            'image/jpeg'    => ['jpg', 'jpeg'],
            'image/png'     => ['png'],
            'image/gif'     => ['gif'],
            'image/webp'    => ['webp'],
            'image/svg+xml' => ['svg'],
            'application/pdf'    => ['pdf'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => ['xlsx'],
            'application/vnd.ms-powerpoint' => ['ppt'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
            'text/plain'    => ['txt'],
            'text/csv'      => ['csv'],
            'application/zip'           => ['zip'],
            'application/x-zip-compressed' => ['zip'],
            'application/x-rar-compressed' => ['rar'],
            'application/x-7z-compressed'  => ['7z'],
            'application/x-tar'            => ['tar'],
            'application/gzip'             => ['gz'],
            'audio/mpeg'    => ['mp3'],
            'audio/wav'     => ['wav'],
            'audio/ogg'     => ['ogg'],
            'audio/mp4'     => ['m4a'],
            'video/mp4'     => ['mp4'],
            'video/webm'    => ['webm'],
            'video/ogg'     => ['ogv'],
            'video/quicktime' => ['mov'],
        ];
        if (isset($mimeExtMap[$mimeType]) && !in_array($ext, $mimeExtMap[$mimeType], true)) {
            throw new RuntimeException(
                "Dateiendung '.$ext' stimmt nicht mit dem erkannten Typ '$mimeType' überein.", 400
            );
        }

        // Sicherer, zufälliger Dateiname (kein Zusammenhang zum Original)
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath   = UPLOAD_DIR . $storedName;

        if (!rename($tmpAssembled, $destPath)) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.', 500);
        }

        // Dateiberechtigungen: Nur lesen (kein Ausführen)
        chmod($destPath, 0640);

        // Download-Token erzeugen
        $token  = bin2hex(random_bytes(32));
        $expiry = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . EXPIRY_DAYS . ' days')
            ->format('Y-m-d H:i:s');

        // IP-Adresse speichern
        $rawIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ip    = filter_var(explode(',', $rawIp)[0], FILTER_VALIDATE_IP) ? trim(explode(',', $rawIp)[0]) : null;

        // Passwort-Hash (null wenn kein Passwort gesetzt)
        $linkPasswordHash = ($linkPassword !== '')
            ? password_hash($linkPassword, PASSWORD_BCRYPT)
            : null;

        // DB-Eintrag
        $stmt = $pdo->prepare(
            'INSERT INTO files
                (original_name, stored_name, mime_type, file_size, token, expiry,
                 email_recipient, link_password_hash, ip_address)
             VALUES
                (:orig, :stored, :mime, :size, :token, :expiry,
                 :email, :pw_hash, INET6_ATON(:ip))'
        );
        $stmt->execute([
            ':orig'    => $origName,
            ':stored'  => $storedName,
            ':mime'    => $mimeType,
            ':size'    => $assembledSize,
            ':token'   => $token,
            ':expiry'  => $expiry,
            ':email'   => $emailRecipient,
            ':pw_hash' => $linkPasswordHash,
            ':ip'      => $ip,
        ]);

        // Chunks-Verzeichnis aufräumen
        cleanupChunkDir($chunkDir);

        // Benachrichtigungs-E-Mail an Uploader (silent – Upload schlägt nicht fehl)
        if ($emailRecipient !== null && function_exists('sendUploaderNotification')) {
            try {
                sendUploaderNotification($emailRecipient, $origName, $assembledSize, $token, $expiry);
            } catch (Throwable) {
                error_log('UploadEz: Uploader-Benachrichtigung fehlgeschlagen für ' . $emailRecipient);
            }
        }

        return ['token' => $token, 'expiry' => $expiry];

    } catch (Throwable $e) {
        // Temp-Datei entfernen
        if (file_exists($tmpAssembled)) {
            @unlink($tmpAssembled);
        }
        // Chunk-Verzeichnis ebenfalls bereinigen (verhindert verwaiste Dateien)
        if (is_dir($chunkDir)) {
            cleanupChunkDir($chunkDir);
        }
        throw $e;
    }
}

/**
 * Dateinamen bereinigen: Pfadteile entfernen, nur sichere Zeichen erlauben.
 */
function sanitizeFilename(string $name): string
{
    // Pfadteile entfernen
    $name = basename($name);
    // Nur alphanumerisch, Leerzeichen, Punkt, Bindestrich, Unterstrich
    $name = preg_replace('/[^\w\s.\-]/u', '', $name);
    // Mehrfach-Punkte kollabieren (verhindert doppelte Endung wie .php.jpg)
    $name = preg_replace('/\.{2,}/', '.', $name);
    return trim($name);
}

/**
 * Chunk-Verzeichnis nach erfolgreicher Assemblierung löschen.
 */
function cleanupChunkDir(string $dir): void
{
    foreach (glob($dir . '/*') as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}
