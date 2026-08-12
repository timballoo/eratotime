<?php

use PHPUnit\Framework\TestCase;

/**
 * Request submission tests (Phase 5, spec 2.3/4.2/4.5) against the local test
 * DB: successful submit creates a pending request + outbox rows; soft-holds
 * block the same slot; duplicates and invalid inputs are rejected; stale sync
 * fails closed. SMTP is in dev mode (no host) so sends are no-ops.
 */
final class RequestSubmitTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec(
                "DELETE FROM request_log WHERE tenant_id = (SELECT id FROM tenants WHERE slug='meertec')"
            );
            $this->pdo->exec("UPDATE calendar_sources SET active = 0, last_synced_at = NULL WHERE provider = 'caldav'");
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

    private function config(): array
    {
        return ['smtp' => []]; // dev mode: notify sends are no-ops
    }

    /** An open slot at 10:00 London on the next weekday at/after $minDayOffset, as UTC ISO. */
    private function openSlotUtc(int $minDayOffset, string $type = '30-min'): string
    {
        $tz = new DateTimeZone('Europe/London');
        $base = new DateTimeImmutable('now', $tz);
        $d = $minDayOffset;
        while (true) {
            $candidate = $base->modify("+{$d} days");
            if ((int) $candidate->format('N') <= 5) { // Mon..Fri
                $date = $candidate->format('Y-m-d');
                return (new DateTimeImmutable($date . ' 10:00:00', $tz))->setTimezone(new DateTimeZone('UTC'))->format('c');
            }
            $d++;
        }
    }

    private function baseInput(int $dayOffset, string $email = 'ada@example.com'): array
    {
        return [
            'tenant' => 'meertec',
            'type' => '30-min',
            'slot_utc' => $this->openSlotUtc($dayOffset),
            'name' => 'Ada Lovelace',
            'email' => $email,
            'timezone' => 'Europe/London',
            'questions' => [['label' => 'Topic', 'answer' => 'Due diligence']],
            'guests' => ['grace@example.com'],
        ];
    }

    public function testSubmitCreatesPendingRequestAndOutbox(): void
    {
        $pdo = $this->pdo();
        $result = request_submit($pdo, $this->config(), $this->baseInput(3));
        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertGreaterThan(0, (int) $result['request_id']);

        $req = $pdo->prepare('SELECT * FROM request_log WHERE id = ?');
        $req->execute([$result['request_id']]);
        $row = $req->fetch(PDO::FETCH_ASSOC);
        self::assertSame('pending', $row['status']);
        self::assertSame('Ada Lovelace', $row['invitee_name']);
        self::assertSame('Europe/London', $row['invitee_timezone']);
        self::assertGreaterThan(time(), strtotime((string) $row['soft_hold_expires_at']));

        $out = $pdo->prepare('SELECT template, recipient, status FROM notification_outbox WHERE request_log_id = ? ORDER BY template');
        $out->execute([$result['request_id']]);
        $rows = $out->fetchAll(PDO::FETCH_ASSOC);
        $templates = array_column($rows, 'template');
        self::assertContains('invitee_confirmation', $templates);
        self::assertContains('organizer_request', $templates);
        $orgRow = array_values(array_filter($rows, fn($r) => $r['template'] === 'organizer_request'))[0];
        self::assertSame('stephen@meertec.ltd', $orgRow['recipient']);
        self::assertSame('sent', $orgRow['status']); // dev-mode send = success
    }

    public function testSoftHoldBlocksSecondBookingOfSameSlot(): void
    {
        $pdo = $this->pdo();
        $input = $this->baseInput(4);
        self::assertTrue(request_submit($pdo, $this->config(), $input)['ok']);

        $second = $input;
        $second['email'] = 'grace@example.com'; // different person, same slot
        $res = request_submit($pdo, $this->config(), $second);
        self::assertFalse($res['ok']);
        self::assertStringContainsString('no longer available', $res['error']);
    }

    public function testIdenticalDuplicateSubmissionRejected(): void
    {
        $pdo = $this->pdo();
        $input = $this->baseInput(5);
        self::assertTrue(request_submit($pdo, $this->config(), $input)['ok']);
        $res = request_submit($pdo, $this->config(), $input); // same person, same slot
        self::assertFalse($res['ok']);
    }

    public function testInvalidEmailRejected(): void
    {
        $input = $this->baseInput(6);
        $input['email'] = 'not-an-email';
        $res = request_submit($this->pdo(), $this->config(), $input);
        self::assertFalse($res['ok']);
        self::assertStringContainsString('email', strtolower($res['error']));
    }

    public function testMissingNameRejected(): void
    {
        $input = $this->baseInput(6);
        $input['name'] = '   ';
        $res = request_submit($this->pdo(), $this->config(), $input);
        self::assertFalse($res['ok']);
    }

    public function testUnknownTypeRejected(): void
    {
        $input = $this->baseInput(6);
        $input['type'] = 'does-not-exist';
        $res = request_submit($this->pdo(), $this->config(), $input);
        self::assertFalse($res['ok']);
    }

    public function testStaleSyncSourceFailsClosed(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("UPDATE calendar_sources SET active = 1, last_synced_at = '2020-01-01 00:00:00' WHERE provider = 'caldav'");
        $res = request_submit($pdo, $this->config(), $this->baseInput(7));
        self::assertFalse($res['ok']);
        self::assertStringContainsString('temporarily unavailable', $res['error']);
    }
}
