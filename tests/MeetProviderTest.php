<?php

use PHPUnit\Framework\TestCase;

/**
 * Meet provider tests: configuration validation, meet_is_configured(), and
 * the notification fallback chain when dynamic Meet links are stored on
 * request_log.meet_link.  Tests the integration path without hitting the
 * live Google Calendar API.
 */
final class MeetProviderTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
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

    // --- meet_is_configured -------------------------------------------------

    public function testIsConfiguredReturnsFalseWhenEmpty(): void
    {
        self::assertFalse(meet_is_configured([]));
        self::assertFalse(meet_is_configured(['google_meet' => []]));
        self::assertFalse(meet_is_configured(['google_meet' => ['service_account_path' => '', 'calendar_id' => '']]));
    }

    public function testIsConfiguredReturnsFalseWhenPathMissing(): void
    {
        self::assertFalse(meet_is_configured([
            'google_meet' => [
                'service_account_path' => '/nonexistent/path.json',
                'calendar_id' => 'abc@group.calendar.google.com',
            ],
        ]));
    }

    public function testIsConfiguredReturnsTrueWhenBothPresent(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'meet_test_');
        file_put_contents($tmp, '{}');
        try {
            self::assertTrue(meet_is_configured([
                'google_meet' => [
                    'service_account_path' => $tmp,
                    'calendar_id' => 'abc@group.calendar.google.com',
                ],
            ]));
        } finally {
            @unlink($tmp);
        }
    }

    // --- meet_build_client --------------------------------------------------

    public function testBuildClientThrowsWhenPathMissing(): void
    {
        $this->expectException(RuntimeException::class);
        meet_build_client([]);
    }

    public function testBuildClientThrowsWhenFileNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        meet_build_client([
            'google_meet' => [
                'service_account_path' => '/no/such/file.json',
                'calendar_id' => 'abc@group.calendar.google.com',
            ],
        ]);
    }

    // --- meet_create_link ---------------------------------------------------

    public function testCreateLinkReturnsNullWhenCalendarIdEmpty(): void
    {
        $result = meet_create_link(
            ['google_meet' => ['service_account_path' => '', 'calendar_id' => '']],
            'test',
            new DateTimeImmutable('2026-08-20 09:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-20 09:30:00', new DateTimeZone('UTC')),
            'Europe/London'
        );
        self::assertNull($result);
    }

    // --- meet_delete_event --------------------------------------------------

    public function testDeleteEventReturnsFalseWhenCalendarIdEmpty(): void
    {
        self::assertFalse(meet_delete_event([], 'some-event-id'));
    }

    public function testDeleteEventReturnsFalseWhenEventIdEmpty(): void
    {
        self::assertFalse(meet_delete_event(
            ['google_meet' => ['calendar_id' => 'abc@group.calendar.google.com']],
            ''
        ));
    }

    // --- notify_meeting_location fallback chain with dynamic links -----------

    public function testDynamicMeetLinkTakesPriorityOverStaticVideoLink(): void
    {
        $request = [
            'meet_link' => 'https://meet.google.com/dynamic-unique-xyz',
            'video_call' => 1,
        ];
        $type = ['video_link' => 'https://meet.google.com/static-abc', 'location_details' => 'Phone call'];
        $settings = ['meet_link' => 'https://meet.google.com/global-fallback'];

        $location = notify_meeting_location($request, $type, $settings);
        self::assertSame('https://meet.google.com/dynamic-unique-xyz', $location);
    }

    public function testStaticVideoLinkUsedWhenNoDynamicLink(): void
    {
        $request = ['meet_link' => null, 'video_call' => 1];
        $type = ['video_link' => 'https://meet.google.com/static-abc', 'location_details' => 'Phone call'];
        $settings = ['meet_link' => 'https://meet.google.com/global-fallback'];

        $location = notify_meeting_location($request, $type, $settings);
        self::assertSame('https://meet.google.com/static-abc', $location);
    }

    public function testGlobalMeetLinkFallbackWhenNoPerTypeOrDynamic(): void
    {
        $request = ['meet_link' => null, 'video_call' => 0];
        $type = ['video_link' => '', 'location_details' => ''];
        $settings = ['meet_link' => 'https://meet.google.com/global-fallback'];

        $location = notify_meeting_location($request, $type, $settings);
        self::assertSame('https://meet.google.com/global-fallback', $location);
    }

    public function testLocationDetailsUsedWhenNoVideoLinkAndNoDynamic(): void
    {
        $request = ['meet_link' => null, 'video_call' => 0];
        $type = ['video_link' => '', 'location_details' => '123 Main St'];
        $settings = ['meet_link' => 'https://meet.google.com/global-fallback'];

        $location = notify_meeting_location($request, $type, $settings);
        self::assertSame('123 Main St', $location);
    }

    public function testNullWhenNothingConfigured(): void
    {
        $request = ['meet_link' => null, 'video_call' => 0];
        $type = ['video_link' => '', 'location_details' => ''];
        $settings = [];

        $location = notify_meeting_location($request, $type, $settings);
        self::assertNull($location);
    }

    // --- request_log.meet_link stored correctly -----------------------------

    public function testMeetLinkStoredOnRequestLog(): void
    {
        $pdo = $this->pdo();
        $pdo->prepare(
            "INSERT INTO request_log (tenant_id, meeting_type_id, invitee_name, invitee_email, invitee_timezone,
                requested_start_utc, requested_end_utc, meet_link, status, soft_hold_expires_at)
             VALUES ((SELECT id FROM tenants WHERE slug='meertec'),
                (SELECT id FROM meeting_types WHERE slug='30-min' LIMIT 1),
                'Test User', 'test@example.com', 'Europe/London',
                '2026-08-20 09:00:00', '2026-08-20 09:30:00',
                'https://meet.google.com/unique-booking-123', 'pending', '2026-08-21 09:00:00')"
        )->execute();
        $requestId = (int) $pdo->lastInsertId();

        $row = $pdo->query("SELECT meet_link FROM request_log WHERE id = {$requestId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('https://meet.google.com/unique-booking-123', $row['meet_link']);

        // Cleanup
        $pdo->prepare("DELETE FROM request_log WHERE id = ?")->execute([$requestId]);
    }

    public function testMeetLinkNullWhenDynamicDisabled(): void
    {
        $pdo = $this->pdo();
        $pdo->prepare(
            "INSERT INTO request_log (tenant_id, meeting_type_id, invitee_name, invitee_email, invitee_timezone,
                requested_start_utc, requested_end_utc, status, soft_hold_expires_at)
             VALUES ((SELECT id FROM tenants WHERE slug='meertec'),
                (SELECT id FROM meeting_types WHERE slug='30-min' LIMIT 1),
                'Test User', 'test@example.com', 'Europe/London',
                '2026-08-20 09:00:00', '2026-08-20 09:30:00',
                'pending', '2026-08-21 09:00:00')"
        )->execute();
        $requestId = (int) $pdo->lastInsertId();

        $row = $pdo->query("SELECT meet_link FROM request_log WHERE id = {$requestId}")->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['meet_link']);

        $pdo->prepare("DELETE FROM request_log WHERE id = ?")->execute([$requestId]);
    }
}
