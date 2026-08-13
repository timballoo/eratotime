<?php

use PHPUnit\Framework\TestCase;

/**
 * Admin panel core tests (Phase 6): grid save/load round-trip (template +
 * override modes), request fulfil/cancel, meeting type CRUD, and login
 * rate-limiting. Runs against the local test DB.
 */
final class AdminLibTest extends TestCase
{
    private ?PDO $pdo = null;
    private int $tenantId = 0;

    protected function setUp(): void
    {
        $this->pdo = null;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            // Reset the real tenant's working hours to the seed default.
            $this->pdo->exec(
                "DELETE FROM working_hours WHERE tenant_id = {$this->tenantId()}"
            );
            $stmt = $this->pdo->prepare(
                'INSERT INTO working_hours (tenant_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)'
            );
            for ($d = 1; $d <= 5; $d++) {
                $stmt->execute([$this->tenantId(), $d, '09:00:00', '17:30:00']);
            }
            $this->pdo->exec("DELETE FROM availability_overrides WHERE tenant_id = {$this->tenantId()}");
            $this->pdo->exec(
                "DELETE FROM request_log WHERE tenant_id = (SELECT id FROM tenants WHERE slug='meertec')"
            );
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

    private function cellIndexesFor(string $start, string $end): array
    {
        $s = availability_parse_time($start);
        $e = availability_parse_time($end);
        return admin_range_cells([['start' => $start, 'end' => $end]]);
    }

    public function testTemplateSaveRoundTrip(): void
    {
        $pdo = $this->pdo();
        // Monday: open 09:00-11:00 only (4 cells), block everything else.
        $monday = '2026-08-17';
        admin_grid_save($pdo, $this->tenantId(), $monday, 'template', [
            0 => $this->cellIndexesFor('09:00', '11:00'), // Mon
            1 => [], // Tue closed
        ]);

        $grid = admin_grid_load($pdo, $this->tenantId(), $monday);
        $days = array_column($grid['days'], 'cells', 'day_of_week');
        // Monday cells for 09:00-11:00 are open (cells 4-7), rest blocked.
        $mon = $days[1];
        for ($i = 0; $i < $grid['cell_count']; $i++) {
            if ($i >= 4 && $i < 8) {
                self::assertSame('open', $mon[$i], "cell {$i} should be open");
            } else {
                self::assertSame('blocked', $mon[$i], "cell {$i} should be blocked");
            }
        }
        // Tuesday closed entirely.
        self::assertSame('blocked', $days[2][4]);

        // The engine now sees Monday as 09:00-11:00 only.
        $wh = $pdo->query("SELECT start_time, end_time FROM working_hours WHERE tenant_id = {$this->tenantId()} AND day_of_week = 1")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([['start_time' => '09:00:00', 'end_time' => '11:00:00']], $wh);
    }

    public function testOverrideSaveAndBlockedRoundTrip(): void
    {
        $pdo = $this->pdo();
        $monday = '2026-08-17';
        $wed = '2026-08-19';

        admin_grid_save($pdo, $this->tenantId(), $monday, 'override', [
            $wed => $this->cellIndexesFor('10:00', '11:00'),
            '2026-08-20' => 'blocked', // Thu fully blocked
        ]);

        $grid = admin_grid_load($pdo, $this->tenantId(), $monday);
        foreach ($grid['days'] as $day) {
            if ($day['date'] === $wed) {
                self::assertTrue($day['has_override']);
                for ($i = 0; $i < $grid['cell_count']; $i++) {
                    $expect = ($i >= 6 && $i < 8) ? 'open' : 'blocked';
                    self::assertSame($expect, $day['cells'][$i], "Wed cell {$i} should be {$expect}");
                }
            }
            if ($day['date'] === '2026-08-20') {
                self::assertTrue($day['has_override']);
                self::assertContains('blocked', array_unique($day['cells']));
                self::assertNotContains('open', array_unique($day['cells']));
            }
        }
    }

    public function testRequestMarkFulfilledAndCancelled(): void
    {
        $pdo = $this->pdo();
        $pdo->prepare(
            "INSERT INTO request_log (tenant_id, meeting_type_id, invitee_name, invitee_email, invitee_timezone,
                requested_start_utc, requested_end_utc, custom_answers, status, soft_hold_expires_at)
             VALUES (?, (SELECT id FROM meeting_types WHERE slug='30-min' LIMIT 1), 'Ada', 'ada@example.com', 'UTC',
                '2026-08-20 09:00:00', '2026-08-20 09:30:00', '{}', 'pending', '2026-08-21 09:00:00')"
        )->execute([$this->tenantId()]);
        $id = (int) $pdo->lastInsertId();

        self::assertTrue(admin_request_set_status($pdo, $id, 'fulfilled'));
        $status = $pdo->query("SELECT status FROM request_log WHERE id = {$id}")->fetchColumn();
        self::assertSame('fulfilled', $status);

        self::assertTrue(admin_request_set_status($pdo, $id, 'cancelled'));
        $status = $pdo->query("SELECT status FROM request_log WHERE id = {$id}")->fetchColumn();
        self::assertSame('cancelled', $status);

        self::assertFalse(admin_request_set_status($pdo, $id, 'bogus'));
        self::assertFalse(admin_request_set_status($pdo, 999999, 'fulfilled'));
    }

    public function testMeetingTypeCreateUpdateDelete(): void
    {
        $pdo = $this->pdo();
        $r = admin_meeting_type_save($pdo, $this->tenantId(), [
            'slug' => 'intro-call', 'name' => 'Intro Call', 'duration_min' => 30,
            'video_link' => 'https://meet.google.com/xyz-abc-def',
            'questions' => [['label' => 'Company', 'type' => 'text', 'required' => 1, 'sort_order' => 0]],
        ]);
        self::assertTrue($r['ok']);
        $id = $r['id'];

        $types = admin_meeting_types_list($pdo, $this->tenantId());
        $found = array_values(array_filter($types, fn($t) => $t['slug'] === 'intro-call'));
        self::assertCount(1, $found);
        self::assertCount(1, $found[0]['questions']);
        self::assertSame('Company', $found[0]['questions'][0]['label']);
        self::assertSame('https://meet.google.com/xyz-abc-def', $found[0]['video_link']);

        // Slug collision rejected.
        $dup = admin_meeting_type_save($pdo, $this->tenantId(), ['slug' => 'intro-call', 'name' => 'X', 'duration_min' => 30]);
        self::assertFalse($dup['ok']);

        self::assertTrue(admin_meeting_type_delete($pdo, $this->tenantId(), $id));
        self::assertFalse(admin_meeting_type_delete($pdo, $this->tenantId(), 999999));
    }

    public function testLoginRateLimited(): void
    {
        $config = [
            'admin' => ['username' => 'admin', 'password_hash' => password_hash('pw123', PASSWORD_DEFAULT)],
            'runtime_dir' => sys_get_temp_dir() . '/eratotime-admin-' . bin2hex(random_bytes(4)),
        ];
        for ($i = 0; $i < 5; $i++) {
            $ok = admin_attempt_login($config, 'admin', 'wrong', '203.0.113.9')['ok'];
            self::assertFalse($ok);
        }
        $locked = admin_attempt_login($config, 'admin', 'pw123', '203.0.113.9');
        self::assertFalse($locked['ok'], '6th attempt must be rate-limited');
        self::assertStringContainsString('Too many', $locked['error']);

        // A different IP is not blocked.
        self::assertTrue(admin_attempt_login($config, 'admin', 'pw123', '203.0.113.10')['ok']);

        if (is_dir($config['runtime_dir'])) {
            foreach (glob($config['runtime_dir'] . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($config['runtime_dir']);
        }
    }
}
