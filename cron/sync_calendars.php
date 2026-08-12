#!/usr/bin/env php
<?php

/**
 * cron/sync_calendars.php — cron entry point (spec 3.5 / Phase 3).
 *
 * Loops all active calendar_sources across all tenants via calendar_sync_lib
 * and writes calendar_blockouts. Schedule every 5-15 minutes on Hostinger:
 *   php /path/to/eratotime/cron/sync_calendars.php
 *
 * Exit code is non-zero if any source failed (so cron logs / alerts can react).
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
]);

set_time_limit(0);

$results = calendar_sync_all($pdo, $config);
$failed = 0;
foreach ($results as $r) {
    if ($r['ok']) {
        printf("[%s] source %d ok — %d blocks (%d rows changed)\n", date('c'), $r['source_id'], $r['blocks'], $r['changed']);
    } else {
        printf("[%s] source %d FAILED — %s\n", date('c'), $r['source_id'], $r['error']);
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);
