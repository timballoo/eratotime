#!/usr/bin/env php
<?php

/**
 * bin/migrate.php — apply the schema + seed (db/eratotime_migration.sql).
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS + INSERT ... WHERE NOT EXISTS /
 * ON DUPLICATE KEY, so it is safe to run on every deploy. Reads DB creds from
 * the server .env via config.php. Never runs from the web (bin/ is denied by
 * .htaccess).
 *
 *   php bin/migrate.php
 */

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['db']['name']) || $config['db']['name'] === '') {
    fwrite(STDERR, "DB not configured — fill .env (ERATO_DB_*)\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    (int) ($config['db']['port'] ?? 3306),
    $config['db']['name'],
    $config['db']['charset'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);

$sql = file_get_contents(__DIR__ . '/../db/eratotime_migration.sql');
if ($sql === false) {
    fwrite(STDERR, "Could not read db/eratotime_migration.sql\n");
    exit(1);
}

// Guarded schema sync for existing databases: CREATE TABLE IF NOT EXISTS never
// alters a table that already exists, so new columns are added explicitly when
// missing. Works on MySQL and MariaDB (no ADD COLUMN IF NOT EXISTS needed).
//
// This MUST run *before* the schema+seed batch below. The seed INSERTs in
// db/eratotime_migration.sql already reference the newer columns (e.g. the
// global_settings seed writes dynamic_meet_links / delete_meet_events /
// email_signature_path). On a pre-existing database those columns are absent,
// so that seed INSERT fails with 'Unknown column' and aborts the whole
// multi-statement batch — leaving later columns such as request_log.meet_link
// unadded, which then breaks the retry_notifications cron. Adding the columns
// first makes every column the seed references exist by the time it runs.
function migrate_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $tableExists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $tableExists->execute([$table]);
    if ((int) $tableExists->fetchColumn() === 0) {
        return; // created below by the schema, with the column already included
    }
    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $exists->execute([$table, $column]);
    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        printf("  + %s.%s added\n", $table, $column);
    }
}

migrate_add_column($pdo, 'meeting_types', 'video_link', 'VARCHAR(255) NULL');
migrate_add_column($pdo, 'meeting_types', 'message_template', 'TEXT NULL');
migrate_add_column($pdo, 'request_log', 'video_call', 'TINYINT(1) NOT NULL DEFAULT 0');
migrate_add_column($pdo, 'request_log', 'meet_link', 'VARCHAR(255) NULL');
migrate_add_column($pdo, 'global_settings', 'meet_link', 'VARCHAR(255) NULL');
migrate_add_column($pdo, 'global_settings', 'dynamic_meet_links', 'TINYINT(1) NOT NULL DEFAULT 0');
migrate_add_column($pdo, 'global_settings', 'delete_meet_events', 'TINYINT(1) NOT NULL DEFAULT 0');
migrate_add_column($pdo, 'global_settings', 'email_signature_path', 'VARCHAR(255) NULL');
migrate_add_column($pdo, 'tenants', 'reset_secret', 'VARCHAR(64) NULL');

$pdo->exec($sql);
printf("Migration applied to %s\n", $config['db']['name']);
exit(0);
