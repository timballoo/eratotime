<?php

use PHPUnit\Framework\TestCase;

/**
 * Availability engine tests (spec 2.2 / section 8).
 *
 * The engine is pure: no DB, no live calendar. All fixtures are synthetic.
 * Includes the interval-subtraction regression cases that Easy!Appointments
 * gets wrong (Appendix B): fully-contained events, back-to-back events,
 * all-day blocks, and workday-boundary spans.
 */
final class AvailabilityEngineTest extends TestCase
{
    private const TZ = 'Europe/London';

    private function workdayFixture(): array
    {
        return [
            ['day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '17:30:00'],
            ['day_of_week' => 2, 'start_time' => '09:00:00', 'end_time' => '17:30:00'],
            ['day_of_week' => 3, 'start_time' => '09:00:00', 'end_time' => '17:30:00'],
            ['day_of_week' => 4, 'start_time' => '09:00:00', 'end_time' => '17:30:00'],
            ['day_of_week' => 5, 'start_time' => '09:00:00', 'end_time' => '17:30:00'],
        ];
    }

    private function mt(array $overrides = []): array
    {
        return array_merge([
            'duration_min' => 30,
            'buffer_before_min' => 0,
            'buffer_after_min' => 0,
            'min_notice_hours' => 0,
            'max_horizon_days' => 14,
        ], $overrides);
    }

    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-08-12', // a Wednesday
            'working_hours' => $this->workdayFixture(),
            'overrides' => [],
            'blockouts' => [],
            'soft_holds' => [],
            'meeting_type' => $this->mt(),
            'now' => new DateTimeImmutable('2026-08-10 09:00:00', new DateTimeZone(self::TZ)),
            'counts' => [],
            'caps' => [],
            'granularity_min' => 30,
        ], $overrides);
    }

    // --- Layer 1: template week -------------------------------------------

    public function testPlainWeekThirtyMinuteSlots(): void
    {
        $r = availability_day($this->ctx());
        // 09:00..17:00 in 30-min steps = 17 slots; last slot 17:00 (ends 17:30).
        self::assertCount(17, $r['slots']);
        self::assertSame('09:00', $r['slots'][0]);
        self::assertSame('17:00', $r['slots'][16]);
        self::assertSame(['start' => '09:00', 'end' => '17:30'], $r['schedule']);
    }

    public function testPlainWeekSixtyMinuteSlots(): void
    {
        $r = availability_day($this->ctx([
            'meeting_type' => $this->mt(['duration_min' => 60]),
        ]));
        self::assertCount(16, $r['slots']); // 09:00..16:30
        self::assertSame('09:00', $r['slots'][0]);
        self::assertSame('16:30', $r['slots'][15]);
    }

    public function testClosedDayWithNoWorkingHours(): void
    {
        $r = availability_day($this->ctx([
            'date' => '2026-08-15', // weekend, not in the Mon-Fri fixture
        ]));
        self::assertSame([], $r['slots']);
        self::assertNull($r['schedule']);
    }

    // --- Layer 2: overrides -------------------------------------------------

    public function testOverrideBlocksTheWholeDay(): void
    {
        $r = availability_day($this->ctx([
            'overrides' => [['date' => '2026-08-12', 'is_blocked' => 1, 'start_time' => null, 'end_time' => null]],
        ]));
        self::assertSame([], $r['slots']);
        self::assertNull($r['schedule']);
    }

    public function testOverrideOpensCustomHours(): void
    {
        $r = availability_day($this->ctx([
            'overrides' => [['date' => '2026-08-12', 'is_blocked' => 0, 'start_time' => '10:00:00', 'end_time' => '12:00:00']],
        ]));
        self::assertSame(['10:00', '10:30', '11:00', '11:30'], $r['slots']);
        self::assertSame(['start' => '10:00', 'end' => '12:00'], $r['schedule']);
    }

    public function testOverrideOpensAvailabilityOnNormallyClosedDay(): void
    {
        $r = availability_day($this->ctx([
            'date' => '2026-08-15', // weekend
            'overrides' => [['date' => '2026-08-15', 'is_blocked' => 0, 'start_time' => '09:00:00', 'end_time' => '11:00:00']],
        ]));
        self::assertSame(['09:00', '09:30', '10:00', '10:30'], $r['slots']);
    }

    public function testOverrideOnlyAffectsItsOwnDate(): void
    {
        $r = availability_day($this->ctx([
            'date' => '2026-08-13', // next day — must still follow the template
            'overrides' => [['date' => '2026-08-12', 'is_blocked' => 1, 'start_time' => null, 'end_time' => null]],
        ]));
        self::assertCount(17, $r['slots']);
    }

    // --- Layer 3: blockouts (interval subtraction) --------------------------

    public function testFullyContainedBlockoutSplitsTheInterval(): void
    {
        // EA regression: an event strictly inside a candidate slot must block it.
        $r = availability_day($this->ctx([
            'blockouts' => [['start' => '10:00', 'end' => '11:00']],
        ]));
        self::assertNotContains('10:00', $r['slots']);
        self::assertNotContains('10:30', $r['slots']);
        self::assertContains('09:30', $r['slots']);
        self::assertContains('11:00', $r['slots']);
        self::assertSame(['09:00', '09:30'], array_slice($r['slots'], 0, 2));
    }

    public function testBackToBackBlockoutsMerge(): void
    {
        $r = availability_day($this->ctx([
            'blockouts' => [
                ['start' => '10:00', 'end' => '10:30'],
                ['start' => '10:30', 'end' => '11:00'],
            ],
        ]));
        self::assertNotContains('10:00', $r['slots']);
        self::assertNotContains('10:30', $r['slots']);
        self::assertContains('11:00', $r['slots']);
    }

    public function testAllDayBlockRemovesEverything(): void
    {
        $r = availability_day($this->ctx([
            'blockouts' => [['start' => '00:00', 'end' => '23:59']],
        ]));
        self::assertSame([], $r['slots']);
    }

    public function testBlockoutSpanningWorkdayBoundariesClamps(): void
    {
        // Starts before the workday -> effectively 09:00-09:30 busy.
        $r = availability_day($this->ctx([
            'blockouts' => [['start' => '08:00', 'end' => '09:30']],
        ]));
        self::assertSame('09:30', $r['slots'][0]);

        // Ends after the workday -> effectively 17:00-17:30 busy.
        $r2 = availability_day($this->ctx([
            'blockouts' => [['start' => '17:00', 'end' => '19:00']],
        ]));
        self::assertSame('16:30', end($r2['slots']));
        self::assertNotContains('17:00', $r2['slots']);
    }

    public function testBlockoutOutsideWorkdayHasNoEffect(): void
    {
        $r = availability_day($this->ctx([
            'blockouts' => [['start' => '06:00', 'end' => '07:00']],
        ]));
        self::assertCount(17, $r['slots']);
    }

    public function testSubtractHandlesOverlappingAndContainedBusy(): void
    {
        // Unit-level check of the sorted-merge subtraction.
        $free = availability_subtract(
            [[540, 1050]],                                             // 09:00-17:30
            [[560, 600], [590, 610], [700, 720]]                       // overlapping busy
        );
        self::assertSame([[540, 560], [610, 700], [720, 1050]], $free);
    }

    // --- Layer 4: soft-holds -------------------------------------------------

    public function testPendingSoftHoldBlocksItsInterval(): void
    {
        $r = availability_day($this->ctx([
            'soft_holds' => [['start' => '10:00', 'end' => '10:30']],
        ]));
        self::assertNotContains('10:00', $r['slots']);
        self::assertContains('09:30', $r['slots']);
        self::assertContains('10:30', $r['slots']);
    }

    public function testNoSoftHoldsMeansFullGrid(): void
    {
        // Expired soft-holds are the caller's job to filter out of the list;
        // an empty list yields the full grid.
        $r = availability_day($this->ctx(['soft_holds' => []]));
        self::assertCount(17, $r['slots']);
    }

    public function testSoftHoldOutsideWorkdayHasNoEffect(): void
    {
        $r = availability_day($this->ctx([
            'soft_holds' => [['start' => '18:00', 'end' => '19:00']],
        ]));
        self::assertCount(17, $r['slots']);
    }

    // --- Layer 5: buffers ----------------------------------------------------

    public function testBuffersShrinkTheBookableFootprint(): void
    {
        // 30-min meeting, 10 min before + 10 min after, free 09:00-10:30.
        // Footprint at 09:00 is 08:50-09:40 (overhangs start); at 09:30 it is
        // 09:20-10:10 (fits); at 10:00 it is 09:50-10:40 (overhangs end).
        $r = availability_day($this->ctx([
            'date' => '2026-08-12',
            'overrides' => [['date' => '2026-08-12', 'is_blocked' => 0, 'start_time' => '09:00:00', 'end_time' => '10:30:00']],
            'meeting_type' => $this->mt(['buffer_before_min' => 10, 'buffer_after_min' => 10]),
        ]));
        self::assertSame(['09:30'], $r['slots']);
    }

    public function testZeroLengthBusyIntervalIsIgnored(): void
    {
        $r = availability_day($this->ctx([
            'blockouts' => [['start' => '10:00', 'end' => '10:00']],
        ]));
        self::assertCount(17, $r['slots']);
    }

    // --- Min-notice / max-horizon --------------------------------------------

    public function testMinNoticeExcludesSlotsAtOrBeforeThreshold(): void
    {
        // now 09:00, notice 1h -> slots must start strictly after 10:00.
        $r = availability_day($this->ctx([
            'now' => new DateTimeImmutable('2026-08-12 09:00:00', new DateTimeZone(self::TZ)),
            'meeting_type' => $this->mt(['min_notice_hours' => 1]),
        ]));
        self::assertNotContains('09:00', $r['slots']);
        self::assertNotContains('10:00', $r['slots']);
        self::assertContains('10:30', $r['slots']);
        self::assertSame('10:30', $r['slots'][0]);
    }

    public function testMaxHorizonExcludesDatesBeyondHorizon(): void
    {
        // now 2026-08-10 09:00, horizon 14 days -> 2026-08-24 bookable, 08-25 not.
        $ok = availability_day($this->ctx(['date' => '2026-08-24']));
        self::assertCount(17, $ok['slots']);

        $beyond = availability_day($this->ctx(['date' => '2026-08-25']));
        self::assertSame([], $beyond['slots']);
    }

    public function testPastDatesYieldNoSlots(): void
    {
        $r = availability_day($this->ctx(['date' => '2026-08-09'])); // Sunday before now
        self::assertSame([], $r['slots']);
    }

    // --- DST ----------------------------------------------------------------

    public function testWallClockSlotsIdenticalAcrossDSTPeriods(): void
    {
        // The same working hours must yield identical wall-clock slots whether
        // the date is in GMT or BST — no offset drift (spec 4.3).
        $base = [
            'now' => new DateTimeImmutable('2026-01-05 09:00:00', new DateTimeZone(self::TZ)),
            'meeting_type' => $this->mt(['max_horizon_days' => 400]),
        ];
        $dates = ['2026-03-30', '2026-08-12', '2026-11-02']; // BST, BST, GMT (all Mondays/Wednesday)
        $expected = null;
        foreach ($dates as $date) {
            $r = availability_day($this->ctx(array_merge($base, ['date' => $date])));
            if ($expected === null) {
                $expected = $r['slots'];
            } else {
                self::assertSame($expected, $r['slots']);
            }
            self::assertCount(17, $r['slots']);
        }
    }

    public function testMinNoticeGateIsDstAware(): void
    {
        // now is Fri 2026-03-27 17:00 GMT (before spring-forward on 03-29).
        // +24h lands at 2026-03-28 17:00 GMT; a Monday 03-30 slot is included.
        $r = availability_day($this->ctx([
            'date' => '2026-03-30',
            'now' => new DateTimeImmutable('2026-03-27 17:00:00', new DateTimeZone(self::TZ)),
            'meeting_type' => $this->mt(['min_notice_hours' => 24]),
        ]));
        self::assertCount(17, $r['slots']);

        // Same gate, but now exactly 24h before the first slot: it must be excluded.
        $r2 = availability_day($this->ctx([
            'date' => '2026-08-12',
            'now' => new DateTimeImmutable('2026-08-11 09:00:00', new DateTimeZone(self::TZ)),
            'meeting_type' => $this->mt(['min_notice_hours' => 24]),
        ]));
        self::assertNotContains('09:00', $r2['slots']);
        self::assertContains('09:30', $r2['slots']);
    }

    // --- Caps ---------------------------------------------------------------

    public function testPerTypeDailyCapHitYieldsNoSlots(): void
    {
        $r = availability_day($this->ctx([
            'counts' => ['type_day' => 3, 'global_day' => 0, 'global_week' => 0],
            'caps' => ['type_daily_cap' => 3, 'global_daily_cap' => null, 'global_weekly_cap' => null],
        ]));
        self::assertSame([], $r['slots']);
    }

    public function testPerTypeDailyCapNotHitYieldsSlots(): void
    {
        $r = availability_day($this->ctx([
            'counts' => ['type_day' => 2, 'global_day' => 0, 'global_week' => 0],
            'caps' => ['type_daily_cap' => 3, 'global_daily_cap' => null, 'global_weekly_cap' => null],
        ]));
        self::assertCount(17, $r['slots']);
    }

    public function testGlobalDailyCapHitYieldsNoSlots(): void
    {
        $r = availability_day($this->ctx([
            'counts' => ['type_day' => 0, 'global_day' => 4, 'global_week' => 0],
            'caps' => ['type_daily_cap' => null, 'global_daily_cap' => 4, 'global_weekly_cap' => null],
        ]));
        self::assertSame([], $r['slots']);
    }

    public function testGlobalWeeklyCapHitYieldsNoSlots(): void
    {
        $r = availability_day($this->ctx([
            'counts' => ['type_day' => 0, 'global_day' => 0, 'global_week' => 8],
            'caps' => ['type_daily_cap' => null, 'global_daily_cap' => null, 'global_weekly_cap' => 8],
        ]));
        self::assertSame([], $r['slots']);
    }

    // --- Day-schedule unit checks -------------------------------------------

    public function testDayScheduleFallsBackToTemplate(): void
    {
        $s = availability_day_schedule(3, $this->workdayFixture(), '2026-08-12', []);
        self::assertSame(['start' => '09:00', 'end' => '17:30'], $s);
    }

    public function testDayScheduleNoWorkingHoursIsNull(): void
    {
        self::assertNull(availability_day_schedule(6, $this->workdayFixture(), '2026-08-15', []));
    }
}
