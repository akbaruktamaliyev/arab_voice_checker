<?php
/**
 * QRaft — schema upgrades for existing installs.
 *
 * database.sql always describes the newest schema (fresh installs use it). Sites
 * installed from an older release catch up here: each migration runs once, in
 * order, and the version reached is stored in the `schema_version` setting.
 * Migrations are idempotent, so a partly-applied one is safe to run again.
 */

declare(strict_types=1);

/** Does a column exist on a table? */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Apply any migrations newer than the stored schema version. */
function run_migrations(PDO $pdo): void
{
    $migrations = [
        // 1: admins can force-disable a code (moderation), separate from the owner's pause.
        1 => function (PDO $pdo): void {
            if (!column_exists($pdo, 'qr_codes', 'admin_disabled')) {
                $pdo->exec('ALTER TABLE qr_codes ADD COLUMN admin_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
            }
        },
    ];

    $current = (int) setting('schema_version', '0');
    $latest = max(array_keys($migrations));
    if ($current >= $latest) {
        return;
    }
    try {
        foreach ($migrations as $version => $apply) {
            if ($version > $current) {
                $apply($pdo);
                set_setting($pdo, 'schema_version', (string) $version);
            }
        }
    } catch (PDOException $e) {
        // Never take the site down over an upgrade step; it is retried on the next request.
        error_log('QRaft migration failed: ' . $e->getMessage());
    }
}
