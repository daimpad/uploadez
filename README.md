# UploadEz

Sicheres File-Sharing-Tool auf Basis des LAMP-Stacks. Dateien bis zu **2 GB** hochladen, einen kryptografisch sicheren Download-Link generieren und optional per E-Mail versenden.

---

## Features

- **Chunked Upload** – Dateien werden clientseitig in 5-MB-Stücke zerlegt, stabil auch bei großen Dateien und langsamen Verbindungen
- **Drag & Drop** – mit Fortschrittsbalken, ETA-Anzeige und Datei-Vorschau
- **Sichere Download-Links** – 64-stellige zufällige Hex-Token, ablaufend nach konfigurierbaren Tagen
- **Passwortgeschützte Links** – optionaler bcrypt-gesicherter Zugangsschutz pro Upload
- **E-Mail-Versand** – Link an Empfänger senden (PHPMailer bevorzugt, Fallback auf PHP `mail()`)
- **Uploader-Benachrichtigung** – automatische Bestätigungs-E-Mail an den Uploader nach erfolgreichem Upload
- **Upload-Authentifizierung** – optionaler gemeinsamer Token (`UPLOAD_TOKEN`) verhindert unautorisierten Zugriff
- **Rate Limiting** – IP-basiertes Limit (konfigurierbar, TOCTOU-sicher via DB-Transaktion)
- **MIME-Validierung** – `finfo::file()` auf der assemblierten Datei plus Kreuz-Validierung MIME↔Dateiendung
- **Admin-Übersicht** – passwortgeschützte Tabelle aller Uploads mit Sortierung, Suche, Paginierung und Löschfunktion
- **Automatischer Cleanup** – Cronjob-Skript löscht abgelaufene Dateien von Disk und Datenbank
- **Mobile-optimiert** – responsives Layout mit Touch-Targets und Card-Ansicht auf kleinen Screens

---

## Verzeichnisstruktur

```
uploadez/
├── index.php              # Frontend (HTML/CSS/JS) + API-Router
├── download.php           # Token-basierter sicherer File-Download
├── admin.php              # Passwortgeschützte Upload-Übersicht
├── cleanup.php            # CLI-Cronjob für Ablauf-Bereinigung
├── config.php             # Zentrale Konfiguration (env-var-fähig)
├── schema.sql             # MySQL/MariaDB DDL (files + rate_limits)
├── .htaccess              # Apache-Sicherheitskonfiguration (Root)
├── .env.example           # Vorlage für Umgebungsvariablen
├── includes/
│   ├── db.php             # PDO-Singleton
│   ├── uploader.php       # Chunk-Assembly, MIME-Validierung, DB-Insert
│   ├── mailer.php         # PHPMailer / mail()-Fallback + formatBytes()
│   ├── rate_limiter.php   # IP-Rate-Limiting + getClientIp()
│   └── .htaccess          # Direktzugriff gesperrt
├── uploads/
│   └── .htaccess          # Alle Script-Handler deaktiviert, Zugriff gesperrt
└── tmp/
    └── .htaccess          # Vollständig gesperrt (Chunk-Temp-Verzeichnis)
```

---

## Voraussetzungen

| Komponente | Version |
|------------|---------|
| PHP        | 8.0 oder neuer |
| MySQL      | 5.7+ / MariaDB 10.3+ |
| Apache     | 2.4+ mit `mod_rewrite`, `mod_headers` |
| Composer   | Optional (für PHPMailer) |

---

## Installation

### 1. Repository klonen

```bash
git clone https://github.com/daimpad/uploadez.git
cd uploadez
```

### 2. Datenbank einrichten

```bash
mysql -u root -p < schema.sql
```

Das Skript legt die Datenbank `uploadez` und die Tabellen `files` sowie `rate_limits` automatisch an.

### 3. Umgebungsvariablen konfigurieren

```bash
cp .env.example .env
```

`.env` mit einem Editor öffnen und ausfüllen:

```dotenv
DB_HOST=localhost
DB_PORT=3306
DB_NAME=uploadez
DB_USER=uploadez_user
DB_PASS=sicheres_passwort

APP_URL=https://yourdomain.com

SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=noreply@yourdomain.com
SMTP_PASS=smtp_passwort
SMTP_FROM=noreply@yourdomain.com
SMTP_FROM_NAME=UploadEz
SMTP_SECURE=tls

# Passwort-Hash für den Admin-Bereich (siehe Schritt 5)
ADMIN_PASSWORD_HASH=$2y$12$...

# Optional: Upload auf autorisierte Clients beschränken
# UPLOAD_TOKEN=langer_zufaelliger_string

# Rate Limiting (Standard: 20 Uploads pro Stunde)
# RATE_LIMIT_MAX=20
# RATE_LIMIT_WINDOW=3600
```

> **Hinweis:** Die `.env`-Datei ist in `.gitignore` eingetragen und wird nie committet.

### 4. PHP-Limits anpassen (für Uploads bis 2 GB)

In `php.ini` oder per `.htaccess` (bereits vorbereitet):

```ini
upload_max_filesize = 2G
post_max_size       = 2G
max_execution_time  = 3600
memory_limit        = 256M
```

### 5. Admin-Passwort setzen

```bash
php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
```

Den ausgegebenen Hash in `.env` als `ADMIN_PASSWORD_HASH` eintragen.

### 6. PHPMailer installieren (empfohlen)

```bash
composer require phpmailer/phpmailer
```

Ohne PHPMailer wird automatisch auf PHP `mail()` zurückgegriffen.

### 7. Cronjob einrichten (täglicher Cleanup)

```bash
crontab -e
```

Folgende Zeile hinzufügen:

```
30 2 * * * php /var/www/html/uploadez/cleanup.php >> /var/log/uploadez-cleanup.log 2>&1
```

---

## Nutzung

### Upload

Startseite öffnen → Datei per Drag & Drop oder Klick auswählen → optionale E-Mail-Adresse und Passwort eingeben → **Hochladen starten**.

Nach dem Upload:
- Download-Link wird angezeigt und ist per Button kopierbar
- Link kann direkt per E-Mail an einen Empfänger versendet werden
- Uploader erhält automatisch eine Bestätigungs-E-Mail (sofern E-Mail angegeben)

### Download

```
https://yourdomain.com/download.php?token=<64-stelliger-hex-token>
```

Passwortgeschützte Links zeigen ein Eingabeformular. Abgelaufene Links geben HTTP 410 zurück.

### Admin-Bereich

```
https://yourdomain.com/admin.php
```

Nach dem Login mit dem konfigurierten Passwort:

| Funktion | Beschreibung |
|---|---|
| Statistik-Karten | Uploads gesamt, aktiv, abgelaufen, Gesamtspeicher |
| Tabelle | Alle Uploads mit Dateiname, Link, Größe, Status, Downloads, E-Mail, Datum |
| Sortierung | Klick auf Spaltenköpfe (Name, Größe, Downloads, Datum, Ablauf) |
| Suche | Volltextsuche nach Dateiname oder E-Mail |
| Link kopieren | Button pro Zeile kopiert den Link in die Zwischenablage |
| Löschen | Entfernt Datei von Disk und Datenbank (mit CSRF-Schutz und Bestätigungsdialog) |

---

## Sicherheitskonzept

### Datei-Speicherung

| Maßnahme | Implementierung |
|---|---|
| Keine Skriptausführung | `uploads/.htaccess`: `RemoveHandler`, `RemoveType`, `Require all denied` |
| Zufällige Dateinamen | `bin2hex(random_bytes(16))` – kein Bezug zum Originalnamen |
| Dateiberechtigungen | `chmod 0640` auf jede gespeicherte Datei |
| MIME-Validierung | `finfo::file()` auf der assemblierten Datei (nicht Client-Angabe) |
| MIME↔Endung Kreuzprüfung | Erkannter MIME-Typ muss zur Dateiendung passen |
| Whitelist | MIME-Type und Dateiendung gegen feste Liste geprüft |

### Upload-Prozess

| Maßnahme | Implementierung |
|---|---|
| Upload-Auth | `UPLOAD_TOKEN` per `hash_equals()` auf **jedem** Chunk geprüft |
| Rate Limiting | SELECT + INSERT in DB-Transaktion mit `FOR UPDATE` (TOCTOU-sicher) |
| Größenlimit | 2 GB server- und clientseitig |
| UUID-Validierung | Regex-Prüfung vor Verzeichniserstellung |
| Dateinamen-Sanitisierung | `basename()` + Regex-Strip + Doppelpunkt-Kollaps |
| Chunk-Cleanup | Verzeichnis wird nach Assemblierung und bei jedem Fehler bereinigt |
| IP-Erfassung | `getClientIp()` mit `X-Forwarded-For`-Unterstützung und Validierung |

### Download

| Maßnahme | Implementierung |
|---|---|
| Token-Sicherheit | 64-stelliger Hex-Token via `random_bytes(32)` |
| Ablauf-Prüfung | UTC-Vergleich, HTTP 410 bei abgelaufenem Link |
| Traversal-Schutz | `realpath(UPLOAD_DIR)` auf `false` geprüft, dann `strpos`-Pfadvergleich |
| Passwortschutz | bcrypt-Hash in DB, Session-basierte Freigabe |
| Session-Cookie | `HttpOnly`, `SameSite=Strict`, `Secure` (bei HTTPS) |
| Content-Type | Aus DB – niemals vom Client übernommen |
| Header-Injection | CRLF aus `original_name` entfernt |

### Admin-Bereich

| Maßnahme | Implementierung |
|---|---|
| Passwort-Hashing | bcrypt via `password_hash()` / `password_verify()` |
| Session | Regenerierung nach Login, konfigurierbares Timeout |
| CSRF-Schutz | Token in Session, `hash_equals()` bei Delete-Aktionen |
| Brute-Force-Verzögerung | 500 ms Pause bei falschem Passwort |

### Apache-Konfiguration

- `Options -Indexes` – kein Directory-Listing
- Security-Header: `X-Content-Type-Options`, `X-Frame-Options DENY`, `Content-Security-Policy`, `Referrer-Policy`, `Permissions-Policy`
- Zugriff auf sensible Endungen (`.sql`, `.env`, `.sh`, …) gesperrt
- `includes/` und `tmp/` per `RewriteRule` vollständig gesperrt

---

## Erlaubte Dateitypen

| Kategorie | Endungen |
|---|---|
| Bilder | jpg, jpeg, png, gif, webp, svg |
| Dokumente | pdf, doc, docx, xls, xlsx, ppt, pptx |
| Text | txt, csv |
| Archive | zip, rar, 7z, tar, gz |
| Audio | mp3, wav, ogg, m4a |
| Video | mp4, webm, ogv, mov |

Die Liste wird in `config.php` (`ALLOWED_EXTENSIONS`, `ALLOWED_MIME_TYPES`) gepflegt.

---

## Konfigurationsreferenz

Alle Werte können per Umgebungsvariable in `.env` überschrieben werden.

| Konstante | Env-Variable | Standard | Beschreibung |
|---|---|---|---|
| `DB_HOST` | `DB_HOST` | `localhost` | Datenbank-Host |
| `DB_PORT` | `DB_PORT` | `3306` | Datenbank-Port |
| `DB_NAME` | `DB_NAME` | `uploadez` | Datenbankname |
| `APP_URL` | `APP_URL` | `http://localhost` | Öffentliche URL der App |
| `MAX_FILE_SIZE` | – | 2 GB | Maximale Upload-Größe |
| `CHUNK_SIZE` | – | 5 MB | Chunk-Größe (clientseitig) |
| `EXPIRY_DAYS` | – | `7` | Gültigkeitsdauer der Links in Tagen |
| `UPLOAD_TOKEN` | `UPLOAD_TOKEN` | _(leer)_ | Shared Secret für Upload-Auth; leer = deaktiviert |
| `RATE_LIMIT_MAX_UPLOADS` | `RATE_LIMIT_MAX` | `20` | Max. Uploads pro Zeitfenster |
| `RATE_LIMIT_WINDOW` | `RATE_LIMIT_WINDOW` | `3600` | Zeitfenster in Sekunden |
| `SMTP_HOST` | `SMTP_HOST` | – | SMTP-Server |
| `SMTP_PORT` | `SMTP_PORT` | `587` | SMTP-Port |
| `SMTP_SECURE` | `SMTP_SECURE` | `tls` | `tls` oder `ssl` |
| `ADMIN_PASSWORD_HASH` | `ADMIN_PASSWORD_HASH` | – | bcrypt-Hash des Admin-Passworts |
| `ADMIN_SESSION_LIFETIME` | – | `3600` | Admin-Session-Timeout in Sekunden |

---

## Tests

```bash
composer require --dev phpunit/phpunit
./vendor/bin/phpunit
```

45 Unit-Tests decken Dateinamen-Sanitisierung, Rate-Limiting, IP-Erkennung und Byte-Formatierung ab.

---

## Lizenz

MIT License – siehe [LICENSE](LICENSE)
