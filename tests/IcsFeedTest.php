<?php

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * Availability-feed tests (Phase 6): grid-slot merging into contiguous windows
 * and the subscribable .ics feed builder. Pure functions — no DB required.
 * Spec 4.7: publication of "available" time, TRANSP:TRANSPARENT, and fail-closed
 * (empty feed) when a calendar sync source is stale.
 */
final class IcsFeedTest extends TestCase
{
    private function makeCtx(bool $stale = false, int $horizon = 30): array
    {
        $workingHours = [];
        for ($d = 0; $d <= 6; $d++) {
            $workingHours[] = ['day_of_week' => $d, 'start_time' => '09:00', 'end_time' => '11:00'];
        }
        return [
            'meeting_type' => [
                'name' => 'Test Call',
                'slug' => 'test-call',
                'duration_min' => 60,
                'buffer_before_min' => 0,
                'buffer_after_min' => 0,
                'min_notice_hours' => 0,
                'max_horizon_days' => $horizon,
            ],
            'org_tz_name' => 'Europe/London',
            'org_tz' => new DateTimeZone('Europe/London'),
            'stale' => $stale,
            'working_hours' => $workingHours,
            'overrides' => [],
            'blockout_rows' => [],
            'soft_hold_rows' => [],
            'counts' => [],
            'caps' => [],
        ];
    }

    public function testMergeWindowsJoinsConsecutiveStarts(): void
    {
        // 09:00 + 09:30 starts for a 60-min call form one 09:00–11:00 window.
        self::assertSame(
            [['start' => '09:00', 'end' => '11:00']],
            ics_merge_windows(['10:00', '09:00', '09:30'], 60)
        );

        // A gap wider than the step splits the run.
        self::assertSame(
            [
                ['start' => '09:00', 'end' => '10:00'],
                ['start' => '10:30', 'end' => '11:00'],
            ],
            ics_merge_windows(['09:00', '09:30', '10:30'], 30, 30)
        );

        self::assertSame([], ics_merge_windows([], 30));
        // Invalid/blank entries are ignored, the valid one still yields a window.
        self::assertSame(
            [['start' => '09:00', 'end' => '09:30']],
            ics_merge_windows(['09:00', 'garbage'], 30)
        );
    }

    public function testBuildAvailabilityEmitsOneTransparentEventPerWindow(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/London'));
        $result = ics_build_availability($this->makeCtx(), $now);

        self::assertFalse($result['stale']);
        // Open every day 09:00–11:00, 60-min calls -> one window per day,
        // horizon+1 days from today.
        self::assertSame(31, $result['count']);

        $cal = Reader::read($result['ical']);
        $events = $cal->select('VEVENT');
        self::assertCount(31, $events);
        foreach ($events as $ev) {
            self::assertSame('Available — Test Call', (string) $ev->SUMMARY);
            self::assertSame('TRANSPARENT', (string) $ev->TRANSP);
            self::assertStringContainsString('T', (string) $ev->DTSTART);
            $dur = (new DateTime((string) $ev->DTEND))->getTimestamp() - (new DateTime((string) $ev->DTSTART))->getTimestamp();
            // One contiguous 09:00–11:00 window per day.
            self::assertSame(7200, $dur);
        }

        self::assertStringContainsString('X-WR-CALNAME:Eratotime — Test Call availability', $result['ical']);
        self::assertStringContainsString('X-PUBLISHED-TTL:PT30M', $result['ical']);
        self::assertStringContainsString('REFRESH-INTERVAL:PT30M', $result['ical']);
    }

    public function testBuildAvailabilityFailsClosedWhenStale(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/London'));
        $result = ics_build_availability($this->makeCtx(stale: true), $now);

        self::assertTrue($result['stale']);
        self::assertSame(0, $result['count']);

        $cal = Reader::read($result['ical']);
        self::assertCount(0, $cal->select('VEVENT'));
        // Still a valid calendar so subscribers don't hard-fail.
        self::assertStringContainsString('BEGIN:VCALENDAR', $result['ical']);
        self::assertStringContainsString('X-WR-CALNAME', $result['ical']);
    }
}