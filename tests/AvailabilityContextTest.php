<?php

use PHPUnit\Framework\TestCase;

/**
 * Availability-context conversion tests (Phase 4): the fiddly bit that turns
 * UTC blockout/soft-hold rows into organizer-tz wall-clock blocks per date.
 * No DB required.
 */
final class AvailabilityContextTest extends TestCase
{
    private function tz(): DateTimeZone
    {
        return new DateTimeZone('Europe/London');
    }

    public function testBlockFullyInsideDate(): void
    {
        $rows = [['start_utc' => '2026-08-12 09:00:00', 'end_utc' => '2026-08-12 10:00:00']];
        // 09:00 BST = 10:00 London? No: BST is UTC+1, so 09:00 UTC = 10:00 BST.
        $blocks = availability_ctx_blocks_by_date($rows, $this->tz(), '2026-08-12');
        self::assertSame([['start' => '10:00', 'end' => '11:00']], $blocks);
    }

    public function testBlockSpanningIntoNextOrganizerDay(): void
    {
        // 23:00 UTC on 08-12 = 00:00 BST on 08-13 (BST summer).
        $rows = [['start_utc' => '2026-08-12 23:00:00', 'end_utc' => '2026-08-13 01:00:00']];
        $blocks = availability_ctx_blocks_by_date($rows, $this->tz(), '2026-08-13');
        self::assertSame([['start' => '00:00', 'end' => '02:00']], $blocks);
        // Nothing for the previous day.
        self::assertSame([], availability_ctx_blocks_by_date($rows, $this->tz(), '2026-08-12'));
    }

    public function testBlockSpanningIntoNextOrganizerDayClampsAtMidnight(): void
    {
        // 22:30 UTC 08-12 = 23:30 BST 08-12; ends 00:30 BST 08-13.
        $rows = [['start_utc' => '2026-08-12 22:30:00', 'end_utc' => '2026-08-12 23:30:00']];
        $blocks = availability_ctx_blocks_by_date($rows, $this->tz(), '2026-08-12');
        self::assertSame([['start' => '23:30', 'end' => '23:59']], $blocks);
    }

    public function testWinterTimeConversion(): void
    {
        // Winter (GMT = UTC): 10:00 UTC = 10:00 London.
        $rows = [['start_utc' => '2026-11-02 10:00:00', 'end_utc' => '2026-11-02 11:00:00']];
        $blocks = availability_ctx_blocks_by_date($rows, $this->tz(), '2026-11-02');
        self::assertSame([['start' => '10:00', 'end' => '11:00']], $blocks);
    }

    public function testStalenessFailsClosedWhenActiveSourcesStale(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $pdo->method('prepare')->willReturn($stmt);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([['last_synced_at' => '2020-01-01 00:00:00']]);
        self::assertTrue(availability_ctx_sources_stale($pdo, 1, 24));
    }
}
