<?php

/**
 * availability_lib.php — layered availability engine (spec 2.2)
 *
 * Pure computation: no I/O, no DB, no external calls. Everything inside this
 * module is timezone-naive wall-clock in the ORGANIZER's timezone:
 *   - dates are 'Y-m-d' strings
 *   - times are 'HH:MM' (or 'HH:MM:SS') strings
 * Real timestamps (DateTimeImmutable) are used only for the min-notice and
 * max-horizon gates, via the caller-supplied 'now' in the organizer's timezone.
 *
 * This keeps slot math DST-proof by construction (spec 2.2 / 4.3): a 9am London
 * start stays 9am London through BST/GMT, and 23/25-hour DST days need no
 * special-casing because no conversion happens inside the grid walk.
 *
 * Layers (spec 2.2):
 *   1. base template week        (working_hours)
 *   2. date-specific overrides   (availability_overrides)
 *   3. synced calendar busy time (calendar_blockouts)
 *   4. pending soft-holds        (request_log)
 *   5. per-type buffers          (meeting_types.buffer_{before,after}_min)
 *
 * The interval math is a sorted-merge subtraction, deliberately NOT the
 * append-while-iterating split loops found in Easy!Appointments (spec 8 /
 * Appendix B): fully-contained busy intervals, back-to-back events and all-day
 * blocks are handled correctly by construction.
 */

if (!function_exists('availability_parse_time')) {

    /**
     * 'HH:MM' / 'HH:MM:SS' -> minutes since midnight. Invalid input -> null.
     */
    function availability_parse_time(string $t): ?int
    {
        $parts = array_map('intval', explode(':', trim($t)));
        if (count($parts) < 2 || count($parts) > 3) {
            return null;
        }
        [$h, $m] = $parts;
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }
        return $h * 60 + $m;
    }

    /**
     * minutes since midnight -> 'HH:MM'.
     */
    function availability_format_time(int $mins): string
    {
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * Normalise an interval entry ['start'=>..,'end'=>..] to [startMin,endMin].
     * Values may be 'HH:MM'/'HH:MM:SS' strings OR integers (minutes since
     * midnight) so numeric [startMin,endMin] pairs work as well as assoc
     * string pairs. Returns null if either side is invalid or end <= start.
     */
    function availability_interval_minutes(array $interval): ?array
    {
        $s = $interval['start'];
        $e = $interval['end'];
        $sM = is_int($s) ? $s : availability_parse_time((string) $s);
        $eM = is_int($e) ? $e : availability_parse_time((string) $e);
        if ($sM === null || $eM === null || $eM <= $sM) {
            return null;
        }
        return [$sM, $eM];
    }

    /**
     * Merge overlapping/adjacent intervals into a sorted, non-overlapping list.
     * Input/output: list of [startMin, endMin]. Input need not be sorted.
     */
    function availability_merge_intervals(array $intervals): array
    {
        $clean = [];
        foreach ($intervals as $iv) {
            if (is_array($iv) && isset($iv['start'], $iv['end'])) {
                $m = availability_interval_minutes($iv);
            } elseif (is_array($iv) && array_key_exists(0, $iv) && array_key_exists(1, $iv)) {
                $m = availability_interval_minutes(['start' => $iv[0], 'end' => $iv[1]]);
            } else {
                $m = null;
            }
            if ($m !== null) {
                $clean[] = $m;
            }
        }
        if ($clean === []) {
            return [];
        }
        usort($clean, fn($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($clean as $iv) {
            $n = count($out);
            if ($n > 0 && $iv[0] <= $out[$n - 1][1]) { // overlap OR adjacency (touch)
                $out[$n - 1][1] = max($out[$n - 1][1], $iv[1]);
            } else {
                $out[] = $iv;
            }
        }
        return $out;
    }

    /**
     * Subtract $busy intervals from $free intervals (sorted-merge).
     * Both lists are [startMin,endMin]. Returns the remaining free intervals.
     */
    function availability_subtract(array $free, array $busy): array
    {
        $free = availability_merge_intervals($free);
        $busy = availability_merge_intervals($busy);
        if ($busy === []) {
            return $free;
        }
        $out = [];
        $j = 0;
        $n = count($busy);
        foreach ($free as [$fs, $fe]) {
            while ($j < $n && $busy[$j][1] <= $fs) {
                $j++; // busy wholly before this free interval
            }
            $cursor = $fs;
            $k = $j;
            while ($k < $n && $busy[$k][0] < $fe) {
                [$bs, $be] = $busy[$k];
                if ($be <= $cursor) {
                    $k++;
                    continue;
                }
                if ($bs > $cursor) {
                    $out[] = [$cursor, min($bs, $fe)];
                }
                $cursor = max($cursor, $be);
                $k++;
            }
            if ($cursor < $fe) {
                $out[] = [$cursor, $fe];
            }
        }
        return $out;
    }

    /**
     * Resolve layer 1 (template) then layer 2 (overrides) into the day's open
     * ranges. Supports MULTIPLE ranges per day (several working_hours rows for
     * one day_of_week, several is_blocked=0 override rows for one date), so the
     * admin grid can represent blocked internal cells. Ranges are merged.
     *
     * @param int $dayOfWeek 0=Sunday .. 6=Saturday (matches working_hours column)
     * @param array $workingHours rows: ['day_of_week'=>int,'start_time'=>str,'end_time'=>str]
     * @param string $date 'Y-m-d'
     * @param array $overrides rows: ['date'=>'Y-m-d','is_blocked'=>int,'start_time'=>?str,'end_time'=>?str]
     *
     * @return array|null list of ['start'=>'HH:MM','end'=>'HH:MM'] or null if the day is closed.
     */
    function availability_day_open_ranges(int $dayOfWeek, array $workingHours, string $date, array $overrides): ?array
    {
        $ovRows = array_values(array_filter($overrides, fn($o) => (string) ($o['date'] ?? '') === $date));
        if ($ovRows !== []) {
            // Any full-block override row closes the whole day.
            foreach ($ovRows as $o) {
                if (!empty($o['is_blocked'])) {
                    return null;
                }
            }
            $ranges = [];
            foreach ($ovRows as $o) {
                $s = $o['start_time'] ?? null;
                $e = $o['end_time'] ?? null;
                if ($s !== null && $e !== null && $s !== '' && $e !== '') {
                    $sM = availability_parse_time((string) $s);
                    $eM = availability_parse_time((string) $e);
                    if ($sM !== null && $eM !== null && $eM > $sM) {
                        $ranges[] = [$sM, $eM];
                    }
                }
            }
            if ($ranges === []) {
                return null; // override present but no valid open window -> closed
            }
            $merged = availability_merge_intervals($ranges);
            return array_map(
                fn($iv) => ['start' => availability_format_time($iv[0]), 'end' => availability_format_time($iv[1])],
                $merged
            );
        }
        $ranges = [];
        foreach ($workingHours as $wh) {
            if ((int) ($wh['day_of_week'] ?? -1) === $dayOfWeek) {
                $sM = availability_parse_time((string) $wh['start_time']);
                $eM = availability_parse_time((string) $wh['end_time']);
                if ($sM !== null && $eM !== null && $eM > $sM) {
                    $ranges[] = [$sM, $eM];
                }
            }
        }
        if ($ranges === []) {
            return null;
        }
        $merged = availability_merge_intervals($ranges);
        return array_map(
            fn($iv) => ['start' => availability_format_time($iv[0]), 'end' => availability_format_time($iv[1])],
            $merged
        );
    }

    /**
     * Legacy single-window view of a day's schedule (first open .. last open).
     * Kept for display purposes; the engine uses availability_day_open_ranges().
     *
     * @return array|null ['start'=>'HH:MM','end'=>'HH:MM'] or null if closed.
     */
    function availability_day_schedule(int $dayOfWeek, array $workingHours, string $date, array $overrides): ?array
    {
        $ranges = availability_day_open_ranges($dayOfWeek, $workingHours, $date, $overrides);
        if ($ranges === null || $ranges === []) {
            return null;
        }
        return ['start' => $ranges[0]['start'], 'end' => $ranges[count($ranges) - 1]['end']];
    }

    /**
     * Walk free intervals on a fixed grid; a start time is bookable iff its full
     * footprint (start - buffer_before .. end + buffer_after) fits inside a free
     * interval (spec 2.2, layer 5).
     *
     * @param array $free list of [startMin,endMin] (already subtracted)
     * @param int $durationMin meeting duration
     * @param int $bufBefore buffer before, minutes
     * @param int $bufAfter  buffer after, minutes
     * @param int $granularity grid step, minutes
     *
     * @return int[] bookable start times in minutes since midnight
     */
    function availability_fit_slots(array $free, int $durationMin, int $bufBefore, int $bufAfter, int $granularity = 30): array
    {
        $out = [];
        foreach ($free as [$fs, $fe]) {
            // First grid-aligned start point that satisfies the start-side buffer.
            $t = $fs + $bufBefore;
            if ($granularity > 1) {
                $t = (int) ceil($t / $granularity) * $granularity;
            }
            while ($t + $durationMin + $bufAfter <= $fe) {
                $out[] = $t;
                $t += $granularity;
            }
        }
        return $out;
    }

    /**
     * Cap checks (spec 2.1): per-type daily cap and global daily/weekly caps.
     * The caller supplies the request counts for the relevant periods; this stays
     * pure so it can be unit-tested without a DB.
     *
     * @param array $counts ['type_day'=>int,'global_day'=>int,'global_week'=>int]
     * @param array $caps   ['type_daily_cap'=>?int,'global_daily_cap'=>?int,'global_weekly_cap'=>?int]
     */
    function availability_caps_hit(array $counts, array $caps): bool
    {
        $rules = [
            'type_daily_cap'    => $counts['type_day'] ?? 0,
            'global_daily_cap'  => $counts['global_day'] ?? 0,
            'global_weekly_cap' => $counts['global_week'] ?? 0,
        ];
        foreach ($rules as $capKey => $count) {
            $cap = $caps[$capKey] ?? null;
            if ($cap !== null && $count >= (int) $cap) {
                return true;
            }
        }
        return false;
    }

    /**
     * Top-level day computation (spec 2.2 / section 8 test matrix).
     *
     * @param array $ctx [
     *   'date'           => 'Y-m-d' (organizer tz)
     *   'working_hours'  => array of working_hours rows
     *   'overrides'      => array of availability_overrides rows
     *   'blockouts'      => [['start'=>'HH:MM','end'=>'HH:MM'], ...] organizer-tz wall clock
     *   'soft_holds'     => same shape as blockouts
     *   'meeting_type'   => ['duration_min'=>int,'buffer_before_min'=>int,'buffer_after_min'=>int,
     *                        'min_notice_hours'=>int,'max_horizon_days'=>int]
     *   'now'            => DateTimeImmutable in organizer tz (required for notice/horizon gates)
     *   'counts'         => availability_caps_hit() counts
     *   'caps'           => availability_caps_hit() caps
     *   'granularity_min'=> int, default 30
     * ]
     *
     * @return array ['slots'=>['HH:MM',...], 'schedule'=>?array, 'intervals'=>[['start','end'],...]]
     */
    function availability_day(array $ctx): array
    {
        $date = (string) ($ctx['date'] ?? '');
        $now = $ctx['now'] ?? null;

        $granularity = max(1, (int) ($ctx['granularity_min'] ?? 30));
        $mt = $ctx['meeting_type'] ?? [];
        $duration = max(1, (int) ($mt['duration_min'] ?? 30));
        $bufBefore = max(0, (int) ($mt['buffer_before_min'] ?? 0));
        $bufAfter = max(0, (int) ($mt['buffer_after_min'] ?? 0));
        $noticeHours = max(0, (int) ($mt['min_notice_hours'] ?? 0));
        $horizonDays = (int) ($mt['max_horizon_days'] ?? 0);

        $empty = ['slots' => [], 'schedule' => null, 'intervals' => []];

        if ($date === '') {
            return $empty;
        }

        $dayOfWeek = (int) date('w', strtotime($date)); // 0=Sunday..6=Saturday
        $ranges = availability_day_open_ranges(
            $dayOfWeek,
            $ctx['working_hours'] ?? [],
            $date,
            $ctx['overrides'] ?? []
        );
        if ($ranges === null || $ranges === []) {
            return $empty;
        }
        $schedule = ['start' => $ranges[0]['start'], 'end' => $ranges[count($ranges) - 1]['end']];

        // max-horizon + past check, day-granular in the organizer's timezone:
        // dates up to and including now+horizonDays are bookable (equality ok).
        if ($now instanceof DateTimeImmutable) {
            $today = $now->format('Y-m-d');
            if ($date < $today) {
                return $empty;
            }
            if ($horizonDays >= 0) {
                $horizonDate = $now->modify("+{$horizonDays} days")->format('Y-m-d');
                if ($date > $horizonDate) {
                    return $empty;
                }
            }
        }

        $busy = array_merge(
            $ctx['blockouts'] ?? [],
            $ctx['soft_holds'] ?? []
        );
        $free = availability_subtract($ranges, $busy);

        $slots = [];
        foreach (availability_fit_slots($free, $duration, $bufBefore, $bufAfter, $granularity) as $startMin) {
            $slots[] = availability_format_time($startMin);
        }

        // min-notice: slot must start strictly after now + notice.
        if ($now instanceof DateTimeImmutable && $noticeHours > 0) {
            $threshold = $now->modify("+{$noticeHours} hours");
            $tz = $now->getTimezone();
            $slots = array_values(array_filter($slots, function ($slot) use ($date, $threshold, $tz) {
                $start = new DateTimeImmutable($date . ' ' . $slot . ':00', $tz);
                return $start > $threshold;
            }));
        }

        // caps: a cap reached anywhere means no more slots this day for this type.
        if (availability_caps_hit($ctx['counts'] ?? [], $ctx['caps'] ?? [])) {
            $slots = [];
        }

        return [
            'slots' => $slots,
            'schedule' => $schedule,
            'intervals' => array_map(
                fn($iv) => ['start' => availability_format_time($iv[0]), 'end' => availability_format_time($iv[1])],
                $free
            ),
        ];
    }
}
