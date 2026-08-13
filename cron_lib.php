<?php

/**
 * cron_lib.php — dispatcher-driven scheduled jobs (FIFA/cookingtogetherness
 * pattern). ONE system cron runs cron_dispatcher.php, which checks the
 * cron_jobs table and runs due jobs by handler (a PHP function name registered
 * below). The admin panel configures schedules and tracks last run/status.
 *
 * Handlers receive (PDO $pdo, array $config) so they are testable against the
 * test database without HTTP or shell.
 */

if (!function_exists('cron_pdo')) {

    function cron_pdo(array $config): PDO
    {
        return new PDO(sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['db']['host'],
            (int) ($config['db']['port'] ?? 3306),
            $config['db']['name'],
            $config['db']['charset'] ?? 'utf8mb4'
        ), $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    function cron_get_jobs(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM cron_jobs ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
    }

    function cron_get_job(PDO $pdo, string $key): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM cron_jobs WHERE job_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function cron_toggle_job(PDO $pdo, string $key): bool
    {
        $job = cron_get_job($pdo, $key);
        if ($job === null) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE cron_jobs SET enabled = ? WHERE job_key = ?');
        $stmt->execute([$job['enabled'] ? 0 : 1, $key]);
        return true;
    }

    function cron_update_schedule(PDO $pdo, string $key, int $scheduleMin): bool
    {
        if ($scheduleMin < 1 || $scheduleMin > 10080) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE cron_jobs SET schedule_min = ? WHERE job_key = ?');
        $stmt->execute([$scheduleMin, $key]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Due when never run or schedule_min elapsed since last_run_at.
     */
    function cron_is_due(array $job): bool
    {
        if (empty($job['enabled'])) {
            return false;
        }
        if (empty($job['last_run_at'])) {
            return true;
        }
        $next = strtotime((string) $job['last_run_at']) + ((int) $job['schedule_min'] * 60);
        return time() >= $next;
    }

    /**
     * Run one job by key. Returns ['ok'=>bool,'output'=>string].
     */
    function cron_run_job(PDO $pdo, array $config, string $key): array
    {
        $job = cron_get_job($pdo, $key);
        if ($job === null) {
            return ['ok' => false, 'output' => 'Job not found'];
        }
        if (empty($job['enabled'])) {
            return ['ok' => false, 'output' => 'Job is disabled'];
        }

        $start = microtime(true);
        $output = '';
        $status = 'success';

        try {
            $handler = (string) $job['handler'];
            if (function_exists($handler)) {
                $result = $handler($pdo, $config);
                $output = is_string($result) ? $result : (is_array($result) ? json_encode($result) : 'OK');
            } else {
                throw new RuntimeException("Handler not found: {$handler}");
            }
        } catch (Throwable $e) {
            $status = 'error';
            $output = 'Job failed: ' . $e->getMessage();
        }

        $elapsed = round(microtime(true) - $start, 2);
        $full = '[' . date('Y-m-d H:i:s') . "] ({$elapsed}s) {$output}";

        $stmt = $pdo->prepare(
            'UPDATE cron_jobs SET last_run_at = NOW(), last_status = ?, last_output = ?, run_count = run_count + 1 WHERE job_key = ?'
        );
        $stmt->execute([$status, $full, $key]);

        // Reflect in the activity log under the seeded tenant for the dashboard.
        $tid = $pdo->query("SELECT id FROM tenants WHERE slug = 'meertec' AND active = 1 LIMIT 1")->fetchColumn();
        if ($tid !== false) {
            $pdo->prepare("INSERT INTO activity_log (tenant_id, event_type, detail) VALUES (?, 'cron_job', ?)")
                ->execute([(int) $tid, json_encode(['job' => $key, 'status' => $status, 'output' => $output])]);
        }

        return ['ok' => $status === 'success', 'output' => $full];
    }

    /**
     * Run due jobs (or a single forced job).
     */
    function cron_run_due(PDO $pdo, array $config, ?string $only = null): array
    {
        $results = [];
        foreach (cron_get_jobs($pdo) as $job) {
            if ($only !== null && $job['job_key'] !== $only) {
                continue;
            }
            if ($only === null && !cron_is_due($job)) {
                continue;
            }
            $results[$job['job_key']] = cron_run_job($pdo, $config, $job['job_key']);
        }
        return $results;
    }

    // --- Job handlers --------------------------------------------------------

    function cron_task_sync_calendars(PDO $pdo, array $config): string
    {
        $results = calendar_sync_all($pdo, $config);
        $ok = 0;
        $failed = 0;
        $blocks = 0;
        foreach ($results as $r) {
            if ($r['ok']) {
                $ok++;
                $blocks += (int) $r['blocks'];
            } else {
                $failed++;
            }
        }
        return "sync_calendars OK: {$ok} sources ok, {$failed} failed, {$blocks} busy blocks";
    }

    function cron_task_retry_notifications(PDO $pdo, array $config): string
    {
        $results = notify_process_outbox($pdo, $config, 200);
        $sent = 0;
        $failed = 0;
        foreach ($results as $r) {
            $r['ok'] ? $sent++ : $failed++;
        }
        return "retry_notifications OK: {$sent} sent, {$failed} failed/retrying";
    }

    function cron_task_cleanup(PDO $pdo, array $config): string
    {
        $rows = $pdo->query(
            'SELECT t.id, g.request_log_retention_days FROM tenants t JOIN global_settings g ON g.tenant_id = t.id WHERE t.active = 1'
        )->fetchAll(PDO::FETCH_ASSOC);
        $expired = 0;
        $purged = 0;
        foreach ($rows as $row) {
            $r = cleanup_run($pdo, (int) $row['id'], ['request_log_retention_days' => (int) $row['request_log_retention_days']]);
            $expired += (int) $r['expired'];
            $purged += (int) $r['purged'];
        }
        return "cleanup OK: {$expired} soft-holds expired, {$purged} requests purged";
    }
}
