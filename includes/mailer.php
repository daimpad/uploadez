<?php
declare(strict_types=1);

/**
 * UploadEz – E-Mail-Handler
 *
 * Bevorzugt PHPMailer (composer require phpmailer/phpmailer).
 * Fallback: PHP-internes mail().
 */

function handleSendEmail(PDO $pdo): void
{
    $token = trim($_POST['token'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Token validieren
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        throw new RuntimeException('Ungültiger Token.', 400);
    }

    // E-Mail validieren
    $recipient = filter_var($email, FILTER_VALIDATE_EMAIL);
    if ($recipient === false) {
        throw new RuntimeException('Ungültige E-Mail-Adresse.', 400);
    }

    // Datei-Eintrag laden
    $stmt = $pdo->prepare(
        'SELECT original_name, file_size, expiry FROM files
         WHERE token = :token AND expiry > NOW()'
    );
    $stmt->execute([':token' => $token]);
    $file = $stmt->fetch();

    if ($file === false) {
        throw new RuntimeException('Token nicht gefunden oder bereits abgelaufen.', 404);
    }

    $downloadUrl = APP_URL . '/download.php?token=' . $token;
    $expiryDate  = (new DateTimeImmutable($file['expiry']))->format('d.m.Y');
    $fileSize    = formatBytes((int)$file['file_size']);
    $fileName    = htmlspecialchars($file['original_name'], ENT_QUOTES, 'UTF-8');

    $subject = 'Dein Download-Link für: ' . $file['original_name'];

    $bodyHtml = <<<HTML
    <!DOCTYPE html>
    <html lang="de">
    <head><meta charset="UTF-8"><title>Dein Download-Link</title></head>
    <body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;">
      <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
        <div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:30px;text-align:center;">
          <h1 style="color:#fff;margin:0;font-size:24px;">📁 UploadEz</h1>
          <p style="color:rgba(255,255,255,.85);margin:8px 0 0;">Deine Datei steht bereit</p>
        </div>
        <div style="padding:30px;">
          <p style="color:#334155;font-size:15px;">Hallo,</p>
          <p style="color:#334155;font-size:15px;">
            eine Datei wurde für dich hochgeladen und kann über den folgenden Link heruntergeladen werden:
          </p>
          <table style="width:100%;border-collapse:collapse;margin:20px 0;background:#f8fafc;border-radius:6px;">
            <tr><td style="padding:10px 14px;color:#64748b;font-size:13px;">Dateiname</td>
                <td style="padding:10px 14px;color:#1e293b;font-size:13px;font-weight:600;">{$fileName}</td></tr>
            <tr style="background:#f1f5f9;">
                <td style="padding:10px 14px;color:#64748b;font-size:13px;">Größe</td>
                <td style="padding:10px 14px;color:#1e293b;font-size:13px;">{$fileSize}</td></tr>
            <tr><td style="padding:10px 14px;color:#64748b;font-size:13px;">Gültig bis</td>
                <td style="padding:10px 14px;color:#1e293b;font-size:13px;">{$expiryDate}</td></tr>
          </table>
          <div style="text-align:center;margin:28px 0;">
            <a href="{$downloadUrl}"
               style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;font-weight:600;">
              Datei herunterladen
            </a>
          </div>
          <p style="color:#94a3b8;font-size:12px;text-align:center;margin-top:20px;">
            Dieser Link läuft am {$expiryDate} ab und kann danach nicht mehr genutzt werden.<br>
            Bitte leite diese E-Mail nur an vertrauenswürdige Personen weiter.
          </p>
        </div>
        <div style="background:#f8fafc;padding:16px;text-align:center;">
          <p style="color:#94a3b8;font-size:12px;margin:0;">UploadEz · Sicheres File-Sharing</p>
        </div>
      </div>
    </body>
    </html>
    HTML;

    $bodyText = "Dein Download-Link\n\n"
        . "Datei: {$file['original_name']}\n"
        . "Größe: {$fileSize}\n"
        . "Gültig bis: {$expiryDate}\n\n"
        . "Download-Link:\n{$downloadUrl}\n\n"
        . "Dieser Link läuft am {$expiryDate} ab.\n";

    $sent = sendMail($recipient, $subject, $bodyHtml, $bodyText);

    if (!$sent) {
        throw new RuntimeException('E-Mail konnte nicht gesendet werden.', 500);
    }

    // E-Mail-Empfänger in DB aktualisieren
    $pdo->prepare('UPDATE files SET email_recipient = :email WHERE token = :token')
        ->execute([':email' => $recipient, ':token' => $token]);

    echo json_encode(['success' => true, 'message' => 'E-Mail erfolgreich gesendet.']);
}

/**
 * Versendet eine E-Mail – bevorzugt via PHPMailer, sonst PHP mail().
 */
function sendMail(string $to, string $subject, string $htmlBody, string $textBody): bool
{
    // PHPMailer bevorzugen wenn vorhanden
    if (file_exists(BASE_DIR . '/vendor/autoload.php')) {
        return sendWithPhpMailer($to, $subject, $htmlBody, $textBody);
    }

    return sendWithMailFunction($to, $subject, $htmlBody, $textBody);
}

function sendWithPhpMailer(string $to, string $subject, string $htmlBody, string $textBody): bool
{
    require_once BASE_DIR . '/vendor/autoload.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->Subject  = $subject;
        $mail->Body     = $htmlBody;
        $mail->AltBody  = $textBody;
        $mail->isHTML(true);

        return $mail->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('PHPMailer-Fehler: ' . $e->getMessage());
        return false;
    }
}

function sendWithMailFunction(string $to, string $subject, string $htmlBody, string $textBody): bool
{
    $boundary = md5(uniqid('', true));

    // CRLF aus Konfigurationswerten entfernen (Header-Injection-Schutz)
    $safeName = preg_replace('/[\r\n]/', '', SMTP_FROM_NAME);
    $safeFrom = preg_replace('/[\r\n<>]/', '', SMTP_FROM);
    $from     = $safeName . ' <' . $safeFrom . '>';

    $headers  = "From: $from\r\n";
    $headers .= "Reply-To: $safeFrom\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $headers .= "X-Mailer: UploadEz\r\n";

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($textBody) . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($htmlBody) . "\r\n\r\n";
    $body .= "--$boundary--\r\n";

    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/**
 * Sendet eine Bestätigungs-E-Mail an den Uploader nach erfolgreichem Upload.
 */
function sendUploaderNotification(
    string $email,
    string $fileName,
    int    $fileSize,
    string $token,
    string $expiry
): bool {
    $downloadUrl  = APP_URL . '/download.php?token=' . $token;
    $expiryDate   = (new DateTimeImmutable($expiry, new DateTimeZone('UTC')))->format('d.m.Y');
    $fileSizeStr  = formatBytes($fileSize);
    $fileNameSafe = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');

    $subject = 'Upload erfolgreich: ' . $fileName;

    $bodyHtml = <<<HTML
    <!DOCTYPE html>
    <html lang="de">
    <head><meta charset="UTF-8"><title>Upload erfolgreich</title></head>
    <body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;">
      <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
        <div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:30px;text-align:center;">
          <h1 style="color:#fff;margin:0;font-size:24px;">✅ Upload erfolgreich</h1>
          <p style="color:rgba(255,255,255,.85);margin:8px 0 0;">Deine Datei wurde hochgeladen</p>
        </div>
        <div style="padding:30px;">
          <p style="color:#334155;font-size:15px;">Hallo,</p>
          <p style="color:#334155;font-size:15px;">
            deine Datei wurde erfolgreich hochgeladen. Hier ist dein persönlicher Download-Link zum Teilen:
          </p>
          <table style="width:100%;border-collapse:collapse;margin:20px 0;background:#f8fafc;border-radius:6px;">
            <tr><td style="padding:10px 14px;color:#64748b;font-size:13px;">Dateiname</td>
                <td style="padding:10px 14px;color:#1e293b;font-size:13px;font-weight:600;">{$fileNameSafe}</td></tr>
            <tr style="background:#f1f5f9;">
                <td style="padding:10px 14px;color:#64748b;font-size:13px;">Größe</td>
                <td style="padding:10px 14px;color:#1e293b;font-size:13px;">{$fileSizeStr}</td></tr>
            <tr><td style="padding:10px 14px;color:#64748b;font-size:13px;">Gültig bis</td>
                <td style="padding:10px 14px;color:#1e293b;font-size:13px;">{$expiryDate}</td></tr>
          </table>
          <div style="text-align:center;margin:28px 0;">
            <a href="{$downloadUrl}"
               style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;font-weight:600;">
              Download-Link öffnen
            </a>
          </div>
          <p style="color:#94a3b8;font-size:12px;text-align:center;margin-top:20px;">
            Teile diesen Link mit den Personen, die die Datei herunterladen sollen.<br>
            Der Link läuft am {$expiryDate} ab.
          </p>
        </div>
        <div style="background:#f8fafc;padding:16px;text-align:center;">
          <p style="color:#94a3b8;font-size:12px;margin:0;">UploadEz · Sicheres File-Sharing</p>
        </div>
      </div>
    </body>
    </html>
    HTML;

    $bodyText = "Upload erfolgreich!\n\n"
        . "Datei:      {$fileName}\n"
        . "Größe:      {$fileSizeStr}\n"
        . "Gültig bis: {$expiryDate}\n\n"
        . "Download-Link (zum Teilen):\n{$downloadUrl}\n\n"
        . "Der Link läuft am {$expiryDate} ab.\n";

    return sendMail($email, $subject, $bodyHtml, $bodyText);
}

/**
 * Bytes in lesbare Einheit umrechnen.
 */
function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}
