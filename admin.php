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
    <style>
        :root {
            --clr-primary:   #6366f1;
            --clr-primary-dk:#4f46e5;
            --clr-secondary: #8b5cf6;
            --clr-bg:        #f0f2ff;
            --clr-surface:   #ffffff;
            --clr-surface-2: #f8fafc;
            --clr-border:    #e2e8f0;
            --clr-text:      #1e293b;
            --clr-muted:     #64748b;
            --clr-success:   #10b981;
            --clr-error:     #ef4444;
            --clr-warning:   #f59e0b;
            --radius:        12px;
            --radius-sm:     8px;
            --font: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: var(--font); background: var(--clr-bg); color: var(--clr-text); min-height: 100vh; }

        /* ── Header ─────────────────────────────────────────────────────── */
        .page-header {
            background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
            padding: 20px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .logo { font-size: 1.4rem; font-weight: 800; color: #fff; letter-spacing: -.5px; }
        .logo span { opacity: .7; font-weight: 400; }
        .logo small { font-size: .7rem; background: rgba(255,255,255,.2); border-radius: 4px; padding: 2px 8px; margin-left: 8px; vertical-align: middle; font-weight: 600; }
        .header-actions { display: flex; gap: 10px; align-items: center; }
        .btn-ghost { background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3); color: #fff; padding: 7px 16px; border-radius: var(--radius-sm); font-size: .85rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: background .2s; }
        .btn-ghost:hover { background: rgba(255,255,255,.25); }

        /* ── Login ──────────────────────────────────────────────────────── */
        .login-wrap { display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 68px); padding: 20px; }
        .login-card { background: var(--clr-surface); border-radius: var(--radius); box-shadow: 0 8px 40px rgba(99,102,241,.18); padding: 40px; width: 100%; max-width: 380px; }
        .login-card h2 { font-size: 1.25rem; margin-bottom: 6px; }
        .login-card p { color: var(--clr-muted); font-size: .88rem; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: .82rem; font-weight: 600; color: var(--clr-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
        input[type="password"], input[type="text"], input[type="search"] {
            width: 100%; padding: 11px 14px; border: 1.5px solid var(--clr-border);
            border-radius: var(--radius-sm); font-size: .95rem; font-family: var(--font);
            color: var(--clr-text); background: var(--clr-surface); outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        input:focus { border-color: var(--clr-primary); box-shadow: 0 0 0 3px rgba(99,102,241,.15); }
        .btn-primary {
            width: 100%; padding: 12px; background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
            color: #fff; border: none; border-radius: var(--radius-sm); font-size: .95rem;
            font-weight: 600; cursor: pointer; transition: opacity .2s;
        }
        .btn-primary:hover { opacity: .9; }
        .alert { padding: 10px 14px; border-radius: var(--radius-sm); font-size: .85rem; font-weight: 500; margin-bottom: 16px; }
        .alert-error { background: #fef2f2; color: var(--clr-error); border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: var(--clr-success); border: 1px solid #bbf7d0; }

        /* ── Hauptlayout ────────────────────────────────────────────────── */
        .main { max-width: 1300px; margin: 0 auto; padding: 28px 20px 48px; }

        /* ── Stat-Karten ────────────────────────────────────────────────── */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: var(--clr-surface); border-radius: var(--radius); padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .stat-card .stat-val { font-size: 1.6rem; font-weight: 800; color: var(--clr-primary); line-height: 1; }
        .stat-card .stat-lbl { font-size: .78rem; color: var(--clr-muted); margin-top: 4px; text-transform: uppercase; letter-spacing: .05em; }

        /* ── Toolbar ────────────────────────────────────────────────────── */
        .toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
        .search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 340px; }
        .search-wrap .ico { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--clr-muted); pointer-events: none; }
        .search-wrap input { padding-left: 34px; }
        .btn-sm { padding: 9px 16px; background: var(--clr-surface); border: 1.5px solid var(--clr-border); border-radius: var(--radius-sm); font-size: .82rem; font-weight: 600; cursor: pointer; color: var(--clr-text); text-decoration: none; transition: background .2s; }
        .btn-sm:hover { background: var(--clr-border); }
        .btn-danger-sm { background: #fef2f2; color: var(--clr-error); border-color: #fecaca; }
        .btn-danger-sm:hover { background: #fee2e2; }

        /* ── Tabelle ────────────────────────────────────────────────────── */
        .table-wrap { background: var(--clr-surface); border-radius: var(--radius); box-shadow: 0 2px 8px rgba(0,0,0,.06); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        thead { background: var(--clr-surface-2); border-bottom: 2px solid var(--clr-border); }
        th { padding: 12px 14px; text-align: left; font-size: .75rem; font-weight: 700; color: var(--clr-muted); text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
        th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        th a:hover { color: var(--clr-primary); }
        td { padding: 12px 14px; border-bottom: 1px solid var(--clr-border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8faff; }

        /* Dateiname-Zelle */
        .file-cell { display: flex; align-items: center; gap: 10px; }
        .file-cell .f-icon { font-size: 1.3rem; flex-shrink: 0; }
        .file-cell .f-name { font-weight: 600; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-cell .f-mime { font-size: .73rem; color: var(--clr-muted); }

        /* URL-Zelle */
        .url-cell { display: flex; align-items: center; gap: 6px; }
        .url-text { font-size: .78rem; color: var(--clr-muted); font-family: 'SFMono-Regular', Consolas, monospace;
                    max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .url-text a { color: var(--clr-primary); text-decoration: none; }
        .url-text a:hover { text-decoration: underline; }
        .btn-copy-row {
            flex-shrink: 0; background: var(--clr-surface-2); border: 1px solid var(--clr-border);
            border-radius: 6px; padding: 4px 10px; font-size: .75rem; font-weight: 600;
            cursor: pointer; color: var(--clr-primary); white-space: nowrap; transition: background .15s, color .15s;
        }
        .btn-copy-row:hover  { background: #eef0ff; }
        .btn-copy-row.copied { background: #f0fdf4; color: var(--clr-success); border-color: #bbf7d0; }

        /* Status-Badge */
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 99px; font-size: .73rem; font-weight: 700; white-space: nowrap; }
        .badge-active  { background: #ecfdf5; color: #059669; }
        .badge-expired { background: #fef2f2; color: #dc2626; }
        .badge-soon    { background: #fffbeb; color: #d97706; }

        /* Löschen-Formular */
        .delete-form { display: inline; }
        .btn-del { background: none; border: none; cursor: pointer; color: var(--clr-muted); font-size: 1rem; padding: 4px 6px; border-radius: 4px; transition: color .15s, background .15s; }
        .btn-del:hover { color: var(--clr-error); background: #fef2f2; }

        /* ── Lösch-Modal ──────────────────────────────────────────────────── */
        .modal-backdrop {
            display: none; position: fixed; inset: 0;
            background: rgba(15,23,42,.45); z-index: 100;
            align-items: center; justify-content: center;
        }
        .modal-backdrop.open { display: flex; }
        .modal {
            background: var(--clr-surface); border-radius: var(--radius);
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            padding: 32px; max-width: 420px; width: 90%;
            animation: modalIn .18s ease-out;
        }
        @keyframes modalIn { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-icon { font-size: 2.5rem; text-align: center; margin-bottom: 12px; }
        .modal h3 { font-size: 1.1rem; text-align: center; margin-bottom: 8px; }
        .modal p { font-size: .88rem; color: var(--clr-muted); text-align: center; margin-bottom: 24px; word-break: break-word; }
        .modal-actions { display: flex; gap: 10px; }
        .modal-actions .btn-cancel {
            flex: 1; padding: 11px; border: 1.5px solid var(--clr-border); border-radius: var(--radius-sm);
            background: var(--clr-surface); font-size: .9rem; font-weight: 600; cursor: pointer; color: var(--clr-text);
            transition: background .15s;
        }
        .modal-actions .btn-cancel:hover { background: var(--clr-surface-2); }
        .modal-actions .btn-confirm-del {
            flex: 1; padding: 11px; border: none; border-radius: var(--radius-sm);
            background: var(--clr-error); color: #fff; font-size: .9rem; font-weight: 600;
            cursor: pointer; transition: opacity .15s;
        }
        .modal-actions .btn-confirm-del:hover { opacity: .88; }

        /* Leer-Zustand */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--clr-muted); }
        .empty-state .empty-icon { font-size: 3rem; margin-bottom: 12px; }

        /* ── Paginierung ─────────────────────────────────────────────────── */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
        .page-btn { padding: 7px 13px; border: 1.5px solid var(--clr-border); border-radius: var(--radius-sm); font-size: .82rem; font-weight: 600; cursor: pointer; color: var(--clr-text); text-decoration: none; background: var(--clr-surface); transition: background .15s; }
        .page-btn:hover { background: var(--clr-surface-2); }
        .page-btn.active { background: var(--clr-primary); color: #fff; border-color: var(--clr-primary); }
        .page-btn:disabled, .page-btn.disabled { opacity: .4; pointer-events: none; }

        /* ── Responsive ──────────────────────────────────────────────────── */
        @media (max-width: 900px) {
            .col-email, .col-date  { display: none; }
        }
        @media (max-width: 640px) {
            .col-size, .col-cnt { display: none; }
            .url-text { max-width: 140px; }
            .file-cell .f-name { max-width: 120px; }
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
            <div class="stat-val" style="color:var(--clr-success)"><?= number_format((int)($stats['active'] ?? 0)) ?></div>
            <div class="stat-lbl">Aktiv</div>
        </div>
        <div class="stat-card">
            <div class="stat-val" style="color:var(--clr-error)"><?= number_format((int)($stats['expired'] ?? 0)) ?></div>
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
            <span style="margin-left:auto;font-size:.82rem;color:var(--clr-muted);">
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
                    <td>
                        <div class="file-cell">
                            <span class="f-icon"><?= fileEmoji($f['mime_type']) ?></span>
                            <div>
                                <div class="f-name" title="<?= $h($f['original_name']) ?>"><?= $h($f['original_name']) ?></div>
                                <div class="f-mime"><?= $h($f['mime_type']) ?></div>
                            </div>
                        </div>
                    </td>
                    <!-- Download-Link + Kopieren -->
                    <td>
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
                                📋 Kopieren
                            </button>
                        </div>
                    </td>
                    <!-- Größe -->
                    <td class="col-size"><?= fmtBytes((int)$f['file_size']) ?></td>
                    <!-- Status -->
                    <td><?= $badge ?></td>
                    <!-- Downloads -->
                    <td class="col-cnt" style="text-align:center"><?= (int)$f['download_count'] ?>×</td>
                    <!-- E-Mail -->
                    <td class="col-email">
                        <?= $f['email_recipient'] ? $h($f['email_recipient']) : '<span style="color:var(--clr-muted)">—</span>' ?>
                    </td>
                    <!-- Hochgeladen -->
                    <td class="col-date" style="white-space:nowrap">
                        <?= (new DateTimeImmutable($f['created_at']))->format('d.m.Y H:i') ?>
                    </td>
                    <!-- Ablauf -->
                    <td class="col-date" style="white-space:nowrap">
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
