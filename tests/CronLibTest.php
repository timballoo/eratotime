<?php

use PHPUnit\Framework\TestCase;

/**
 * Cron dispatcher tests (FIFA/cookingtogetherness pattern): due-checking,
 * run/tracking updates, toggle, schedule update, and the three real handlers
 * against the local test DB.
 */

// Handler registered for tests (function_exists must resolve it).
function cron_test_handler(PDO $pdo, array $config): string
{
    return 'hello-from-test';
}

function cron_test_failing_handler(PDO $pdo, array $config): string
{
    throw new RuntimeException('boom');
}

final class CronLibTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->pdo = null;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec("DELETE FROM cron_jobs WHERE job_key NOT IN ('sync_calendars','retry_notifications','cleanup')");
            $this->pdo->exec("DELETE FROM request_log WHERE tenant_id = (SELECT id FROM tenants WHERE slug='meertec')");
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $host = getenv('ERATO_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('ERATO_TEST_DB_PORT') ?: '3307';
        $user = getenv('ERATO_TEST_DB_USER') ?: 'root';
        $pass = getenv('ERATO_TEST_DB_PASS') ?: '';
        $name = getenv('ERATO_TEST_DB_NAME') ?: 'eratotime_test';
        $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $server->exec("USE `{$name}`");
        $has = $server->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $server->quote($name) . " AND table_name = 'cron_jobs'")->fetchColumn();
        if ((int) $has === 0) {
            $server->exec(file_get_contents(__DIR__ . '/../db/eratotime_migration.sql'));
        }
        $server->exec("USE `{$name}`");
        $this->pdo = $server;
        return $this->pdo;
    }

    private function insertJob(string $key, string $handler, int $scheduleMin = 60, int $enabled = 1, ?string $lastRun = null): void
    {
        $this->pdo()->prepare(
            'INSERT INTO cron_jobs (job_key, title, handler, schedule_min, enabled, last_run_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE handler = VALUES(handler), schedule_min = VALUES(schedule_min), enabled = VALUES(enabled), last_run_at = VALUES(last_run_at)'
        )->execute([$key, ucfirst($key), $handler, $scheduleMin, $enabled, $lastRun]);
    }

    private function row(string $key): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM cron_jobs WHERE job_key = ?');
        $stmt->execute([$key]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testSeedHasTheThreeJobs(): void
    {
        $keys = array_column(cron_get_jobs($this->pdo()), 'job_key');
        self::assertContains('sync_calendars', $keys);
        self::assertContains('retry_notifications', $keys);
        self::assertContains('cleanup', $keys);
    }

    public function testIsDue(): void
    {
        self::assertTrue(cron_is_due(['enabled' => 1, 'last_run_at' => null, 'schedule_min' => 60]), 'never-run job is due');
        self::assertTrue(cron_is_due(['enabled' => 1, 'last_run_at' => date('Y-m-d H:i:s', time() - 120), 'schedule_min' => 1]), 'past window is due');
        self::assertFalse(cron_is_due(['enabled' => 1, 'last_run_at' => date('Y-m-d H:i:s'), 'schedule_min' => 60]), 'fresh run not due');
        self::assertFalse(cron_is_due(['enabled' => 0, 'last_run_at' => null, 'schedule_min' => 60]), 'disabled never due');
    }

    public function testRunJobTracksExecution(): void
    {
        $this->insertJob('test_ok', 'cron_test_handler', 60);
        $result = cron_run_job($this->pdo(), [], 'test_ok');
        self::assertTrue($result['ok']);
        self::assertStringContainsString('hello-from-test', $result['output']);

        $row = $this->row('test_ok');
        self::assertSame('success', $row['last_status']);
        self::assertNotNull($row['last_run_at']);
        self::assertSame(1, (int) $row['run_count']);
        self::assertStringContainsString('hello-from-test', $row['last_output']);
    }

    public function testFailedJobMarkedError(): void
    {
        $this->insertJob('test_fail', 'cron_test_failing_handler', 60);
        $result = cron_run_job($this->pdo(), [], 'test_fail');
        self::assertFalse($result['ok']);
        $row = $this->row('test_fail');
        self::assertSame('error', $row['last_status']);
        self::assertStringContainsString('boom', $row['last_output']);
    }

    public function testDisabledJobRefused(): void
    {
        $this->insertJob('test_disabled', 'cron_test_handler', 60, 0);
        $result = cron_run_job($this->pdo(), [], 'test_disabled');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('disabled', strtolower($result['output']));
    }

    public function testToggleAndScheduleUpdate(): void
    {
        $this->insertJob('test_toggle', 'cron_test_handler', 30);
        self::assertTrue(cron_toggle_job($this->pdo(), 'test_toggle'));
        self::assertSame(0, (int) $this->row('test_toggle')['enabled']);
        self::assertTrue(cron_update_schedule($this->pdo(), 'test_toggle', 120));
        self::assertSame(120, (int) $this->row('test_toggle')['schedule_min']);
        self::assertFalse(cron_update_schedule($this->pdo(), 'test_toggle', 0), 'schedule 0 rejected');
    }

    public function testRunDueOnlyRunsDueJobs(): void
    {
        $this->insertJob('due_job', 'cron_test_handler', 1, 1, null); // due
        $this->insertJob('fresh_job', 'cron_test_handler', 60, 1, date('Y-m-d H:i:s')); // not due
        $results = cron_run_due($this->pdo(), []);
        self::assertArrayHasKey('due_job', $results);
        self::assertArrayNotHasKey('fresh_job', $results);
    }

    public function testRealHandlersRunAgainstTestDb(): void
    {
        // sync with no active sources is a no-op summary; retry with empty outbox is fine; cleanup no-ops.
        $out = cron_task_sync_calendars($this->pdo(), ['smtp' => []]);
        self::assertStringContainsString('sync_calendars OK', $out);
        $out2 = cron_task_retry_notifications($this->pdo(), ['smtp' => []]);
        self::assertStringContainsString('retry_notifications OK', $out2);
        $out3 = cron_task_cleanup($this->pdo(), []);
        self::assertStringContainsString('cleanup OK', $out3);
    }
}
