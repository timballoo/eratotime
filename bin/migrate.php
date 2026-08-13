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

$pdo->exec($sql);
printf("Migration applied to %s\n", $config['db']['name']);
exit(0);
