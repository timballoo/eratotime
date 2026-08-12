#!/usr/bin/env php
<?php

/**
 * cron/cleanup.php — retention + soft-hold expiry sweep (spec 4.5 / Phase 7).
 * Runs per active tenant; purge window comes from each tenant's
 * global_settings.request_log_retention_days.
 *
 *   php /path/to/eratotime/cron/cleanup.php
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

$rows = $pdo->query(
    "SELECT t.id, g.request_log_retention_days FROM tenants t
       JOIN global_settings g ON g.tenant_id = t.id
      WHERE t.active = 1"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $result = cleanup_run($pdo, (int) $row['id'], ['request_log_retention_days' => (int) $row['request_log_retention_days']]);
    printf("[%s] tenant %d cleanup: %d expired, %d purged\n", date('c'), (int) $row['id'], $result['expired'], $result['purged']);
}

exit(0);
