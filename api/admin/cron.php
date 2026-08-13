<?php
// api/admin/cron.php — configure + track dispatcher cron jobs.
require __DIR__ . '/_guard.php';

try {
    $tenantId = admin_guard_tenant();
    $pdo = admin_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        admin_json(['ok' => true, 'jobs' => admin_cron_list($pdo)]);
    }

    $data = admin_guard_body();
    admin_guard_csrf($data);
    $key = (string) ($data['job_key'] ?? '');
    $action = (string) ($data['action'] ?? '');
    if ($key === '' || !in_array($action, ['toggle', 'update', 'run'], true)) {
        admin_json(['ok' => false, 'error' => 'Invalid action'], 400);
    }

    switch ($action) {
        case 'toggle':
            admin_cron_toggle($pdo, $key);
            break;
        case 'update':
            if (!admin_cron_update($pdo, $key, (int) ($data['schedule_min'] ?? 0))) {
                admin_json(['ok' => false, 'error' => 'Invalid schedule (1–10080 minutes)'], 400);
            }
            break;
        case 'run':
            $result = admin_cron_run($pdo, $GLOBALS['admin_config'], $key);
            admin_json(['ok' => $result['ok'], 'output' => $result['output'], 'jobs' => admin_cron_list($pdo)]);
    }
    admin_json(['ok' => true, 'jobs' => admin_cron_list($pdo)]);
} catch (Throwable $e) {
    admin_json_out($e);
}
