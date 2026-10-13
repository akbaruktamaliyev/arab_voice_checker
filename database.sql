-- QRaft — database schema
-- Import this file once before first run:
--   mysql -u root -p qraft < database.sql
-- or load it through phpMyAdmin / Laragon's database tool.

CREATE DATABASE IF NOT EXISTS `qraft`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `qraft`;

-- Accounts -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(80)  NOT NULL,
    `email`         VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dynamic QR codes -----------------------------------------------------
-- A dynamic code stores a short `code` that resolves to `target_url`.
-- The QR image encodes {base_url}/q/{code}, so the destination can be
-- changed later without reprinting the QR, and every visit is counted.
CREATE TABLE IF NOT EXISTS `qr_codes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `code`       VARCHAR(16)  NOT NULL,
    `title`      VARCHAR(120) NOT NULL,
    `target_url` VARCHAR(2048) NOT NULL,
    `fg_color`   CHAR(7)      NOT NULL DEFAULT '#000000',
    `bg_color`   CHAR(7)      NOT NULL DEFAULT '#ffffff',
    `logo_path`  VARCHAR(255) NULL,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `scan_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_qr_code` (`code`),
    KEY `idx_qr_user` (`user_id`),
    CONSTRAINT `fk_qr_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per scan, for the analytics charts --------------------------
CREATE TABLE IF NOT EXISTS `qr_scans` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `qr_id`      INT UNSIGNED    NOT NULL,
    `scanned_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_hash`    CHAR(64)        NULL,
    `user_agent` VARCHAR(255)    NULL,
    `referer`    VARCHAR(255)    NULL,
    PRIMARY KEY (`id`),
    KEY `idx_scan_qr` (`qr_id`),
    KEY `idx_scan_time` (`scanned_at`),
    CONSTRAINT `fk_scan_qr` FOREIGN KEY (`qr_id`)
        REFERENCES `qr_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
