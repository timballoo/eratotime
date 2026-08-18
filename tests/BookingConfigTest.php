<?php

use PHPUnit\Framework\TestCase;

/**
 * booking_lib.php config-builder tests (Phase 4 / AJAX type switching): the
 * shared payload builder returns the full widget config for a valid tenant +
 * type, and null when either is unknown or the type is inactive.
 */
final class BookingConfigTest extends TestCase
{
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
        $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $server->exec("USE `{$name}`");
        $has = $server->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $server->quote($name) . " AND table_name = 'tenants'")->fetchColumn();
        if ((int) $has === 0) {
            $server->exec(file_get_contents(__DIR__ . '/../db/eratotime_migration.sql'));
        }
        $this->pdo = $server;
        return $this->pdo;
    }

    private function config(): array
    {
        return ['csrf_secret' => 'test-secret', 'altcha_hmac_key' => ''];
    }

    public function testValidTypeReturnsFullConfig(): void
    {
        $pdo = $this->pdo();
        $mtId = (int) $pdo->query("SELECT id FROM meeting_types WHERE slug = '30-min'")->fetchColumn();
        $pdo->prepare('INSERT INTO meeting_type_questions (meeting_type_id, label, type, required, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$mtId, 'BookingConfigTest question', 'text', 1, 99]);
        try {
            $cfg = booking_config_build($pdo, $this->config(), 'meertec', '30-min', '/');
            self::assertIsArray($cfg);
            self::assertSame('30-min', $cfg['type_slug']);
            self::assertSame('30 Minute Meeting', $cfg['type']['name']);
            self::assertSame(30, $cfg['type']['duration_min']);
            self::assertArrayHasKey('description', $cfg['type']);
            self::assertArrayHasKey('video_link', $cfg['type']);
            self::assertIsArray($cfg['questions']);
            self::assertContains('BookingConfigTest question', array_column($cfg['questions'], 'label'));
            self::assertIsArray($cfg['types']);
            self::assertCount(2, $cfg['types']);
            self::assertSame('60-min', $cfg['types'][1]['slug'] ?? null);
            self::assertNotSame('', $cfg['csrf']);
            self::assertFalse($cfg['altcha_enabled']);
        } finally {
            $pdo->prepare('DELETE FROM meeting_type_questions WHERE meeting_type_id = ? AND label = ?')
                ->execute([$mtId, 'BookingConfigTest question']);
        }
    }

    public function testUnknownTypeReturnsNull(): void
    {
        self::assertNull(booking_config_build($this->pdo(), $this->config(), 'meertec', 'does-not-exist', '/'));
    }

    public function testUnknownTenantReturnsNull(): void
    {
        self::assertNull(booking_config_build($this->pdo(), $this->config(), 'no-such-tenant', '30-min', '/'));
    }

    public function testInactiveTypeReturnsNull(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("UPDATE meeting_types SET active = 0 WHERE slug = '60-min'");
        self::assertNull(booking_config_build($pdo, $this->config(), 'meertec', '60-min', '/'));
        $pdo->exec("UPDATE meeting_types SET active = 1 WHERE slug = '60-min'");
    }
}
