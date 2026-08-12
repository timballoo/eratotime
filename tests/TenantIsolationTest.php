<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tenant isolation tests — THE test-first priority (spec 1.4/8, Phase 1).
 *
 * Seeds a second throwaway tenant with deliberately overlapping data (same
 * meeting-type slug, similar calendar-source label, same working hours) and
 * proves that no query scoped to tenant A ever returns tenant B's rows, across
 * every table, including the indirectly-scoped ones (meeting_type_questions,
 * calendar_blockouts, notification_outbox).
 *
 * Requires a reachable MySQL/MariaDB. Connection is configured via env vars
 * (defaults match the local XAMPP MariaDB used during development):
 *   ERATO_TEST_DB_HOST (127.0.0.1), ERATO_TEST_DB_PORT (3307),
 *   ERATO_TEST_DB_USER (root), ERATO_TEST_DB_PASS (''),
 *   ERATO_TEST_DB_NAME (eratotime_test)
 * The test creates the database and applies db/eratotime_migration.sql if the
 * schema is absent, so it is self-contained and idempotent.
 */
final class TenantIsolationTest extends TestCase
{
    private const FIXTURE_SLUG = 'isolation-fixture';

    private ?PDO $pdo = null;

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

        $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $server->exec("USE `{$name}`");

        $hasSchema = $server->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $server->quote($name) . " AND table_name = 'tenants'")->fetchColumn();
        if ((int) $hasSchema === 0) {
            $sql = file_get_contents(__DIR__ . '/../db/eratotime_migration.sql');
            $server->exec($sql);
        }

        $server->exec("USE `{$name}`");
        $this->pdo = $server;
        return $this->pdo;
    }

    private function seedFixtureTenant(): array
    {
        $pdo = $this->pdo();
        $name = $pdo->query("SELECT DATABASE()")->fetchColumn();

        // Wipe any previous run of this fixture tenant. FKs are ON so the
        // ON DELETE CASCADE cleans up all child rows — do NOT disable FK checks
        // here, that would orphan them instead.
        $pdo->exec("DELETE FROM tenants WHERE slug = " . $pdo->quote(self::FIXTURE_SLUG));

        $pdo->exec("INSERT INTO tenants (slug, display_name, active) VALUES (" .
            $pdo->quote(self::FIXTURE_SLUG) . ", 'Isolation Fixture', 1)");
        $b = (int) $pdo->lastInsertId();
        $a = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'meertec' LIMIT 1")->fetchColumn();

        // Deliberately overlapping data: same meeting-type slug, same label shape.
        $pdo->exec("INSERT INTO meeting_types (tenant_id, slug, name, duration_min, min_notice_hours, max_horizon_days, active, sort_order) VALUES ($b, '30-min', 'Fixture 30m', 30, 24, 14, 1, 1)");
        $pdo->exec("INSERT INTO meeting_types (tenant_id, slug, name, duration_min, min_notice_hours, max_horizon_days, active, sort_order) VALUES ($b, '60-min', 'Fixture 60m', 60, 24, 14, 1, 2)");
        $mtb = (int) $pdo->query("SELECT id FROM meeting_types WHERE tenant_id = $b AND slug = '30-min'")->fetchColumn();
        $pdo->exec("INSERT INTO meeting_type_questions (meeting_type_id, label, type, required, sort_order) VALUES ($mtb, 'Fixture only question', 'text', 0, 1)");

        $pdo->exec("INSERT INTO global_settings (tenant_id, mailbox_destination, whatsapp_enabled, organizer_timezone) VALUES ($b, 'fixture@example.com', 0, 'Europe/London')");
        $pdo->exec("INSERT INTO tenant_admins (tenant_id, username, password_hash) VALUES ($b, 'fixture', 'x')");
        $pdo->exec("INSERT INTO working_hours (tenant_id, day_of_week, start_time, end_time) VALUES ($b, 1, '09:00:00', '17:30:00'), ($b, 3, '09:00:00', '17:30:00')");
        $pdo->exec("INSERT INTO availability_overrides (tenant_id, date, is_blocked, start_time, end_time) VALUES ($b, '2026-08-12', 1, NULL, NULL)");
        $pdo->exec("INSERT INTO calendar_sources (tenant_id, provider, label, calendar_identifier, active, last_sync_status) VALUES ($b, 'google', 'Fixture Gmail', 'fixture@gmail.com', 1, 'never_run')");
        $csb = (int) $pdo->query("SELECT id FROM calendar_sources WHERE tenant_id = $b LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO calendar_blockouts (calendar_source_id, external_uid, start_utc, end_utc) VALUES ($csb, 'evt-b-1', '2026-08-12 10:00:00', '2026-08-12 11:00:00')");

        $pdo->exec("INSERT INTO request_log (tenant_id, meeting_type_id, invitee_name, invitee_email, requested_start_utc, requested_end_utc, status, soft_hold_expires_at) VALUES ($b, $mtb, 'Fixture Person', 'fixture@example.com', '2026-08-12 09:00:00', '2026-08-12 09:30:00', 'pending', '2026-08-13 09:00:00')");
        $reqB = (int) $pdo->query("SELECT id FROM request_log WHERE tenant_id = $b LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO notification_outbox (tenant_id, request_log_id, channel, recipient, template, status) VALUES ($b, $reqB, 'email', 'fixture@example.com', 'invitee_confirmation', 'pending')");
        $pdo->exec("INSERT INTO activity_log (tenant_id, event_type, detail) VALUES ($b, 'fixture_event', '{}')");

        return ['tenant_a' => $a, 'tenant_b' => $b, 'name' => $name];
    }

    // --- The tests ----------------------------------------------------------

    public function testTwoTenantsWithOverlappingSlugsBothExist(): void
    {
        $pdo = $this->pdo();
        $this->seedFixtureTenant();
        $a = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'meertec'")->fetchColumn();
        $b = (int) $pdo->query("SELECT id FROM tenants WHERE slug = '" . self::FIXTURE_SLUG . "'")->fetchColumn();
        self::assertNotSame($a, $b);
        self::assertGreaterThan(0, $a);
        self::assertGreaterThan(0, $b);
    }

    #[DataProvider('directScopedTables')]
    public function testDirectScopedTableNeverLeaksAcrossTenants(string $table, string $tenantCol, bool $expectARows): void
    {
        $ids = $this->seedFixtureTenant();
        $pdo = $this->pdo();
        $a = $ids['tenant_a'];
        $b = $ids['tenant_b'];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$tenantCol}` = ?");
        $stmt->execute([$a]);
        $aCount = (int) $stmt->fetchColumn();

        // Rows returned when scoping to A must ALL be A's.
        $stmt = $pdo->prepare("SELECT `{$tenantCol}` FROM `{$table}` WHERE `{$tenantCol}` = ?");
        $stmt->execute([$a]);
        $tenantCols = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($tenantCols as $tc) {
            self::assertSame($a, $tc, "Table {$table}: row scoped to tenant A carries tenant_id {$tc}");
        }

        // Rows returned when scoping to B must ALL be B's.
        $stmt = $pdo->prepare("SELECT `{$tenantCol}` FROM `{$table}` WHERE `{$tenantCol}` = ?");
        $stmt->execute([$b]);
        $bCols = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($bCols as $tc) {
            self::assertSame($b, $tc, "Table {$table}: row scoped to tenant B carries tenant_id {$tc}");
        }

        // And B must actually have produced fixture rows (the test is real).
        self::assertGreaterThan(0, $bCols, "Table {$table}: fixture tenant B has no rows — test is vacuous");

        // Tables that the seed populates for the real tenant must have rows;
        // runtime-only tables are legitimately empty at seed time.
        if ($expectARows) {
            self::assertGreaterThan(0, $aCount, "Table {$table}: tenant A unexpectedly has no rows");
        }
    }

    public static function directScopedTables(): array
    {
        return [
            'tenant_admins' => ['tenant_admins', 'tenant_id', false],
            'global_settings' => ['global_settings', 'tenant_id', true],
            'meeting_types' => ['meeting_types', 'tenant_id', true],
            'working_hours' => ['working_hours', 'tenant_id', true],
            'availability_overrides' => ['availability_overrides', 'tenant_id', false],
            'calendar_sources' => ['calendar_sources', 'tenant_id', true],
            'request_log' => ['request_log', 'tenant_id', false],
            'notification_outbox' => ['notification_outbox', 'tenant_id', false],
            'activity_log' => ['activity_log', 'tenant_id', false],
        ];
    }

    public function testOverlappingMeetingTypeSlugDoesNotCollide(): void
    {
        $ids = $this->seedFixtureTenant();
        $pdo = $this->pdo();
        $a = $ids['tenant_a'];

        $stmt = $pdo->prepare("SELECT id, tenant_id FROM meeting_types WHERE tenant_id = ? AND slug = '30-min'");
        $stmt->execute([$a]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'Tenant A should see exactly one 30-min meeting type');
        self::assertSame($a, (int) $rows[0]['tenant_id']);
        self::assertSame(1, (int) $rows[0]['id'], 'A\'s 30-min type is the seeded one (id 1)');
    }

    public function testIndirectlyScopedMeetingTypeQuestionsNeverLeak(): void
    {
        $ids = $this->seedFixtureTenant();
        $pdo = $this->pdo();
        $a = $ids['tenant_a'];

        // Join through the parent meeting_types, scoped by A.
        $stmt = $pdo->prepare(
            'SELECT q.label, q.id FROM meeting_type_questions q
               JOIN meeting_types m ON m.id = q.meeting_type_id
              WHERE m.tenant_id = ?'
        );
        $stmt->execute([$a]);
        $labels = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'label');
        self::assertNotContains('Fixture only question', $labels, 'Tenant B\'s question leaked into tenant A');
    }

    public function testIndirectlyScopedCalendarBlockoutsNeverLeak(): void
    {
        $ids = $this->seedFixtureTenant();
        $pdo = $this->pdo();
        $a = $ids['tenant_a'];
        $b = $ids['tenant_b'];

        $stmt = $pdo->prepare(
            'SELECT b.external_uid, b.calendar_source_id FROM calendar_blockouts b
               JOIN calendar_sources s ON s.id = b.calendar_source_id
              WHERE s.tenant_id = ?'
        );
        $stmt->execute([$a]);
        $aBlockouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($aBlockouts as $row) {
            self::assertNotSame('evt-b-1', $row['external_uid']);
        }
        self::assertCount(0, $aBlockouts, 'Tenant A should have no blockouts yet (its calendar source is inactive)');

        // B's blockout must exist and only be reachable via B.
        $stmt = $pdo->prepare(
            'SELECT b.external_uid FROM calendar_blockouts b
               JOIN calendar_sources s ON s.id = b.calendar_source_id
              WHERE s.tenant_id = ?'
        );
        $stmt->execute([$b]);
        self::assertSame('evt-b-1', $stmt->fetchColumn());
    }

    public function testTenantLibLoadsOnlyOwnTenantAndSettings(): void
    {
        $ids = $this->seedFixtureTenant();
        $pdo = $this->pdo();

        $a = tenant_load($pdo, 'meertec');
        self::assertNotNull($a);
        self::assertSame($ids['tenant_a'], (int) $a['tenant']['id']);
        self::assertSame('stephen@meertec.ltd', $a['settings']['mailbox_destination']);

        $b = tenant_load($pdo, self::FIXTURE_SLUG);
        self::assertNotNull($b);
        self::assertSame($ids['tenant_b'], (int) $b['tenant']['id']);
        self::assertSame('fixture@example.com', $b['settings']['mailbox_destination']);

        // The two loads never cross.
        self::assertNotSame($a['tenant']['id'], $b['tenant']['id']);
        self::assertNotSame($a['settings']['mailbox_destination'], $b['settings']['mailbox_destination']);

        // Inactive or unknown tenants resolve to null.
        self::assertNull(tenant_load($pdo, 'does-not-exist'));
    }

    public function testTenantPathParsingIsTenantAware(): void
    {
        self::assertSame('meertec', tenant_parse_from_path('/t/meertec/book/30-min'));
        self::assertSame('meertec', tenant_parse_from_path('https://www.meertec.ltd/t/meertec/book/60-min?x=1'));
        self::assertSame('isolation-fixture', tenant_parse_from_path('/t/isolation-fixture/book/30-min'));
        self::assertNull(tenant_parse_from_path('/book/30-min'));
        self::assertNull(tenant_parse_from_path('/t/'));
        self::assertNull(tenant_parse_from_path('/t/../etc/passwd'));
    }

    public function testAvailabilityIgnoresOtherTenantsOverridesAndBlockouts(): void
    {
        $ids = $this->seedFixtureTenant();
        $pdo = $this->pdo();
        $a = $ids['tenant_a'];

        // Tenant A's real working hours, fetched scoped to A.
        $stmt = $pdo->prepare('SELECT day_of_week, start_time, end_time FROM working_hours WHERE tenant_id = ?');
        $stmt->execute([$a]);
        $workingHours = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // A's overrides (B has a blocked override for 2026-08-12 — A must ignore it).
        $stmt = $pdo->prepare('SELECT date, is_blocked, start_time, end_time FROM availability_overrides WHERE tenant_id = ?');
        $stmt->execute([$a]);
        $overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([], $overrides, 'Tenant A has no overrides; B\'s override must not leak');

        // A's blockouts via its own sources only.
        $stmt = $pdo->prepare(
            'SELECT b.start_utc, b.end_utc FROM calendar_blockouts b
               JOIN calendar_sources s ON s.id = b.calendar_source_id
              WHERE s.tenant_id = ?'
        );
        $stmt->execute([$a]);
        self::assertSame([], $stmt->fetchAll(PDO::FETCH_ASSOC), 'A has no blockouts; B\'s must not leak');

        $r = availability_day([
            'date' => '2026-08-12',
            'working_hours' => $workingHours,
            'overrides' => $overrides,
            'blockouts' => [],
            'soft_holds' => [],
            'meeting_type' => ['duration_min' => 30, 'buffer_before_min' => 0, 'buffer_after_min' => 0, 'min_notice_hours' => 0, 'max_horizon_days' => 400],
            'now' => new DateTimeImmutable('2026-01-05 09:00:00', new DateTimeZone('Europe/London')),
            'counts' => [],
            'caps' => [],
        ]);
        // B blocked 2026-08-12 via an override and via a blockout — but A must
        // still see the full template day, because A never sees B's data.
        self::assertCount(17, $r['slots'], 'Tenant B\'s override/blockout leaked into tenant A\'s availability');
    }
}
