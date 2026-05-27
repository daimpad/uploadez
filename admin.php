<?php
declare(strict_types=1);

/**
 * UploadEz – Admin-Übersicht aller Uploads
 *
 * Zugang: Passwort-geschützt via Session.
 * Hash erzeugen: php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
 * Hash in .env hinterlegen: ADMIN_PASSWORD_HASH=...
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// ── Session starten ──────────────────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => ADMIN_SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$error       = '';
$isLoggedIn  = isset($_SESSION['uploadez_admin'])
               && $_SESSION['uploadez_admin'] === true
               && isset($_SESSION['uploadez_admin_ts'])
               && (time() - $_SESSION['uploadez_admin_ts']) < ADMIN_SESSION_LIFETIME;

// ── Abmelden ─────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ── CSRF-Token erzeugen / prüfen ──────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    $submitted = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $submitted);
}

// ── Einloggen ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $hash = ADMIN_PASSWORD_HASH;

    if ($hash === '') {
        $error = 'Kein Admin-Passwort konfiguriert. Bitte ADMIN_PASSWORD_HASH in .env setzen.';
    } elseif (password_verify($_POST['password'], $hash)) {
        session_regenerate_id(true);
        $_SESSION['uploadez_admin']    = true;
        $_SESSION['uploadez_admin_ts'] = time();
        $_SESSION['csrf_token']        = bin2hex(random_bytes(32));
        header('Location: admin.php');
        exit;
    } else {
        // Kurze Pause verhindert Brute-Force
        usleep(500_000);
        $error = 'Falsches Passwort.';
    }
}

// ── Datei löschen (POST-Action) ───────────────────────────────────────────────
$deleteMsg = '';
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_token'])) {
    if (!verifyCsrf()) {
        header('Location: admin.php?deleted=error');
        exit;
    }
    $delToken = trim($_POST['delete_token']);
    if (preg_match('/^[0-9a-f]{64}$/', $delToken)) {
        try {
            $pdo  = getDb();
            $stmt = $pdo->prepare('SELECT stored_name FROM files WHERE token = :t');
            $stmt->execute([':t' => $delToken]);
            $row  = $stmt->fetch();

            if ($row) {
                $path = UPLOAD_DIR . basename($row['stored_name']);
                if (file_exists($path)) @unlink($path);
                $pdo->prepare('DELETE FROM files WHERE token = :t')->execute([':t' => $delToken]);
                $deleteMsg = 'success';
            }
        } catch (Throwable $e) {
            $deleteMsg = 'error';
        }
    }
    header('Location: admin.php?deleted=' . $deleteMsg);
    exit;
}

// ── Daten laden (nur wenn eingeloggt) ────────────────────────────────────────
$files      = [];
$stats      = ['total' => 0, 'active' => 0, 'expired' => 0, 'total_size' => 0];
$sortCol    = in_array($_GET['sort'] ?? '', ['created_at', 'expiry', 'file_size', 'download_count', 'original_name'], true)
              ? $_GET['sort']
              : 'created_at';
$sortDir    = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$search     = trim($_GET['q'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 25;
$offset     = ($page - 1) * $perPage;
$totalPages = 1;

if ($isLoggedIn) {
    try {
        $pdo = getDb();

        // Suchparameter
        $whereClause = '';
        $params      = [];
        if ($search !== '') {
            $whereClause   = 'WHERE original_name LIKE :q OR email_recipient LIKE :q';
            $params[':q']  = '%' . $search . '%';
        }

        // Gesamtstatistik
        $s = $pdo->query('SELECT COUNT(*) AS total,
                                 SUM(file_size) AS total_size,
                                 SUM(expiry > NOW()) AS active,
                                 SUM(expiry <= NOW()) AS expired
                          FROM files');
        $stats = $s->fetch() + $stats;

        // Gesamtzahl für Paginierung
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM files $whereClause");
        $countStmt->execute($params);
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));

        // Datensätze
        $stmt = $pdo->prepare(
            "SELECT id, original_name, stored_name, mime_type, file_size,
                    token, expiry, email_recipient, download_count, created_at
             FROM files
             $whereClause
             ORDER BY $sortCol $sortDir
             LIMIT :limit OFFSET :offset"
        );
        $params[':limit']  = $perPage;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $files = $stmt->fetchAll();

    } catch (Throwable $e) {
        error_log('UploadEz admin DB-Fehler: ' . $e->getMessage());
    }
}

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────
function fmtBytes(int $b): string {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 2) . ' MB';
    if ($b >= 1024)       return round($b / 1024, 2) . ' KB';
    return $b . ' B';
}

function isExpired(string $expiry): bool {
    return new DateTimeImmutable($expiry, new DateTimeZone('UTC'))
           < new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function fileEmoji(string $mime): string {
    if (str_starts_with($mime, 'image/'))  return '🖼️';
    if (str_starts_with($mime, 'video/'))  return '🎬';
    if (str_starts_with($mime, 'audio/'))  return '🎵';
    if (str_contains($mime, 'pdf'))        return '📕';
    if (str_contains($mime, 'zip') || str_contains($mime, 'rar') || str_contains($mime, '7z')) return '📦';
    if (str_contains($mime, 'word') || str_contains($mime, 'document'))  return '📝';
    if (str_contains($mime, 'excel') || str_contains($mime, 'sheet'))    return '📊';
    return '📄';
}

function sortUrl(string $col): string {
    global $sortCol, $sortDir, $search, $page;
    $dir = ($sortCol === $col && $sortDir === 'DESC') ? 'asc' : 'desc';
    $q   = $search !== '' ? '&q=' . urlencode($search) : '';
    return "admin.php?sort=$col&dir=$dir$q";
}

function sortIcon(string $col): string {
    global $sortCol, $sortDir;
    if ($sortCol !== $col) return '<span style="opacity:.3">↕</span>';
    return $sortDir === 'ASC' ? '↑' : '↓';
}

$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UploadEz · Admin-Übersicht</title>
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

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: var(--nz-font-body); background: var(--nz-paper); color: var(--nz-ink); min-height: 100vh; }

        /* ── Header ─────────────────────────────────────────────────────── */
        .page-header {
            background: var(--nz-ink);
            padding: 20px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 2px solid var(--nz-ink);
        }
        .logo {
            font-family: var(--nz-font-display);
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--nz-paper);
            letter-spacing: -0.02em;
            line-height: 1;
        }
        .logo span { color: var(--nz-green); }
        .logo small {
            font-family: var(--nz-font-mono);
            font-size: 10px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            background: rgba(255,254,229,.12);
            border: 1px solid rgba(255,254,229,.25);
            padding: 2px 8px;
            margin-left: 10px;
            vertical-align: middle;
            color: rgba(255,254,229,.7);
        }
        .header-actions { display: flex; gap: 10px; align-items: center; }
        .btn-ghost {
            background: rgba(255,254,229,.08);
            border: 1.5px solid rgba(255,254,229,.25);
            color: var(--nz-paper);
            padding: 7px 16px;
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: background var(--nz-dur);
        }
        .btn-ghost:hover { background: rgba(255,254,229,.18); }

        /* ── Login ──────────────────────────────────────────────────────── */
        .login-wrap { display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 72px); padding: 20px; }
        .login-card {
            background: #fff;
            border: 2px solid var(--nz-ink);
            box-shadow: var(--nz-shadow);
            padding: 40px;
            width: 100%;
            max-width: 380px;
        }
        .login-card h2 { font-family: var(--nz-font-display); font-size: 1.6rem; margin-bottom: 6px; }
        .login-card p { font-family: var(--nz-font-mono); font-size: 11px; letter-spacing: 0.08em; color: var(--nz-ink-70); margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
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
        input[type="password"], input[type="text"], input[type="search"] {
            width: 100%; padding: 11px 14px;
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
        input:focus { box-shadow: var(--nz-shadow-sm); }
        .btn-primary {
            width: 100%; padding: 13px;
            background: var(--nz-green);
            color: var(--nz-ink);
            border: 2px solid var(--nz-ink);
            font-family: var(--nz-font-body);
            font-size: .95rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: var(--nz-shadow);
            transition: background var(--nz-dur), box-shadow var(--nz-dur);
        }
        .btn-primary:hover { background: var(--nz-green-strong); }
        .btn-primary:active { transform: translate(3px,3px); box-shadow: none; }
        .alert { padding: 10px 14px; font-size: .85rem; font-weight: 500; margin-bottom: 16px; border: 2px solid var(--nz-ink); }
        .alert-error   { background: #FFD5C5; }
        .alert-success { background: var(--nz-green-soft); }

        /* ── Hauptlayout ────────────────────────────────────────────────── */
        .main { max-width: 1300px; margin: 0 auto; padding: 28px 20px 48px; }

        /* ── Stat-Karten ────────────────────────────────────────────────── */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card {
            background: #fff;
            border: 2px solid var(--nz-ink);
            box-shadow: var(--nz-shadow-sm);
            padding: 18px 20px;
        }
        .stat-card .stat-val {
            font-family: var(--nz-font-display);
            font-size: 2rem;
            font-weight: 700;
            color: var(--nz-ink);
            line-height: 1;
        }
        .stat-card .stat-lbl {
            font-family: var(--nz-font-mono);
            font-size: 10px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-top: 6px;
        }

        /* ── Toolbar ────────────────────────────────────────────────────── */
        .toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
        .search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 340px; }
        .search-wrap .ico { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--nz-ink-50); pointer-events: none; }
        .search-wrap input { padding-left: 34px; }
        .btn-sm {
            padding: 9px 16px;
            background: #fff;
            border: 2px solid var(--nz-ink);
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
            cursor: pointer;
            color: var(--nz-ink);
            text-decoration: none;
            transition: background var(--nz-dur);
            box-shadow: var(--nz-shadow-sm);
        }
        .btn-sm:hover { background: var(--nz-paper-alt); }
        .btn-sm:active { transform: translate(3px,3px); box-shadow: none; }
        .btn-danger-sm { background: #FFD5C5; }
        .btn-danger-sm:hover { background: #FFBEA8; }

        /* ── Tabelle ────────────────────────────────────────────────────── */
        .table-wrap {
            background: #fff;
            border: 2px solid var(--nz-ink);
            box-shadow: var(--nz-shadow);
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        thead { background: var(--nz-paper-deep); border-bottom: 2px solid var(--nz-ink); }
        th {
            padding: 12px 14px;
            text-align: left;
            font-family: var(--nz-font-mono);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            white-space: nowrap;
        }
        th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        th a:hover { color: var(--nz-ink); }
        td { padding: 12px 14px; border-bottom: 1.5px solid var(--nz-ink-20); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: var(--nz-paper-alt); }

        /* Dateiname-Zelle */
        .file-cell { display: flex; align-items: center; gap: 10px; }
        .file-cell .f-icon { font-size: 1.3rem; flex-shrink: 0; }
        .file-cell .f-name { font-weight: 600; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-cell .f-mime { font-family: var(--nz-font-mono); font-size: 10px; letter-spacing: 0.06em; color: var(--nz-ink-70); }

        /* URL-Zelle */
        .url-cell { display: flex; align-items: center; gap: 6px; }
        .url-text {
            font-family: var(--nz-font-mono);
            font-size: .75rem;
            color: var(--nz-ink-70);
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .url-text a { color: var(--nz-ink); text-decoration: underline; }
        .url-text a:hover { color: var(--nz-ink-70); }
        .btn-copy-row {
            flex-shrink: 0;
            background: var(--nz-paper-deep);
            border: 1.5px solid var(--nz-ink);
            padding: 4px 10px;
            font-family: var(--nz-font-mono);
            font-size: 10px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
            cursor: pointer;
            color: var(--nz-ink);
            white-space: nowrap;
            transition: background var(--nz-dur);
        }
        .btn-copy-row:hover  { background: var(--nz-green-soft); }
        .btn-copy-row.copied { background: var(--nz-green); border-color: var(--nz-ink); }

        /* Status-Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border: 1.5px solid var(--nz-ink);
            font-family: var(--nz-font-mono);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge-active  { background: var(--nz-green-soft); }
        .badge-expired { background: #FFD5C5; }
        .badge-soon    { background: #FFF0C5; }

        /* Löschen */
        .delete-form { display: inline; }
        .btn-del {
            background: none;
            border: 1.5px solid var(--nz-ink-20);
            cursor: pointer;
            color: var(--nz-ink-70);
            font-size: .95rem;
            padding: 4px 8px;
            transition: background var(--nz-dur), border-color var(--nz-dur);
        }
        .btn-del:hover { color: var(--nz-ink); background: #FFD5C5; border-color: var(--nz-ink); }

        /* ── Lösch-Modal ──────────────────────────────────────────────────── */
        .modal-backdrop {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.55); z-index: 100;
            align-items: center; justify-content: center;
        }
        .modal-backdrop.open { display: flex; }
        .modal {
            background: var(--nz-paper);
            border: 2px solid var(--nz-ink);
            box-shadow: 10px 10px 0 0 #000;
            padding: 32px;
            max-width: 420px;
            width: 90%;
            animation: modalIn .15s ease-out;
        }
        @keyframes modalIn { from { transform: translate(-6px,-6px); opacity: 0; } to { transform: translate(0,0); opacity: 1; } }
        .modal-icon { font-size: 2.5rem; text-align: center; margin-bottom: 12px; }
        .modal h3 { font-family: var(--nz-font-display); font-size: 1.3rem; font-weight: 700; text-align: center; margin-bottom: 8px; }
        .modal p { font-family: var(--nz-font-mono); font-size: 12px; letter-spacing: 0.06em; color: var(--nz-ink-70); text-align: center; margin-bottom: 24px; word-break: break-word; }
        .modal-actions { display: flex; gap: 10px; }
        .modal-actions .btn-cancel {
            flex: 1; padding: 11px;
            border: 2px solid var(--nz-ink);
            background: #fff;
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
            cursor: pointer;
            color: var(--nz-ink);
            transition: background var(--nz-dur);
        }
        .modal-actions .btn-cancel:hover { background: var(--nz-paper-deep); }
        .modal-actions .btn-confirm-del {
            flex: 1; padding: 11px;
            border: 2px solid var(--nz-ink);
            background: var(--nz-error);
            color: #fff;
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
            cursor: pointer;
            box-shadow: var(--nz-shadow-sm);
            transition: opacity var(--nz-dur);
        }
        .modal-actions .btn-confirm-del:hover { opacity: .88; }
        .modal-actions .btn-confirm-del:active { transform: translate(3px,3px); box-shadow: none; }

        /* Leer-Zustand */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--nz-ink-50); }
        .empty-state .empty-icon { font-size: 3rem; margin-bottom: 12px; }

        /* ── Paginierung ─────────────────────────────────────────────────── */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
        .page-btn {
            padding: 7px 13px;
            border: 2px solid var(--nz-ink);
            font-family: var(--nz-font-mono);
            font-size: 11px;
            letter-spacing: 0.08em;
            font-weight: 700;
            cursor: pointer;
            color: var(--nz-ink);
            text-decoration: none;
            background: #fff;
            transition: background var(--nz-dur);
        }
        .page-btn:hover { background: var(--nz-paper-alt); }
        .page-btn.active { background: var(--nz-green); border-color: var(--nz-ink); }
        .page-btn:disabled, .page-btn.disabled { opacity: .4; pointer-events: none; }

        /* ── Responsive ──────────────────────────────────────────────────── */
        @media (max-width: 900px) { .col-email, .col-date { display: none; } }

        @media (max-width: 640px) {
            .col-size, .col-cnt { display: none; }
            .url-text { max-width: 140px; }
            .file-cell .f-name { max-width: 120px; }
            .toolbar { flex-wrap: wrap; gap: 8px; }
        }

        /* ── Karten-Layout für Tabellen-Zeilen auf Phones ────────────────── */
        @media (max-width: 540px) {
            /* Tabelle → Karten */
            .table-wrap { overflow: visible; box-shadow: none; border: none; }
            table, tbody, tr { display: block; }
            thead { display: none; }
            tr {
                border: 2px solid var(--nz-ink);
                box-shadow: var(--nz-shadow-sm);
                background: #fff;
                margin-bottom: 14px;
            }
            td {
                display: flex;
                align-items: center;
                padding: 10px 14px;
                border-bottom: 1.5px solid var(--nz-ink-20);
                min-height: 44px;
            }
            td:last-child { border-bottom: none; }
            td[data-label]::before {
                content: attr(data-label);
                font-family: var(--nz-font-mono);
                font-size: 10px;
                letter-spacing: 0.1em;
                text-transform: uppercase;
                color: var(--nz-ink-70);
                font-weight: 700;
                flex-shrink: 0;
                width: 72px;
                margin-right: 12px;
            }
            /* Spalten die auf Desktop ausgeblendet waren, jetzt wieder zeigen */
            .col-email, .col-date, .col-size, .col-cnt { display: flex; }
            .file-cell .f-name { max-width: none; white-space: normal; }
            .url-text { max-width: none; white-space: normal; word-break: break-all; }
            .url-cell { flex-wrap: wrap; gap: 8px; }

            /* Header */
            .page-header { padding: 14px 16px; }
            .logo { font-size: 1.3rem; }
            .logo small { display: none; }
            .btn-ghost { padding: 6px 10px; font-size: 10px; }

            /* Hauptbereich */
            .main { padding: 16px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }

            /* Login */
            .login-card { padding: 24px 20px; margin: 0 4px; }

            /* Touch-Targets */
            .btn-sm { min-height: 44px; }
            .btn-primary { min-height: 48px; }
            .btn-del { min-height: 44px; min-width: 44px; display: flex; align-items: center; justify-content: center; }
            .btn-copy-row { padding: 8px 12px; }
        }
    </style>
</head>
<body>

<!-- ── Header ───────────────────────────────────────────────────────────────── -->
<header class="page-header">
    <div class="logo">Upload<span>Ez</span> <small>Admin</small></div>
    <?php if ($isLoggedIn): ?>
    <div class="header-actions">
        <a href="/" class="btn-ghost">← Zum Upload</a>
        <a href="admin.php?logout=1" class="btn-ghost">Abmelden</a>
    </div>
    <?php endif; ?>
</header>

<?php if (!$isLoggedIn): ?>
<!-- ── Login ─────────────────────────────────────────────────────────────────── -->
<div class="login-wrap">
    <div class="login-card">
        <h2>Admin-Bereich</h2>
        <p>Bitte melde dich an, um die Upload-Übersicht zu sehen.</p>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= $h($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="pw">Passwort</label>
                <input type="password" id="pw" name="password" autofocus required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-primary">Anmelden</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── Hauptinhalt ────────────────────────────────────────────────────────────── -->
<main class="main">

    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert <?= $_GET['deleted'] === 'success' ? 'alert-success' : 'alert-error' ?>"
         style="margin-bottom:16px;">
        <?= $_GET['deleted'] === 'success' ? '✓ Datei erfolgreich gelöscht.' : '✗ Fehler beim Löschen.' ?>
    </div>
    <?php endif; ?>

    <!-- Statistik-Karten -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-val"><?= number_format((int)($stats['total'] ?? 0)) ?></div>
            <div class="stat-lbl">Uploads gesamt</div>
        </div>
        <div class="stat-card">
            <div class="stat-val" style="color:var(--nz-ink)"><?= number_format((int)($stats['active'] ?? 0)) ?></div>
            <div class="stat-lbl">Aktiv</div>
        </div>
        <div class="stat-card">
            <div class="stat-val" style="color:var(--nz-error)"><?= number_format((int)($stats['expired'] ?? 0)) ?></div>
            <div class="stat-lbl">Abgelaufen</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= fmtBytes((int)($stats['total_size'] ?? 0)) ?></div>
            <div class="stat-lbl">Gesamtgröße</div>
        </div>
    </div>

    <!-- Toolbar -->
    <form method="GET" action="admin.php">
        <input type="hidden" name="sort" value="<?= $h($sortCol) ?>">
        <input type="hidden" name="dir"  value="<?= $sortDir === 'ASC' ? 'asc' : 'desc' ?>">
        <div class="toolbar">
            <div class="search-wrap">
                <span class="ico">🔍</span>
                <input type="search" name="q" value="<?= $h($search) ?>"
                       placeholder="Dateiname oder E-Mail suchen…">
            </div>
            <button type="submit" class="btn-sm">Suchen</button>
            <?php if ($search !== ''): ?>
            <a href="admin.php" class="btn-sm">✕ Zurücksetzen</a>
            <?php endif; ?>
            <span style="margin-left:auto;font-size:.82rem;color:var(--nz-ink-70);">
                <?= number_format($totalRows ?? 0) ?> Einträge
            </span>
        </div>
    </form>

    <!-- Tabelle -->
    <div class="table-wrap">
        <?php if (empty($files)): ?>
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <p><?= $search !== '' ? 'Keine Treffer für „' . $h($search) . '".' : 'Noch keine Uploads vorhanden.' ?></p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><a href="<?= sortUrl('original_name') ?>">Dateiname <?= sortIcon('original_name') ?></a></th>
                    <th>Download-Link</th>
                    <th class="col-size"><a href="<?= sortUrl('file_size') ?>">Größe <?= sortIcon('file_size') ?></a></th>
                    <th>Status</th>
                    <th class="col-cnt"><a href="<?= sortUrl('download_count') ?>">Downloads <?= sortIcon('download_count') ?></a></th>
                    <th class="col-email">E-Mail</th>
                    <th class="col-date"><a href="<?= sortUrl('created_at') ?>">Hochgeladen <?= sortIcon('created_at') ?></a></th>
                    <th class="col-date"><a href="<?= sortUrl('expiry') ?>">Läuft ab <?= sortIcon('expiry') ?></a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($files as $f):
                $expired  = isExpired($f['expiry']);
                $expDt    = new DateTimeImmutable($f['expiry'], new DateTimeZone('UTC'));
                $nowDt    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $daysLeft = (int)ceil(($expDt->getTimestamp() - $nowDt->getTimestamp()) / 86400);
                $dlUrl    = APP_URL . '/download.php?token=' . $f['token'];

                if ($expired) {
                    $badge = '<span class="badge badge-expired">Abgelaufen</span>';
                } elseif ($daysLeft <= 1) {
                    $badge = '<span class="badge badge-soon">Läuft heute ab</span>';
                } elseif ($daysLeft <= 3) {
                    $badge = '<span class="badge badge-soon">Noch ' . $daysLeft . ' Tage</span>';
                } else {
                    $badge = '<span class="badge badge-active">Aktiv · ' . $daysLeft . ' T.</span>';
                }
            ?>
                <tr>
                    <!-- Dateiname -->
                    <td data-label="Datei">
                        <div class="file-cell">
                            <span class="f-icon"><?= fileEmoji($f['mime_type']) ?></span>
                            <div>
                                <div class="f-name" title="<?= $h($f['original_name']) ?>"><?= $h($f['original_name']) ?></div>
                                <div class="f-mime"><?= $h($f['mime_type']) ?></div>
                            </div>
                        </div>
                    </td>
                    <!-- Download-Link + Kopieren -->
                    <td data-label="Link">
                        <div class="url-cell">
                            <span class="url-text">
                                <?php if (!$expired): ?>
                                <a href="<?= $h($dlUrl) ?>" target="_blank" rel="noopener"><?= $h($dlUrl) ?></a>
                                <?php else: ?>
                                <span><?= $h($dlUrl) ?></span>
                                <?php endif; ?>
                            </span>
                            <button class="btn-copy-row"
                                    data-url="<?= $h($dlUrl) ?>"
                                    onclick="copyUrl(this)"
                                    title="Link in Zwischenablage kopieren">
                                Kopieren
                            </button>
                        </div>
                    </td>
                    <!-- Größe -->
                    <td class="col-size" data-label="Größe"><?= fmtBytes((int)$f['file_size']) ?></td>
                    <!-- Status -->
                    <td data-label="Status"><?= $badge ?></td>
                    <!-- Downloads -->
                    <td class="col-cnt" data-label="DL" style="text-align:center"><?= (int)$f['download_count'] ?>×</td>
                    <!-- E-Mail -->
                    <td class="col-email" data-label="E-Mail">
                        <?= $f['email_recipient'] ? $h($f['email_recipient']) : '<span style="color:var(--nz-ink-70)">—</span>' ?>
                    </td>
                    <!-- Hochgeladen -->
                    <td class="col-date" data-label="Datum" style="white-space:nowrap">
                        <?= (new DateTimeImmutable($f['created_at']))->format('d.m.Y H:i') ?>
                    </td>
                    <!-- Ablauf -->
                    <td class="col-date" data-label="Ablauf" style="white-space:nowrap">
                        <?= $expDt->format('d.m.Y') ?>
                    </td>
                    <!-- Löschen -->
                    <td>
                        <form class="delete-form" method="POST" action="admin.php" id="del-<?= $h($f['token']) ?>">
                            <input type="hidden" name="csrf_token"   value="<?= $h($csrfToken) ?>">
                            <input type="hidden" name="delete_token" value="<?= $h($f['token']) ?>">
                            <button type="button" class="btn-del" title="Datei löschen"
                                    onclick="openDeleteModal('del-<?= $h($f['token']) ?>', '<?= $h(addslashes($f['original_name'])) ?>')">
                                🗑️
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Paginierung -->
    <?php if ($totalPages > 1):
        $baseUrl = 'admin.php?sort=' . urlencode($sortCol) . '&dir=' . ($sortDir === 'ASC' ? 'asc' : 'desc')
                   . ($search !== '' ? '&q=' . urlencode($search) : '');
    ?>
    <div class="pagination">
        <a href="<?= $baseUrl ?>&page=<?= max(1, $page - 1) ?>"
           class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹ Zurück</a>

        <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
        <a href="<?= $baseUrl ?>&page=<?= $p ?>"
           class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>

        <a href="<?= $baseUrl ?>&page=<?= min($totalPages, $page + 1) ?>"
           class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Weiter ›</a>
    </div>
    <?php endif; ?>

</main>
<?php endif; ?>

<!-- ── Lösch-Bestätigungs-Modal ───────────────────────────────────────────── -->
<div class="modal-backdrop" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal">
        <div class="modal-icon">🗑️</div>
        <h3 id="modalTitle">Datei löschen?</h3>
        <p id="modalFileName"></p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeDeleteModal()">Abbrechen</button>
            <button class="btn-confirm-del" id="modalConfirmBtn">Löschen</button>
        </div>
    </div>
</div>

<script>
let _pendingDeleteForm = null;

function openDeleteModal(formId, filename) {
    _pendingDeleteForm = document.getElementById(formId);
    document.getElementById('modalFileName').textContent =
        'Datei „' + filename + '" wird unwiderruflich gelöscht.';
    document.getElementById('deleteModal').classList.add('open');
    document.getElementById('modalConfirmBtn').focus();
}

function closeDeleteModal() {
    _pendingDeleteForm = null;
    document.getElementById('deleteModal').classList.remove('open');
}

document.getElementById('modalConfirmBtn').addEventListener('click', () => {
    if (_pendingDeleteForm) _pendingDeleteForm.submit();
});

// Klick auf den Hintergrund schließt das Modal
document.getElementById('deleteModal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeDeleteModal();
});

// Escape-Taste schließt das Modal
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDeleteModal();
});

async function copyUrl(btn) {
    const url = btn.dataset.url;
    try {
        await navigator.clipboard.writeText(url);
    } catch {
        const ta = document.createElement('textarea');
        ta.value = url;
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
    btn.textContent = '✓ Kopiert!';
    btn.classList.add('copied');
    setTimeout(() => {
        btn.textContent = '📋 Kopieren';
        btn.classList.remove('copied');
    }, 2000);
}
</script>

</body>
</html>
