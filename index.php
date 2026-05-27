<?php
declare(strict_types=1);

/**
 * UploadEz – Haupt-Einstiegspunkt
 *
 * Dient als:
 *  • API-Endpunkt  (GET/POST ?action=...)
 *  • Frontend-HTML (kein action-Parameter)
 */

// php.ini-Empfehlungen (alternativ via .htaccess php_value):
//   upload_max_filesize = 2G
//   post_max_size       = 2G
//   max_execution_time  = 3600
//   memory_limit        = 256M

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/uploader.php';
require_once __DIR__ . '/includes/mailer.php';

// ── API-Routing ──────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== '') {
    // Kein HTML-Output für API-Anfragen
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    try {
        $pdo = getDb();

        switch ($action) {
            case 'upload_chunk':
                handleUploadChunk($pdo);
                break;
            case 'send_email':
                handleSendEmail($pdo);
                break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion.']);
        }
    } catch (RuntimeException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('UploadEz unhandled: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Interner Serverfehler.']);
    }
    exit;
}

// ── Frontend-HTML ─────────────────────────────────────────────────────────────
// Ab hier: HTML-Ausgabe
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dateien sicher hochladen und per Link teilen – bis zu 2 GB.">
    <title>UploadEz · Sicheres File-Sharing</title>
    <link rel="icon" href="/public/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Zilla+Slab:wght@700&family=Inter:wght@400;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* ── nozilla CI Tokens ───────────────────────────────────────────── */
        :root {
            --nz-green:        #00FF9C;
            --nz-green-strong: #00E88D;
            --nz-green-soft:   #B7FFE0;
            --nz-paper:        #FFFEE5;
            --nz-paper-alt:    #FAF8D4;
            --nz-paper-deep:   #F4F1C4;
            --nz-ink:          #000000;
            --nz-ink-70:       rgba(0,0,0,.72);
            --nz-ink-50:       rgba(0,0,0,.50);
            --nz-ink-20:       rgba(0,0,0,.18);
            --nz-error:        #FF5F1F;
            --nz-shadow:       6px 6px 0 0 #000;
            --nz-shadow-sm:    3px 3px 0 0 #000;
            --nz-dur:          160ms cubic-bezier(.2,0,.0,1);
            --nz-font-display: 'Zilla Slab', Georgia, serif;
            --nz-font-body:    'Inter', system-ui, sans-serif;
            --nz-font-mono:    'Space Mono', 'Courier New', monospace;
        }

        /* ── Reset ───────────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            font-family: var(--nz-font-body);
            background: var(--nz-paper);
            color: var(--nz-ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Header ──────────────────────────────────────────────────────── */
        .page-header {
            background: var(--nz-ink);
            padding: 20px 28px;
            border-bottom: 2px solid var(--nz-ink);
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .page-header .logo {
            font-family: var(--nz-font-display);
            font-size: 2rem;
            font-weight: 700;
            color: var(--nz-paper);
            letter-spacing: -0.02em;
            line-height: 1;
        }

        .page-header .logo span { color: var(--nz-green); }

        .page-header p {
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(255,254,229,.5);
        }

        /* ── Layout ──────────────────────────────────────────────────────── */
        main {
            flex: 1;
            max-width: 680px;
            width: 100%;
            margin: 40px auto;
            padding: 0 16px;
        }

        /* ── Card ────────────────────────────────────────────────────────── */
        .card {
            background: #fff;
            border: 2px solid var(--nz-ink);
            box-shadow: var(--nz-shadow);
        }

        .card-body { padding: 32px; }

        /* ── Drop Zone ───────────────────────────────────────────────────── */
        .drop-zone {
            border: 2px dashed var(--nz-ink);
            padding: 48px 24px;
            text-align: center;
            cursor: pointer;
            background: var(--nz-paper-alt);
            transition: background var(--nz-dur);
            position: relative;
        }

        .drop-zone:hover,
        .drop-zone.drag-over {
            background: var(--nz-green-soft);
            border-style: solid;
        }

        .drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .drop-zone .drop-icon { font-size: 2.5rem; display: block; margin-bottom: 12px; }

        .drop-zone .drop-title {
            font-family: var(--nz-font-display);
            font-size: 1.1rem;
            font-weight: 700;
        }

        .drop-zone .drop-sub {
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-top: 8px;
        }

        .drop-zone.has-file { background: var(--nz-green-soft); border-style: solid; }

        /* ── File Info ───────────────────────────────────────────────────── */
        .file-info {
            display: none;
            align-items: center;
            gap: 14px;
            margin-top: 16px;
            padding: 12px 16px;
            background: var(--nz-paper-alt);
            border: 2px solid var(--nz-ink);
        }

        .file-info.visible { display: flex; }
        .file-icon { font-size: 2rem; flex-shrink: 0; }
        .file-details { min-width: 0; flex: 1; }

        .file-name {
            font-weight: 600;
            font-size: .9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-size {
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.08em;
            color: var(--nz-ink-70);
            margin-top: 3px;
        }

        .file-remove {
            background: none;
            border: 2px solid var(--nz-ink);
            cursor: pointer;
            color: var(--nz-ink);
            font-size: 1rem;
            padding: 4px 8px;
            font-family: var(--nz-font-mono);
            transition: background var(--nz-dur);
        }

        .file-remove:hover { background: var(--nz-error); color: #fff; border-color: var(--nz-error); }

        /* ── Form ────────────────────────────────────────────────────────── */
        .form-group { margin-top: 20px; }

        label {
            display: block;
            font-family: var(--nz-font-mono);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-bottom: 8px;
        }

        .input-wrap { position: relative; }

        .input-wrap .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1rem;
            pointer-events: none;
        }

        input[type="email"],
        input[type="password"]:not(.admin-pw) {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 2px solid var(--nz-ink);
            border-radius: 0;
            font-size: .95rem;
            font-family: var(--nz-font-body);
            color: var(--nz-ink);
            background: #fff;
            outline: none;
            -webkit-appearance: none;
            transition: box-shadow var(--nz-dur);
        }

        input[type="email"]:focus,
        input[type="password"]:not(.admin-pw):focus {
            box-shadow: var(--nz-shadow-sm);
        }

        .input-hint {
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.06em;
            color: var(--nz-ink-50);
            margin-top: 6px;
        }

        /* ── Progress Bar ────────────────────────────────────────────────── */
        .progress-wrap { display: none; margin-top: 20px; }
        .progress-wrap.visible { display: block; }

        .progress-header {
            display: flex;
            justify-content: space-between;
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-bottom: 8px;
        }

        .progress-bar-bg {
            height: 16px;
            background: var(--nz-paper-deep);
            border: 2px solid var(--nz-ink);
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            width: 0%;
            background: var(--nz-green);
            transition: width .3s ease;
        }

        .progress-meta {
            display: flex;
            justify-content: space-between;
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.08em;
            color: var(--nz-ink-70);
            margin-top: 6px;
            min-height: 1.2em;
        }

        /* ── Buttons ─────────────────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border: 2px solid var(--nz-ink);
            font-size: .95rem;
            font-weight: 600;
            font-family: var(--nz-font-body);
            cursor: pointer;
            text-decoration: none;
            transition: box-shadow var(--nz-dur);
        }

        .btn:active:not(:disabled) { transform: translate(3px,3px); box-shadow: none !important; }

        .btn:disabled { opacity: .45; cursor: not-allowed; }

        .btn-primary {
            background: var(--nz-green);
            color: var(--nz-ink);
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            font-size: 1rem;
            font-weight: 700;
            box-shadow: var(--nz-shadow);
        }

        .btn-primary:hover:not(:disabled) { background: var(--nz-green-strong); }

        .btn-secondary {
            background: var(--nz-paper-alt);
            color: var(--nz-ink);
            box-shadow: var(--nz-shadow-sm);
        }

        .btn-secondary:hover:not(:disabled) { background: var(--nz-paper-deep); }

        /* ── Result Panel ────────────────────────────────────────────────── */
        #result-panel { display: none; }

        .result-success-icon { text-align: center; font-size: 3rem; margin-bottom: 8px; }

        .result-title {
            font-family: var(--nz-font-display);
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .result-sub {
            font-family: var(--nz-font-mono);
            text-align: center;
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-bottom: 24px;
        }

        .link-box {
            display: flex;
            border: 2px solid var(--nz-ink);
            background: var(--nz-paper-alt);
            align-items: stretch;
        }

        .link-box input {
            flex: 1;
            border: none;
            background: none;
            font-size: .82rem;
            color: var(--nz-ink);
            outline: none;
            min-width: 0;
            font-family: var(--nz-font-mono);
            padding: 10px 12px;
        }

        .btn-copy {
            flex-shrink: 0;
            background: var(--nz-ink);
            color: var(--nz-paper);
            border: none;
            border-left: 2px solid var(--nz-ink);
            padding: 10px 16px;
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--nz-dur);
        }

        .btn-copy:hover { background: #333; }
        .btn-copy.copied { background: var(--nz-green); color: var(--nz-ink); }

        .expiry-note {
            font-family: var(--nz-font-mono);
            text-align: center;
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-top: 10px;
        }

        /* ── E-Mail Section ──────────────────────────────────────────────── */
        .email-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--nz-ink-20);
        }

        .email-section h3 {
            font-family: var(--nz-font-mono);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-bottom: 12px;
        }

        .email-row { display: flex; }

        .email-row input[type="email"] {
            padding-left: 14px;
            border-right: none;
        }

        .btn-send-email {
            flex-shrink: 0;
            padding: 11px 18px;
            background: var(--nz-ink);
            color: var(--nz-paper);
            border: 2px solid var(--nz-ink);
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: opacity var(--nz-dur);
        }

        .btn-send-email:hover { opacity: .8; }
        .btn-send-email:disabled { opacity: .4; cursor: not-allowed; }

        /* ── Alert ───────────────────────────────────────────────────────── */
        .alert {
            display: none;
            padding: 12px 16px;
            font-size: .88rem;
            margin-top: 16px;
            font-weight: 500;
            border: 2px solid var(--nz-ink);
        }

        .alert.visible { display: block; }
        .alert-error   { background: #FFD5C5; }
        .alert-success { background: var(--nz-green-soft); }
        .alert-info    { background: var(--nz-paper-deep); }

        /* ── Info Bar ────────────────────────────────────────────────────── */
        .info-bar {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            border-top: 2px solid var(--nz-ink);
        }

        .info-bar-item {
            background: var(--nz-paper-deep);
            padding: 16px;
            text-align: center;
            border-right: 2px solid var(--nz-ink);
        }

        .info-bar-item:last-child { border-right: none; }
        .info-bar-item .ib-icon { font-size: 1.4rem; }

        .info-bar-item .ib-label {
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-top: 6px;
        }

        /* ── Footer ──────────────────────────────────────────────────────── */
        footer {
            text-align: center;
            padding: 20px;
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--nz-ink-50);
            border-top: 2px solid var(--nz-ink-20);
            margin-top: 40px;
        }

        /* ── Spinner ─────────────────────────────────────────────────────── */
        @keyframes spin { to { transform: rotate(360deg); } }

        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2.5px solid rgba(0,0,0,.2);
            border-top-color: var(--nz-ink);
            animation: spin .7s linear infinite;
        }

        /* ── Responsive ──────────────────────────────────────────────────── */
        @media (max-width: 520px) {
            .card-body { padding: 20px; }
            .drop-zone { padding: 32px 16px; }
            .info-bar { grid-template-columns: 1fr; }
            .info-bar-item { border-right: none; border-bottom: 2px solid var(--nz-ink); }
            .info-bar-item:last-child { border-bottom: none; }
            .email-row { flex-direction: column; }
            .email-row input[type="email"] { border-right: 2px solid var(--nz-ink); border-bottom: none; }
        }
    </style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<header class="page-header">
    <div class="logo">Upload<span>Ez</span></div>
    <p>Dateien sicher hochladen und per Link teilen · bis zu 2 GB</p>
</header>

<!-- ── Hauptinhalt ──────────────────────────────────────────────────────────── -->
<main>
    <div class="card">
        <div class="card-body">

            <!-- Upload-Formular -->
            <div id="upload-panel">

                <!-- Drag-and-Drop-Zone -->
                <div class="drop-zone" id="drop-zone" role="button" aria-label="Datei auswählen oder hierher ziehen">
                    <input type="file" id="file-input" aria-label="Datei auswählen">
                    <span class="drop-icon" aria-hidden="true">📂</span>
                    <div class="drop-title">Datei hierher ziehen oder klicken</div>
                    <div class="drop-sub">Erlaubt: Bilder, PDF, Office, Audio, Video, Archive · Max. 2 GB</div>
                </div>

                <!-- Datei-Info -->
                <div class="file-info" id="file-info">
                    <span class="file-icon" id="file-type-icon" aria-hidden="true">📄</span>
                    <div class="file-details">
                        <div class="file-name" id="file-name-display"></div>
                        <div class="file-size" id="file-size-display"></div>
                    </div>
                    <button class="file-remove" id="file-remove-btn" title="Datei entfernen" aria-label="Datei entfernen">✕</button>
                </div>

                <!-- E-Mail (optional) -->
                <div class="form-group">
                    <label for="email-input-upload">E-Mail (optional)</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">✉️</span>
                        <input type="email" id="email-input-upload"
                               placeholder="empfaenger@beispiel.de"
                               autocomplete="email">
                    </div>
                    <div class="input-hint">Der Download-Link wird nach dem Upload direkt an diese Adresse gesendet.</div>
                </div>

                <?php if (UPLOAD_TOKEN !== ''): ?>
                <!-- Zugangscode (nur wenn UPLOAD_TOKEN konfiguriert) -->
                <div class="form-group">
                    <label for="upload-token-input">Zugangscode <span style="color:var(--nz-error)">*</span></label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">🔑</span>
                        <input type="password" id="upload-token-input"
                               placeholder="Zugangscode eingeben"
                               autocomplete="off" required>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Link mit Passwort schützen (optional) -->
                <div class="form-group">
                    <label for="link-password-input">Link-Passwort (optional)</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">🔒</span>
                        <input type="password" id="link-password-input"
                               placeholder="Leer lassen für öffentlichen Link"
                               autocomplete="new-password">
                    </div>
                    <div class="input-hint">Empfänger müssen dieses Passwort eingeben, um die Datei herunterzuladen.</div>
                </div>

                <!-- Fortschrittsanzeige -->
                <div class="progress-wrap" id="progress-wrap">
                    <div class="progress-header">
                        <span id="progress-label">Hochladen…</span>
                        <span id="progress-pct">0%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" id="progress-fill"></div>
                    </div>
                    <div class="progress-meta">
                        <span id="progress-speed"></span>
                        <span id="progress-eta"></span>
                    </div>
                </div>

                <!-- Fehlermeldung -->
                <div class="alert alert-error" id="upload-error" role="alert"></div>

                <!-- Upload-Button -->
                <button class="btn btn-primary" id="upload-btn" disabled>
                    <span id="upload-btn-text">Datei auswählen</span>
                </button>

            </div><!-- /#upload-panel -->

            <!-- Ergebnis-Panel (nach erfolgreichem Upload) -->
            <div id="result-panel">
                <div class="result-success-icon" aria-hidden="true">✅</div>
                <div class="result-title">Upload erfolgreich!</div>
                <div class="result-sub" id="result-sub">Dein Datei ist hochgeladen und bereit zum Teilen.</div>

                <!-- Link-Box -->
                <div class="link-box">
                    <input type="text" id="download-link" readonly aria-label="Download-Link">
                    <button class="btn-copy" id="copy-btn" aria-label="Link kopieren">Kopieren</button>
                </div>
                <div class="expiry-note" id="expiry-note"></div>

                <!-- E-Mail versenden -->
                <div class="email-section">
                    <h3>Link per E-Mail senden</h3>
                    <div class="email-row">
                        <input type="email" id="email-result"
                               placeholder="empfaenger@beispiel.de"
                               autocomplete="email"
                               aria-label="E-Mail-Adresse für Link-Versand">
                        <button class="btn-send-email" id="send-email-btn">Senden</button>
                    </div>
                    <div class="alert alert-success" id="email-success" role="status"></div>
                    <div class="alert alert-error"   id="email-error"   role="alert"></div>
                </div>

                <!-- Neuer Upload -->
                <button class="btn btn-secondary" id="new-upload-btn" style="margin-top:20px;width:100%;">
                    ↩ Neuen Upload starten
                </button>
            </div><!-- /#result-panel -->

        </div><!-- /.card-body -->

        <!-- Info-Leiste -->
        <div class="info-bar" aria-label="Features">
            <div class="info-bar-item">
                <div class="ib-icon" aria-hidden="true">🔒</div>
                <div class="ib-label">Verschlüsselte Übertragung</div>
            </div>
            <div class="info-bar-item">
                <div class="ib-icon" aria-hidden="true">⏱️</div>
                <div class="ib-label">Link läuft nach <?= EXPIRY_DAYS ?> Tagen ab</div>
            </div>
            <div class="info-bar-item">
                <div class="ib-icon" aria-hidden="true">🗂️</div>
                <div class="ib-label">Bis zu 2 GB</div>
            </div>
        </div>

    </div><!-- /.card -->
</main>

<footer>
    &copy; <?= date('Y') ?> UploadEz · Sicheres File-Sharing
</footer>

<script>
'use strict';

/* ── Konfiguration ─────────────────────────────────────────────────────────── */
const MAX_FILE_SIZE = <?= MAX_FILE_SIZE ?>;          // 2 GB
const CHUNK_SIZE    = <?= CHUNK_SIZE ?>;             // 5 MB
const ALLOWED_EXTS  = <?= json_encode(ALLOWED_EXTENSIONS) ?>;
const ALLOWED_MIME  = <?= json_encode(ALLOWED_MIME_TYPES) ?>;

/* ── DOM-Referenzen ────────────────────────────────────────────────────────── */
const dropZone          = document.getElementById('drop-zone');
const fileInput         = document.getElementById('file-input');
const fileInfo          = document.getElementById('file-info');
const fileNameDisplay   = document.getElementById('file-name-display');
const fileSizeDisplay   = document.getElementById('file-size-display');
const fileTypeIcon      = document.getElementById('file-type-icon');
const fileRemoveBtn     = document.getElementById('file-remove-btn');
const emailInputUpload  = document.getElementById('email-input-upload');
const uploadTokenInput  = document.getElementById('upload-token-input');
const linkPasswordInput = document.getElementById('link-password-input');
const progressWrap      = document.getElementById('progress-wrap');
const progressLabel     = document.getElementById('progress-label');
const progressPct       = document.getElementById('progress-pct');
const progressFill      = document.getElementById('progress-fill');
const progressSpeed     = document.getElementById('progress-speed');
const progressEta       = document.getElementById('progress-eta');
const uploadBtn         = document.getElementById('upload-btn');
const uploadBtnText     = document.getElementById('upload-btn-text');
const uploadError       = document.getElementById('upload-error');
const uploadPanel       = document.getElementById('upload-panel');
const resultPanel       = document.getElementById('result-panel');
const downloadLink      = document.getElementById('download-link');
const copyBtn           = document.getElementById('copy-btn');
const expiryNote        = document.getElementById('expiry-note');
const emailResult       = document.getElementById('email-result');
const sendEmailBtn      = document.getElementById('send-email-btn');
const emailSuccess      = document.getElementById('email-success');
const emailError        = document.getElementById('email-error');
const newUploadBtn      = document.getElementById('new-upload-btn');

/* ── Zustand ───────────────────────────────────────────────────────────────── */
let selectedFile  = null;
let uploadToken   = null;

/* ── Hilfsfunktionen ───────────────────────────────────────────────────────── */
function formatBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576)    return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024)       return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
}

function fileExtension(name) {
    return name.split('.').pop().toLowerCase();
}

function fileEmoji(mime) {
    if (mime.startsWith('image/'))  return '🖼️';
    if (mime.startsWith('video/'))  return '🎬';
    if (mime.startsWith('audio/'))  return '🎵';
    if (mime.includes('pdf'))       return '📕';
    if (mime.includes('zip') || mime.includes('rar') || mime.includes('7z') || mime.includes('tar')) return '📦';
    if (mime.includes('word') || mime.includes('document')) return '📝';
    if (mime.includes('excel') || mime.includes('sheet'))   return '📊';
    if (mime.includes('presentation') || mime.includes('powerpoint')) return '📊';
    return '📄';
}

/** UUID v4 im Browser erzeugen */
function generateUUID() {
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
}

function showAlert(el, message) {
    el.textContent = message;
    el.classList.add('visible');
}

function hideAlert(el) {
    el.classList.remove('visible');
    el.textContent = '';
}

/* ── Datei validieren (clientseitig, Vorprüfung) ───────────────────────────── */
function validateFile(file) {
    if (file.size === 0)            return 'Die Datei ist leer.';
    if (file.size > MAX_FILE_SIZE)  return `Die Datei ist zu groß (max. 2 GB). Deine Datei: ${formatBytes(file.size)}`;

    const ext = fileExtension(file.name);
    if (!ALLOWED_EXTS.includes(ext)) {
        return `Dateityp ".${ext}" ist nicht erlaubt.`;
    }
    return null;
}

/* ── Datei setzen ──────────────────────────────────────────────────────────── */
function setFile(file) {
    const err = validateFile(file);
    if (err) {
        showAlert(uploadError, err);
        return;
    }

    hideAlert(uploadError);
    selectedFile        = file;
    fileNameDisplay.textContent = file.name;
    fileSizeDisplay.textContent = formatBytes(file.size);
    fileTypeIcon.textContent    = fileEmoji(file.type || '');
    fileInfo.classList.add('visible');
    dropZone.classList.add('has-file');
    uploadBtn.disabled          = false;
    uploadBtnText.textContent   = 'Hochladen starten';
}

/* ── Datei entfernen ───────────────────────────────────────────────────────── */
function clearFile() {
    selectedFile = null;
    fileInput.value = '';
    fileInfo.classList.remove('visible');
    dropZone.classList.remove('has-file');
    uploadBtn.disabled        = true;
    uploadBtnText.textContent = 'Datei auswählen';
    hideAlert(uploadError);
    setProgress(0, '');
    progressSpeed.textContent = '';
    progressEta.textContent   = '';
    progressWrap.classList.remove('visible');
}

/* ── Fortschritt aktualisieren ─────────────────────────────────────────────── */
function setProgress(pct, label, speedBps, etaSec) {
    progressFill.style.width = pct + '%';
    progressPct.textContent  = Math.round(pct) + '%';
    if (label) progressLabel.textContent = label;

    // Geschwindigkeit
    if (speedBps != null && speedBps > 0) {
        progressSpeed.textContent = formatBytes(speedBps) + '/s';
    } else {
        progressSpeed.textContent = '';
    }

    // ETA
    if (etaSec != null && etaSec > 0 && etaSec < 86400) {
        progressEta.textContent = 'noch ~' + formatEta(etaSec);
    } else {
        progressEta.textContent = '';
    }
}

function formatEta(sec) {
    sec = Math.round(sec);
    if (sec < 60)   return sec + ' Sek.';
    if (sec < 3600) return Math.ceil(sec / 60) + ' Min.';
    return Math.ceil(sec / 3600) + ' Std.';
}

/* ── Drag-and-Drop ─────────────────────────────────────────────────────────── */
['dragenter', 'dragover'].forEach(evt =>
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.classList.add('drag-over');
    })
);

['dragleave', 'drop'].forEach(evt =>
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
    })
);

dropZone.addEventListener('drop', e => {
    const file = e.dataTransfer?.files?.[0];
    if (file) setFile(file);
});

fileInput.addEventListener('change', () => {
    if (fileInput.files?.[0]) setFile(fileInput.files[0]);
});

fileRemoveBtn.addEventListener('click', e => {
    e.stopPropagation();
    clearFile();
});

/* ── Chunked Upload ────────────────────────────────────────────────────────── */

const MAX_RETRIES    = 3;
const RETRY_DELAYS   = [1000, 2000, 4000]; // ms zwischen Versuchen

/**
 * Sendet einen einzelnen Chunk mit automatischem Retry bei Netzwerk-/Serverfehlern.
 * Gibt das geparste JSON-Objekt zurück oder wirft nach MAX_RETRIES Fehlern.
 */
async function sendChunk(fd, chunkIdx, totalChunks) {
    let lastErr;

    for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
        if (attempt > 0) {
            const delay = RETRY_DELAYS[attempt - 1];
            setProgress(
                (chunkIdx / totalChunks) * 100,
                `Chunk ${chunkIdx + 1}/${totalChunks}: Versuch ${attempt + 1}/${MAX_RETRIES + 1}…`
            );
            await new Promise(r => setTimeout(r, delay));
        }

        try {
            const response = await fetch('index.php', { method: 'POST', body: fd });

            if (!response.ok) {
                const txt = await response.text().catch(() => '');
                throw new Error(`Server-Fehler ${response.status}: ${txt.slice(0, 120)}`);
            }

            const data = await response.json().catch(() => {
                throw new Error('Ungültige Server-Antwort (kein JSON).');
            });

            if (!data.success) throw new Error(data.error || 'Unbekannter Fehler.');

            return data;

        } catch (err) {
            lastErr = err;
            // Sofort werfen wenn es ein fachlicher Fehler ist (kein Retry sinnvoll)
            if (err.message.startsWith('Server-Fehler 4')) throw err;
        }
    }

    throw new Error(`Upload fehlgeschlagen nach ${MAX_RETRIES + 1} Versuchen: ${lastErr?.message}`);
}

async function uploadFile(file, email, uploadToken = '', linkPassword = '') {
    const totalChunks  = Math.ceil(file.size / CHUNK_SIZE);
    const fileId       = generateUUID();
    let   lastResult   = null;
    let   bytesUploaded = 0;
    const startTime    = Date.now();

    progressWrap.classList.add('visible');
    setProgress(0, 'Vorbereitung…');

    for (let i = 0; i < totalChunks; i++) {
        const start     = i * CHUNK_SIZE;
        const end       = Math.min(start + CHUNK_SIZE, file.size);
        const chunkSize = end - start;
        const chunk     = file.slice(start, end);

        const fd = new FormData();
        fd.append('action',        'upload_chunk');
        fd.append('chunk',         chunk, file.name);
        fd.append('chunk_index',   String(i));
        fd.append('total_chunks',  String(totalChunks));
        fd.append('file_id',       fileId);
        fd.append('original_name', file.name);
        fd.append('total_size',    String(file.size));
        if (email) fd.append('email', email);
        // Zugangscode + Link-Passwort nur mit erstem Chunk senden
        if (i === 0) {
            if (uploadToken)  fd.append('upload_token',   uploadToken);
            if (linkPassword) fd.append('link_password',  linkPassword);
        }

        const label = totalChunks === 1
            ? 'Hochladen…'
            : `Chunk ${i + 1} von ${totalChunks}…`;

        const data = await sendChunk(fd, i, totalChunks);

        // ── ETA berechnen ────────────────────────────────────────────────────
        bytesUploaded += chunkSize;
        const elapsedSec = (Date.now() - startTime) / 1000;
        const speedBps   = elapsedSec > 0.5 ? bytesUploaded / elapsedSec : 0;
        const remaining  = file.size - bytesUploaded;
        const etaSec     = speedBps > 0 ? remaining / speedBps : null;
        const pct        = (bytesUploaded / file.size) * 100;

        setProgress(pct, label, speedBps || null, etaSec);
        lastResult = data;

        if (data.complete) break;
    }

    return lastResult;
}

/* ── Upload-Button ─────────────────────────────────────────────────────────── */
uploadBtn.addEventListener('click', async () => {
    if (!selectedFile) return;

    const email        = emailInputUpload.value.trim();
    const uploadToken  = uploadTokenInput  ? uploadTokenInput.value.trim()  : '';
    const linkPassword = linkPasswordInput ? linkPasswordInput.value        : '';

    uploadBtn.disabled        = true;
    uploadBtnText.innerHTML   = '<span class="spinner"></span> Wird übertragen…';
    hideAlert(uploadError);

    try {
        const result = await uploadFile(selectedFile, email, uploadToken, linkPassword);

        if (!result?.token || !result?.download_url) {
            throw new Error('Upload erfolgreich, aber kein Download-Link erhalten.');
        }

        setProgress(100, 'Abgeschlossen ✓');
        uploadToken = result.token;

        // Ergebnis anzeigen
        uploadPanel.style.display = 'none';
        resultPanel.style.display = 'block';
        downloadLink.value = result.download_url;

        if (result.expiry) {
            const d = new Date(result.expiry + 'Z');
            expiryNote.textContent = `⚠️ Dieser Link läuft am ${d.toLocaleDateString('de-DE')} ab.`;
        }

        // E-Mail-Feld vorausfüllen
        if (email) emailResult.value = email;

    } catch (err) {
        showAlert(uploadError, err.message || 'Upload fehlgeschlagen.');
        uploadBtn.disabled      = false;
        uploadBtnText.textContent = 'Erneut versuchen';
        setProgress(0, '');
        progressWrap.classList.remove('visible');
    }
});

/* ── Link kopieren ─────────────────────────────────────────────────────────── */
copyBtn.addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText(downloadLink.value);
    } catch {
        downloadLink.select();
        document.execCommand('copy');
    }
    copyBtn.textContent = '✓ Kopiert!';
    copyBtn.classList.add('copied');
    setTimeout(() => {
        copyBtn.textContent = 'Kopieren';
        copyBtn.classList.remove('copied');
    }, 2000);
});

/* ── E-Mail versenden ──────────────────────────────────────────────────────── */
sendEmailBtn.addEventListener('click', async () => {
    const email = emailResult.value.trim();
    hideAlert(emailSuccess);
    hideAlert(emailError);

    if (!email) {
        showAlert(emailError, 'Bitte eine E-Mail-Adresse eingeben.');
        return;
    }

    if (!uploadToken) {
        showAlert(emailError, 'Kein gültiger Upload-Token. Bitte Seite neu laden.');
        return;
    }

    sendEmailBtn.disabled  = true;
    sendEmailBtn.textContent = '⏳ Senden…';

    try {
        const fd = new FormData();
        fd.append('action', 'send_email');
        fd.append('token',  uploadToken);
        fd.append('email',  email);

        const res  = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Unbekannter Fehler.');

        showAlert(emailSuccess, `✓ E-Mail erfolgreich an ${email} gesendet!`);
        sendEmailBtn.textContent = '✓ Gesendet';

    } catch (err) {
        showAlert(emailError, err.message || 'E-Mail konnte nicht gesendet werden.');
        sendEmailBtn.disabled    = false;
        sendEmailBtn.textContent = 'Senden';
    }
});

/* ── Neuer Upload ──────────────────────────────────────────────────────────── */
newUploadBtn.addEventListener('click', () => {
    resultPanel.style.display = 'none';
    uploadPanel.style.display = 'block';
    uploadToken = null;
    clearFile();
    emailInputUpload.value = '';
    hideAlert(emailSuccess);
    hideAlert(emailError);
});
</script>

</body>
</html>
