<?php

use PHPUnit\Framework\TestCase;

/**
 * Cleanup cron tests (Phase 7, spec 4.5): expired soft-holds are marked, old
 * terminal requests are purged per retention, active pending holds survive.
 */
final class CleanupLibTest extends TestCase
{
    private ?PDO $pdo = null;
    private int $tenantId = 0;

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec("DELETE FROM request_log WHERE tenant_id = {$this->tenantId()}");
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
        $has = $server->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $server->quote($name) . " AND table_name = 'tenants'")->fetchColumn();
        if ((int) $has === 0) {
            $server->exec(file_get_contents(__DIR__ . '/../db/eratotime_migration.sql'));
        }
        $server->exec("USE `{$name}`");
        $this->pdo = $server;
        return $this->pdo;
    }

    private function tenantId(): int
    {
        if ($this->tenantId === 0) {
            $this->tenantId = (int) $this->pdo()->query("SELECT id FROM tenants WHERE slug='meertec'")->fetchColumn();
        }
        return $this->tenantId;
    }

    private int $seq = 0;

    private function insertRequest(string $status, string $sentAt, string $holdExpires): int
    {
        $this->seq++;
        $start = '2026-08-20 09:00:00';
        $end = '2026-08-20 09:30:00';
        if ($this->seq > 1) {
            $mins = $this->seq * 60;
            $start = gmdate('Y-m-d H:i:s', strtotime($start) + $mins);
            $end = gmdate('Y-m-d H:i:s', strtotime($end) + $mins);
        }
        $this->pdo()->prepare(
            "INSERT INTO request_log (tenant_id, meeting_type_id, invitee_name, invitee_email, invitee_timezone,
                requested_start_utc, requested_end_utc, custom_answers, status, soft_hold_expires_at, sent_at)
             VALUES (?, (SELECT id FROM meeting_types WHERE slug='30-min' LIMIT 1), 'Ada', 'ada@example.com', 'UTC',
                ?, ?, '{}', ?, ?, ?)"
        )->execute([$this->tenantId(), $start, $end, $status, $holdExpires, $sentAt]);
        return (int) $this->pdo()->lastInsertId();
    }

    public function testExpiredSoftHoldsBecomeExpired(): void
    {
        $pdo = $this->pdo();
        $this->insertRequest('pending', gmdate('Y-m-d H:i:s'), '2020-01-01 00:00:00'); // hold passed
        $this->insertRequest('pending', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 3600)); // hold active

        $n = cleanup_expire_soft_holds($pdo, $this->tenantId());
        self::assertSame(1, $n);

        $rows = $pdo->query("SELECT status FROM request_log WHERE tenant_id = {$this->tenantId()} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['expired', 'pending'], $rows);
    }

    public function testOldTerminalRequestsPurgedButActiveHoldKept(): void
    {
        $pdo = $this->pdo();
        $oldFulfilled = $this->insertRequest('fulfilled', gmdate('Y-m-d H:i:s', time() - 40 * 86400), '2020-01-01 00:00:00');
        $oldCancelled = $this->insertRequest('cancelled', gmdate('Y-m-d H:i:s', time() - 40 * 86400), '2020-01-01 00:00:00');
        $recent = $this->insertRequest('fulfilled', gmdate('Y-m-d H:i:s', time() - 2 * 86400), gmdate('Y-m-d H:i:s'));
        $activeHold = $this->insertRequest('pending', gmdate('Y-m-d H:i:s', time() - 40 * 86400), gmdate('Y-m-d H:i:s', time() + 3600));

        $n = cleanup_purge_old_requests($pdo, $this->tenantId(), 30);
        self::assertSame(2, $n, 'old terminal requests purged');

        $ids = array_map('intval', $pdo->query("SELECT id FROM request_log WHERE tenant_id = {$this->tenantId()}")->fetchAll(PDO::FETCH_COLUMN));
        self::assertContains($recent, $ids);
        self::assertContains($activeHold, $ids, 'active pending hold must survive');
        self::assertNotContains($oldFulfilled, $ids);
        self::assertNotContains($oldCancelled, $ids);
    }

    public function testCleanupRunAggregates(): void
    {
        $pdo = $this->pdo();
        $this->insertRequest('pending', gmdate('Y-m-d H:i:s'), '2020-01-01 00:00:00');
        $this->insertRequest('cancelled', gmdate('Y-m-d H:i:s', time() - 40 * 86400), '2020-01-01 00:00:00');

        $result = cleanup_run($pdo, $this->tenantId(), ['request_log_retention_days' => 30]);
        self::assertSame(1, $result['expired']);
        self::assertSame(1, $result['purged']);

        $log = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE tenant_id = ? AND event_type = 'cleanup'");
        $log->execute([$this->tenantId()]);
        self::assertGreaterThan(0, (int) $log->fetchColumn());
    }
}
