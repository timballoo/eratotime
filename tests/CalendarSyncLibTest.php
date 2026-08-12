<?php

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;

/**
 * Calendar sync orchestration tests (Phase 3, spec 3.5) against the local test
 * DB. Uses the seeded 'caldav' source row for the real tenant, a temp crypto
 * key, and a mock HTTP transport — no live Baikal and no real credentials.
 *
 * Each test cleans up after itself (blockouts deleted, source reset to
 * inactive) so the tenant-isolation suite's "tenant A has no blockouts"
 * assertions stay valid regardless of execution order.
 */
final class CalendarSyncLibTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $keyPath;
    private array $config;

    protected function setUp(): void
    {
        $this->pdo = null;
        $this->keyPath = sys_get_temp_dir() . '/eratotime-sync-' . bin2hex(random_bytes(4)) . '.key';
        $this->config = ['encryption_key_path' => $this->keyPath];
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec(
                "DELETE cb FROM calendar_blockouts cb
                  JOIN calendar_sources s ON s.id = cb.calendar_source_id
                 WHERE s.provider = 'caldav'"
            );
            $this->pdo->exec("UPDATE calendar_sources SET active = 0, credentials_encrypted = NULL WHERE provider = 'caldav'");
        }
        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
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

    /** Set the seeded caldav source active with encrypted creds; return its source row. */
    private function activeSource(array $creds): array
    {
        $pdo = $this->pdo();
        $key = crypto_key_load($this->keyPath);
        $payload = crypto_encrypt(json_encode($creds, JSON_UNESCAPED_SLASHES), $key);
        $pdo->prepare("UPDATE calendar_sources SET credentials_encrypted = ?, active = 1 WHERE provider = 'caldav'")->execute([$payload]);

        $stmt = $pdo->query(
            "SELECT s.*, g.organizer_timezone
               FROM calendar_sources s
               JOIN global_settings g ON g.tenant_id = s.tenant_id
              WHERE s.provider = 'caldav' LIMIT 1"
        );
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function multistatusXml(string ...$ical): string
    {
        $responses = '';
        foreach ($ical as $i => $cal) {
            $responses .= '<d:response><d:href>/cal/' . $i . '.ics</d:href><d:propstat><d:prop>' .
                '<c:calendar-data>' . $cal . '</c:calendar-data></d:prop></d:propstat></d:response>';
        }
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' . $responses . '</d:multistatus>';
    }

    private function ical(string $uid, string $day, string $start = '10:00', string $end = '11:00'): string
    {
        // iCal times are HHMMSS without colons.
        $start = str_pad(str_replace(':', '', $start), 6, '0', STR_PAD_RIGHT);
        $end = str_pad(str_replace(':', '', $end), 6, '0', STR_PAD_RIGHT);
        return "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//t//EN\n" .
            "BEGIN:VEVENT\nUID:{$uid}\nDTSTART:{$day}T{$start}Z\nDTEND:{$day}T{$end}Z\nSUMMARY:Busy\nEND:VEVENT\n" .
            "END:VCALENDAR\n";
    }

    /** Two UTC days from now (keeps the fixture inside the +90d sync window). */
    private function daysFromNow(int $offset): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$offset} days")->format('Ymd');
    }

    private function countBlockouts(int $sourceId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM calendar_blockouts WHERE calendar_source_id = ?');
        $stmt->execute([$sourceId]);
        return (int) $stmt->fetchColumn();
    }

    // --- Tests --------------------------------------------------------------

    public function testSyncWritesBlockoutsAndMarksOk(): void
    {
        $source = $this->activeSource(['username' => 'stephen@meertec.ltd', 'password' => 'pw']);
        $day = $this->daysFromNow(2);
        $http = fn() => $this->multistatusXml($this->ical('uid-1', $day), $this->ical('uid-2', $day, '14:00', '15:00'));

        $result = calendar_sync_source($this->pdo(), $source, $this->config, $http);

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['blocks']);
        self::assertSame(2, $this->countBlockouts((int) $source['id']));

        $row = $this->pdo()->query("SELECT external_uid, start_utc FROM calendar_blockouts WHERE calendar_source_id = " . (int) $source['id'] . " ORDER BY external_uid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('uid-1', $row['external_uid']);
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} 10:00:00/', $row['start_utc']);

        $src = $this->pdo()->query("SELECT last_sync_status, last_synced_at, last_sync_error FROM calendar_sources WHERE id = " . (int) $source['id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ok', $src['last_sync_status']);
        self::assertNotNull($src['last_synced_at']);
        self::assertNull($src['last_sync_error']);
    }

    public function testSyncIsIdempotent(): void
    {
        $source = $this->activeSource(['username' => 'u', 'password' => 'p']);
        $day = $this->daysFromNow(3);
        $http = fn() => $this->multistatusXml($this->ical('uid-1', $day), $this->ical('uid-2', $day));

        calendar_sync_source($this->pdo(), $source, $this->config, $http);
        calendar_sync_source($this->pdo(), $source, $this->config, $http);

        self::assertSame(2, $this->countBlockouts((int) $source['id']), 're-running a sync must not duplicate rows');
    }

    public function testSyncDeletesEventsNoLongerReturned(): void
    {
        $source = $this->activeSource(['username' => 'u', 'password' => 'p']);
        $day = $this->daysFromNow(4);
        $two = fn() => $this->multistatusXml($this->ical('uid-1', $day), $this->ical('uid-2', $day, '14:00', '15:00'));
        $one = fn() => $this->multistatusXml($this->ical('uid-1', $day));

        calendar_sync_source($this->pdo(), $source, $this->config, $two);
        self::assertSame(2, $this->countBlockouts((int) $source['id']));

        calendar_sync_source($this->pdo(), $source, $this->config, $one);
        self::assertSame(1, $this->countBlockouts((int) $source['id']), 'removed event must stop blocking');

        $stmt = $this->pdo()->prepare('SELECT external_uid FROM calendar_blockouts WHERE calendar_source_id = ?');
        $stmt->execute([$source['id']]);
        self::assertSame('uid-1', $stmt->fetchColumn());
    }

    public function testSyncFailureMarksSourceErrorAndLogsActivity(): void
    {
        $source = $this->activeSource(['username' => 'u', 'password' => 'p']);
        $http = fn() => throw new RuntimeException('401 Unauthorized from Baikal');

        $result = calendar_sync_source($this->pdo(), $source, $this->config, $http);

        self::assertFalse($result['ok']);
        self::assertSame(0, $this->countBlockouts((int) $source['id']));

        $src = $this->pdo()->query("SELECT last_sync_status, last_sync_error FROM calendar_sources WHERE id = " . (int) $source['id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame('error', $src['last_sync_status']);
        self::assertStringContainsString('401', (string) $src['last_sync_error']);

        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE tenant_id = ? AND event_type = 'calendar_sync_failed'");
        $stmt->execute([(int) $source['tenant_id']]);
        self::assertGreaterThan(0, (int) $stmt->fetchColumn());
    }

    public function testSourceStalenessFailsClosed(): void
    {
        self::assertTrue(calendar_source_is_stale(['last_synced_at' => null]), 'never-synced source must be stale');
        self::assertTrue(calendar_source_is_stale(['last_synced_at' => '2020-01-01 00:00:00']), 'old source must be stale');
        self::assertFalse(calendar_source_is_stale(['last_synced_at' => gmdate('Y-m-d H:i:s')]), 'fresh source must not be stale');
    }

    public function testCredentialsEncryptDecryptThroughSource(): void
    {
        $source = $this->activeSource(['username' => 'stephen@meertec.ltd', 'password' => 'hunter2']);
        $creds = calendar_source_creds($source, $this->config);
        self::assertSame('stephen@meertec.ltd', $creds['username']);
        self::assertSame('hunter2', $creds['password']);

        // The stored payload must not contain the plaintext password.
        $raw = $this->pdo()->query("SELECT credentials_encrypted FROM calendar_sources WHERE id = " . (int) $source['id'])->fetchColumn();
        self::assertStringNotContainsString('hunter2', (string) $raw);
    }
}
