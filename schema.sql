-- UploadEz – Datenbankschema
-- Zeichensatz: utf8mb4 für volle Unicode-Unterstützung (Emojis, internationale Zeichen)

CREATE DATABASE IF NOT EXISTS `uploadez`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `uploadez`;

CREATE TABLE IF NOT EXISTS `files` (
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `original_name`    VARCHAR(255)      NOT NULL COMMENT 'Ursprünglicher Dateiname (sanitized)',
    `stored_name`      VARCHAR(64)       NOT NULL COMMENT 'Zufälliger Hex-Name auf dem Dateisystem',
    `mime_type`        VARCHAR(128)      NOT NULL COMMENT 'Validierter MIME-Type der Datei',
    `file_size`        BIGINT UNSIGNED   NOT NULL COMMENT 'Dateigröße in Bytes',
    `token`            VARCHAR(64)       NOT NULL COMMENT 'Kryptografisch sicherer Download-Token',
    `expiry`           DATETIME          NOT NULL COMMENT 'Ablaufdatum (UTC)',
    `email_recipient`  VARCHAR(255)          NULL DEFAULT NULL COMMENT 'E-Mail-Empfänger des Links',
    `download_count`   INT UNSIGNED      NOT NULL DEFAULT 0,
    `ip_address`       VARBINARY(16)         NULL DEFAULT NULL COMMENT 'IP des Uploaders (inet6_aton)',
    `created_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_stored_name` (`stored_name`),
    UNIQUE KEY `uq_token`       (`token`),
    KEY `idx_expiry`            (`expiry`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Cleanup-Event (optional, falls MySQL Event Scheduler aktiv ist)
-- DELIMITER $$
-- CREATE EVENT IF NOT EXISTS `ev_cleanup_expired_files`
--     ON SCHEDULE EVERY 1 DAY
--     STARTS CURRENT_TIMESTAMP
--     DO
--     BEGIN
--         DELETE FROM `files` WHERE `expiry` < NOW();
--     END$$
-- DELIMITER ;
