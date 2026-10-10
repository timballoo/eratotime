<?php

/**
 * ics_lib.php — iCalendar (.ics) generation for publishing availability.
 *
 * Two uses:
 *   - ics_merge_windows(): collapse the engine's grid-aligned open slots into
 *     contiguous "available" windows (one per run), so a published feed reads
 *     as blocks rather than one event per 30 minutes.
 *   - ics_build_availability(): serialize a meeting type's open windows over
 *     its booking horizon as a subscribable calendar (Outlook/Google/Apple
 *     "Internet Calendar"). This is a read-only feed of when the organizer is
 *     bookable — it is NOT busy-free/busy and does not feed Outlook's
 *     Scheduling Assistant (which only reads Exchange/M365 free-busy).
 *
 * Pure w.r.t. the DB: callers pass an already-loaded availability context
 * (availability_ctx_load) exactly like api/slots.php. Emits an empty calendar
 * when the context is stale (fail closed, spec 3.5).
 */

if (!function_exists('ics_merge_windows')) {

    /**
     * Merge consecutive grid-aligned slot starts into contiguous windows.
     *
     * @param string[] $slots       'HH:MM' start times (any order, dupes ok)
     * @param int      $durationMin meeting length: a window ends at last start + duration
     * @param int      $stepMin     grid step; starts closer than this are joined
     *
     * @return array<int,array{start:string,end:string}> ['HH:MM','HH:MM']
     */
    function ics_merge_windows(array $slots, int $durationMin, int $stepMin = 30): array
    {
        $mins = [];
        foreach ($slots as $s) {
            $s = (string) $s;
            if (preg_match('/^(\d{2}):(\d{2})$/', $s, $m)) {
                $mins[(int) $m[1] * 60 + (int) $m[2]] = true;
            }
        }
        $mins = array_keys($mins);
        sort($mins);

        $fmt = static fn(int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);

        $windows = [];
        $start = null;
        $last = null;
        foreach ($mins as $m) {
            if ($start === null) {
                $start = $last = $m;
                continue;
            }
            if ($m - $last <= $stepMin) {
                $last = $m;
                continue;
            }
            $windows[] = ['start' => $fmt($start), 'end' => $fmt($last + $durationMin)];
            $start = $last = $m;
        }
        if ($start !== null) {
            $windows[] = ['start' => $fmt($start), 'end' => $fmt($last + $durationMin)];
        }
        return $windows;
    }

    /**
     * Build the subscribable availability calendar for one meeting type.
     *
     * @param array             $ctx loaded availability context (availability_ctx_load)
     * @param DateTimeImmutable $now organizer-tz "now" (also used for the DTSTAMP)
     *
     * @return array{ical:string,stale:bool,count:int}
     */
    function ics_build_availability(array $ctx, DateTimeImmutable $now): array
    {
        $type = $ctx['meeting_type'];
        $typeName = (string) ($type['name'] ?? 'Meeting');
        $slug = (string) ($type['slug'] ?? 'meeting');
        $duration = max(1, (int) ($type['duration_min'] ?? 30));
        $horizon = (int) ($type['max_horizon_days'] ?? 14);
        if ($horizon < 0) {
            $horizon = 0;
        }
        $horizon = min($horizon, 90); // hard cap: keep the feed small

        $cal = new Sabre\VObject\Component\VCalendar();
        $cal->add('X-WR-CALNAME', 'Eratotime — ' . $typeName . ' availability');
        $cal->add('X-WR-TIMEZONE', (string) ($ctx['org_tz_name'] ?? 'Europe/London'));
        $cal->add('X-PUBLISHED-TTL', 'PT30M');
        $cal->add('REFRESH-INTERVAL', 'PT30M');

        if (!empty($ctx['stale'])) {
            return ['ical' => $cal->serialize(), 'stale' => true, 'count' => 0];
        }

        $stamp = $now->setTimezone(new DateTimeZone('UTC'));
        $count = 0;
        for ($i = 0; $i <= $horizon; $i++) {
            $date = $now->modify('+' . $i . ' days')->format('Y-m-d');
            $day = availability_day(availability_ctx_for_date($ctx, $date));
            foreach (ics_merge_windows($day['slots'], $duration) as $w) {
                $startUtc = (new DateTimeImmutable($date . ' ' . $w['start'] . ':00', $ctx['org_tz']))->setTimezone(new DateTimeZone('UTC'));
                $endUtc = (new DateTimeImmutable($date . ' ' . $w['end'] . ':00', $ctx['org_tz']))->setTimezone(new DateTimeZone('UTC'));
                $cal->add('VEVENT', [
                    'UID' => 'eratotime-avail-' . $date . '-' . str_replace(':', '', $w['start']) . '-' . $slug . '@eratotime',
                    'DTSTAMP' => $stamp,
                    'DTSTART' => $startUtc,
                    'DTEND' => $endUtc,
                    'SUMMARY' => 'Available — ' . $typeName,
                    'TRANSP' => 'TRANSPARENT',
                    'STATUS' => 'CONFIRMED',
                ]);
                $count++;
            }
        }

        return ['ical' => $cal->serialize(), 'stale' => false, 'count' => $count];
    }
}
