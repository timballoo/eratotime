#!/usr/bin/env php
<?php

/**
 * cron/retry_notifications.php — retry pending notification_outbox rows (4.5).
 * Backs off per attempt; rows past the retry window are marked 'failed' and
 * surface as a warning on the admin dashboard (2.6).
 *
 *   php /path/to/eratotime/cron/retry_notifications.php
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

$results = notify_process_outbox($pdo, $config, 200);
$ok = 0;
$failed = 0;
foreach ($results as $r) {
    if ($r['ok']) {
        $ok++;
    } else {
        $failed++;
    }
}
printf("[%s] outbox: %d sent, %d failed/retrying\n", date('c'), $ok, $failed);
exit($failed > 0 ? 0 : 0); // partial failures retry later; only hard errors matter
