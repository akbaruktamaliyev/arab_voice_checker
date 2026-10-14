-- QRaft — database schema (v2)
-- Fresh install: the web installer (install/) imports this automatically.
-- Manual install:  mysql -u root -p qraft < database.sql

CREATE DATABASE IF NOT EXISTS `qraft`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `qraft`;

-- Subscription plans ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `plans` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(80)  NOT NULL,
    `price`      INT UNSIGNED NOT NULL DEFAULT 0,  -- minor units (e.g. cents)
    `period`     ENUM('month','year','lifetime') NOT NULL DEFAULT 'month',
    `max_codes`  INT UNSIGNED NULL,                -- NULL = unlimited
    `max_bios`   INT UNSIGNED NULL,
    `features`   VARCHAR(1000) NULL,               -- comma-separated, for display
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `sort`       INT          NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(80)  NOT NULL,
    `email`         VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          ENUM('user','admin') NOT NULL DEFAULT 'user',
    `plan_id`       INT UNSIGNED NULL,
    `plan_expires`  DATETIME     NULL,
    `is_verified`   TINYINT(1)   NOT NULL DEFAULT 0,
    `verify_token`  CHAR(64)     NULL,
    `reset_token`   CHAR(64)     NULL,
    `reset_expires` DATETIME     NULL,
    `is_blocked`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_user_plan` (`plan_id`),
    CONSTRAINT `fk_user_plan` FOREIGN KEY (`plan_id`)
        REFERENCES `plans` (`id`) ON DELETE SET NULL
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

-- Bio link pages (Linktree-style) -------------------------------------
CREATE TABLE IF NOT EXISTS `bio_pages` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `slug`       VARCHAR(40)  NOT NULL,
    `title`      VARCHAR(120) NOT NULL,
    `headline`   VARCHAR(200) NULL,
    `theme`      VARCHAR(16)  NOT NULL DEFAULT 'light',
    `views`      INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bio_slug` (`slug`),
    KEY `idx_bio_user` (`user_id`),
    CONSTRAINT `fk_bio_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bio_links` (
    `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bio_id`  INT UNSIGNED NOT NULL,
    `label`   VARCHAR(120) NOT NULL,
    `url`     VARCHAR(2048) NOT NULL,
    `sort`    INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_biolink_bio` (`bio_id`),
    CONSTRAINT `fk_biolink_bio` FOREIGN KEY (`bio_id`)
        REFERENCES `bio_pages` (`id`) ON DELETE CASCADE
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
    ('smtp_secure', 'tls'),
    ('stripe_secret', ''),
    ('stripe_public', ''),
    ('stripe_webhook_secret', ''),
    ('currency', 'usd'),
    ('default_lang', 'en')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Default plans --------------------------------------------------------
INSERT INTO `plans` (`name`, `price`, `period`, `max_codes`, `max_bios`, `features`, `sort`) VALUES
    ('Free', 0, 'month', 5, 1, 'Up to 5 dynamic codes,1 bio page,Scan analytics,PNG/SVG export', 0),
    ('Pro', 900, 'month', NULL, 10, 'Unlimited dynamic codes,10 bio pages,Password & expiry,PDF export,REST API', 1),
    ('Business', 2900, 'month', NULL, NULL, 'Everything in Pro,Unlimited bio pages,Bulk import,Priority support', 2)
ON DUPLICATE KEY UPDATE `name` = `name`;
