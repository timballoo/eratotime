<?php

/**
 * cron_dispatcher.php — single cron entry point (FIFA/cookingtogetherness
 * pattern). ONE system cron calls this; it checks the cron_jobs table and runs
 * due jobs by handler. Configure schedules + track execution in the admin
 * panel (Cron tab) or directly in the cron_jobs table.
 *
 * CLI:  php cron_dispatcher.php
 *       php cron_dispatcher.php --force sync_calendars
 * HTTP: GET cron_dispatcher.php?key=YOUR_CRON_SECRET
 *       GET cron_dispatcher.php?key=YOUR_CRON_SECRET&force=sync_calendars
 *
 * System cron (hPanel) — one entry, every 5 minutes:
 *   /usr/bin/php /home/u835116879/domains/book.meertec.ltd/public_html/cron_dispatcher.php
 * (Invoked via `php script.php`, so no shebang is needed — and a shebang would
 * leak into the HTTP JSON response.)
 */

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    $key = $_GET['key'] ?? '';
    $secret = (string) ($config['cron_secret'] ?? '');
    if ($secret === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid cron secret']);
        exit;
    }
}

$only = $isCli
    ? (($argv[1] ?? '') === '--force' ? ($argv[2] ?? null) : null)
    : ($_GET['force'] ?? null);

if ($only !== null && $only === '') {
    $only = null;
}

$pdo = cron_pdo($config);
$results = cron_run_due($pdo, $config, $only);

$payload = ['success' => true, 'results' => $results, 'ran_at' => date('c')];

if ($isCli) {
    foreach ($results as $key => $r) {
        printf("[%s] %s: %s\n", date('c'), $key, $r['ok'] ? 'ok' : 'FAILED — ' . $r['output']);
    }
    if ($results === []) {
        echo "No jobs due.\n";
    }
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_UNESCAPED_SLASHES);
