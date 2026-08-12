<?php

use PHPUnit\Framework\TestCase;

/**
 * Notification tests (Phase 5, spec 2.5): subject-prefix conventions, the .ics
 * calendar-import file (Eratotime: title + LOCATION = Meet link), the Gmail
 * address never appearing in anything the app sends, and outbox send/retry
 * state transitions. Uses an on_send hook to capture composed messages.
 */
final class NotifyLibTest extends TestCase
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

    private function seedRequest(array $questions = [['label' => 'Topic', 'answer' => 'DD']]): int
    {
        $pdo = $this->pdo();
        $pdo->prepare(
            "INSERT INTO request_log (tenant_id, meeting_type_id, invitee_name, invitee_email, invitee_timezone,
                guest_emails, requested_start_utc, requested_end_utc, custom_answers, status, soft_hold_expires_at)
             VALUES ((SELECT id FROM tenants WHERE slug='meertec'),
                (SELECT id FROM meeting_types WHERE slug='30-min' LIMIT 1),
                'Ada Lovelace', 'ada@example.com', 'Europe/London',
                '[\"grace@example.com\"]', '2026-08-20 09:00:00', '2026-08-20 09:30:00',
                ?, 'pending', '2026-08-21 09:00:00')"
        )->execute([json_encode($questions, JSON_UNESCAPED_UNICODE)]);
        return (int) $pdo->lastInsertId();
    }

    private function queueEmail(int $requestId, string $template, string $recipient): void
    {
        $this->pdo()->prepare(
            "INSERT INTO notification_outbox (tenant_id, request_log_id, channel, recipient, template, status)
             VALUES ((SELECT tenant_id FROM request_log WHERE id = ?), ?, 'email', ?, ?, 'pending')"
        )->execute([$requestId, $requestId, $recipient, $template]);
    }

    private function configWithCapture(&$captured, bool $ok = true): array
    {
        return ['smtp' => ['on_send' => function ($to, $subject, $html, $alt, $ics) use (&$captured, $ok) {
            $captured = ['to' => $to, 'subject' => $subject, 'html' => $html, 'ics' => $ics];
            return $ok;
        }]];
    }

    public function testOrganizerEmailSubjectAndIcsAttachment(): void
    {
        $requestId = $this->seedRequest();
        $this->queueEmail($requestId, 'organizer_request', 'stephen@meertec.ltd');

        $captured = null;
        notify_process_outbox($this->pdo(), $this->configWithCapture($captured), 10);

        self::assertNotNull($captured);
        self::assertSame('stephen@meertec.ltd', $captured['to']);
        self::assertStringStartsWith('[Eratotime Request] 30 Minute Meeting — Ada Lovelace', $captured['subject']);
        self::assertNotNull($captured['ics'], 'organizer email must carry the .ics import file');
        self::assertStringContainsString('SUMMARY:Eratotime: 30 Minute Meeting — Ada Lovelace', $captured['ics']);
        self::assertStringContainsString('DTSTART:20260820T090000Z', $captured['ics']);
        self::assertStringContainsString('Topic: DD', $captured['ics']);

        $row = $this->pdo()->query("SELECT status FROM notification_outbox WHERE request_log_id = {$requestId}")->fetchColumn();
        self::assertSame('sent', $row);
    }

    public function testIcsCarriesFixedMeetLinkLocation(): void
    {
        $pdo = $this->pdo();
        $pdo->prepare("UPDATE meeting_types SET location_details = 'https://meet.google.com/abc-defg-hij' WHERE slug = '30-min'")->execute();

        $request = $pdo->query("SELECT * FROM request_log LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($request === false) {
            $this->seedRequest();
            $request = $pdo->query("SELECT * FROM request_log LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
        $type = $pdo->query("SELECT * FROM meeting_types WHERE slug = '30-min'")->fetch(PDO::FETCH_ASSOC);
        $settings = $pdo->query("SELECT * FROM global_settings WHERE tenant_id = " . (int) $request['tenant_id'])->fetch(PDO::FETCH_ASSOC);

        $ics = notify_build_ics($request, $type, $settings);
        self::assertStringContainsString('LOCATION:https://meet.google.com/abc-defg-hij', $ics);
        self::assertStringNotContainsString('meertec.ltd@gmail.com', $ics);

        $pdo->prepare("UPDATE meeting_types SET location_details = NULL WHERE slug = '30-min'")->execute();
    }

    public function testInviteeEmailSubjectPrefix(): void
    {
        $requestId = $this->seedRequest();
        $this->queueEmail($requestId, 'invitee_confirmation', 'ada@example.com');

        $captured = null;
        notify_process_outbox($this->pdo(), $this->configWithCapture($captured), 10);

        self::assertSame('ada@example.com', $captured['to']);
        self::assertStringStartsWith('[Eratotime] Confirmation — 30 Minute Meeting', $captured['subject']);
        self::assertNull($captured['ics'], 'invitee email must NOT carry the .ics import file');
    }

    public function testGmailAddressNeverAppearsInComposedOutput(): void
    {
        $requestId = $this->seedRequest();
        $request = $this->pdo()->query("SELECT * FROM request_log WHERE id = {$requestId}")->fetch(PDO::FETCH_ASSOC);
        $type = $this->pdo()->query("SELECT * FROM meeting_types WHERE slug = '30-min'")->fetch(PDO::FETCH_ASSOC);
        $settings = $this->pdo()->query("SELECT * FROM global_settings WHERE tenant_id = " . (int) $request['tenant_id'])->fetch(PDO::FETCH_ASSOC);

        $invitee = notify_compose_invitee($request, $type, $settings);
        $organizer = notify_compose_organizer($request, $type, $settings);
        $ics = notify_build_ics($request, $type, $settings);

        $all = implode(' ', [$invitee['subject'], $invitee['html'], $invitee['alt'], $organizer['subject'], $organizer['html'], $organizer['alt'], $ics]);
        self::assertStringNotContainsString('meertec.ltd@gmail.com', $all);
        self::assertStringContainsString('stephen@meertec.ltd', $all);
    }

    public function testFailedSendBacksOffAndStaysPending(): void
    {
        $requestId = $this->seedRequest();
        $this->queueEmail($requestId, 'organizer_request', 'stephen@meertec.ltd');

        $captured = null;
        notify_process_outbox($this->pdo(), $this->configWithCapture($captured, false), 10);

        $row = $this->pdo()->query("SELECT status, attempts, next_retry_at FROM notification_outbox WHERE request_log_id = {$requestId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['next_retry_at']);

        // An immediate second run must NOT retry (backoff window).
        notify_process_outbox($this->pdo(), $this->configWithCapture($captured, false), 10);
        $row = $this->pdo()->query("SELECT attempts FROM notification_outbox WHERE request_log_id = {$requestId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['attempts'], 'row is in backoff — no retry yet');

        // Simulate time passing: clear the backoff, then it retries.
        $this->pdo()->prepare("UPDATE notification_outbox SET next_retry_at = NULL WHERE request_log_id = ?")->execute([$requestId]);
        notify_process_outbox($this->pdo(), $this->configWithCapture($captured, false), 10);
        $row = $this->pdo()->query("SELECT attempts FROM notification_outbox WHERE request_log_id = {$requestId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2, (int) $row['attempts']);
    }
}
