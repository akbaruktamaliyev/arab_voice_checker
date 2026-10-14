-- QRaft — database schema (v2)
-- Fresh install: the web installer (install/) imports this automatically.
-- Manual install:  mysql -u root -p qraft < database.sql

CREATE DATABASE IF NOT EXISTS `qraft`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `qraft`;

-- Accounts -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(80)  NOT NULL,
    `email`         VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          ENUM('user','admin') NOT NULL DEFAULT 'user',
    `is_verified`   TINYINT(1)   NOT NULL DEFAULT 0,
    `verify_token`  CHAR(64)     NULL,
    `reset_token`   CHAR(64)     NULL,
    `reset_expires` DATETIME     NULL,
    `is_blocked`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-configurable settings (key/value) -----------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `name`  VARCHAR(64) NOT NULL,
    `value` TEXT        NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Folders to organise codes -------------------------------------------
CREATE TABLE IF NOT EXISTS `folders` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(80)  NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_folder_user` (`user_id`),
    CONSTRAINT `fk_folder_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dynamic QR codes -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `qr_codes` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `folder_id`     INT UNSIGNED NULL,
    `code`          VARCHAR(40)  NOT NULL,
    `title`         VARCHAR(120) NOT NULL,
    `target_url`    VARCHAR(2048) NOT NULL,
    `fg_color`      CHAR(7)      NOT NULL DEFAULT '#000000',
    `bg_color`      CHAR(7)      NOT NULL DEFAULT '#ffffff',
    `logo_path`     VARCHAR(255) NULL,
    `password_hash` VARCHAR(255) NULL,
    `expires_at`    DATETIME     NULL,
    `scan_limit`    INT UNSIGNED NULL,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `scan_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_qr_code` (`code`),
    KEY `idx_qr_user` (`user_id`),
    KEY `idx_qr_folder` (`folder_id`),
    CONSTRAINT `fk_qr_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qr_folder` FOREIGN KEY (`folder_id`)
        REFERENCES `folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per scan, for analytics -------------------------------------
CREATE TABLE IF NOT EXISTS `qr_scans` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `qr_id`      INT UNSIGNED    NOT NULL,
    `scanned_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_hash`    CHAR(64)        NULL,
    `user_agent` VARCHAR(255)    NULL,
    `referer`    VARCHAR(255)    NULL,
    `device`     VARCHAR(16)     NULL,
    `os`         VARCHAR(24)     NULL,
    `browser`    VARCHAR(24)     NULL,
    PRIMARY KEY (`id`),
    KEY `idx_scan_qr` (`qr_id`),
    KEY `idx_scan_time` (`scanned_at`),
    CONSTRAINT `fk_scan_qr` FOREIGN KEY (`qr_id`)
        REFERENCES `qr_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API tokens (REST API) ------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(80)  NOT NULL,
    `token_hash` CHAR(64)     NOT NULL,
    `last_used`  DATETIME     NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_token_user` (`user_id`),
    CONSTRAINT `fk_token_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings -----------------------------------------------------
INSERT INTO `settings` (`name`, `value`) VALUES
    ('app_name', 'QRaft'),
    ('allow_registration', '1'),
    ('require_email_verification', '0'),
    ('smtp_host', ''),
    ('smtp_port', '587'),
    ('smtp_user', ''),
    ('smtp_pass', ''),
    ('smtp_from', ''),
    ('smtp_secure', 'tls')
ON DUPLICATE KEY UPDATE `name` = `name`;
