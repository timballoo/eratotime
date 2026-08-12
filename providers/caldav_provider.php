<?php

/**
 * providers/caldav_provider.php — CalDAV + ICS read-only busy-block sync.
 *
 * PRIMARY v1 provider (calendar of record: Baikal under stephen@meertec.ltd).
 * Fetches busy blocks read-only via a CalDAV calendar-query REPORT and
 * normalizes them to UTC blocks keyed by the VEVENT UID. Also provides the
 * simpler ICS feed fallback. Parsing uses sabre/vobject (robust against
 * malformed data); the HTTP transport is raw cURL (overridable for tests).
 *
 * Recurring events are expanded with Sabre\VObject\Recur\EventIterator, which
 * also resolves overrides (RECURRENCE-ID) and EXDATEs. Override components are
 * therefore skipped in the outer loop — the iterator for the master already
 * yields the overridden instances.
 *
 * This provider never writes anything. SSL verification is ON by default
 * (spec 4.1 — the verify=false fallback is a documented last resort only).
 */

if (!function_exists('caldav_build_calendar_query')) {

    define('CALDAV_NAMESPACE_DAV', 'DAV:');
    define('CALDAV_NAMESPACE_CAL', 'urn:ietf:params:xml:ns:caldav');

    /**
     * Build the calendar-query REPORT body for a time window (RFC 4791).
     */
    function caldav_build_calendar_query(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $from = $from->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $to = $to->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        return
            '<?xml version="1.0" encoding="utf-8"?>' . "\n" .
            '<c:calendar-query xmlns:d="' . CALDAV_NAMESPACE_DAV . '" xmlns:c="' . CALDAV_NAMESPACE_CAL . '">' .
            '<d:prop>' .
            '<d:getetag/>' .
            '<c:calendar-data/>' .
            '</d:prop>' .
            '<c:filter>' .
            '<c:comp-filter name="VCALENDAR">' .
            '<c:comp-filter name="VEVENT">' .
            '<c:time-range start="' . $from . '" end="' . $to . '"/>' .
            '</c:comp-filter>' .
            '</c:comp-filter>' .
            '</c:filter>' .
            '</c:calendar-query>';
    }

    /**
     * Default HTTP transport: cURL. Signature is the test-injectable contract:
     * caldav_http(string $method, string $url, string $body, string $user, string $pass): string
     */
    function caldav_http(string $method, string $url, string $body, string $user, string $pass): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $user . ':' . $pass,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml; charset=utf-8',
                'Depth: 1',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('CalDAV HTTP request failed: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("CalDAV {$method} returned HTTP {$code} for {$url}");
        }
        return $resp;
    }

    /**
     * Extract the raw iCalendar payloads from a multistatus REPORT response.
     * Tolerant of prefixed/unprefixed namespaces, entity escaping, and CDATA.
     */
    function caldav_extract_calendar_data(string $xml): array
    {
        $out = [];
        if (preg_match_all('#<(?:[a-zA-Z0-9]+:)?calendar-data[^>]*>(.*?)</(?:[a-zA-Z0-9]+:)?calendar-data>#s', $xml, $m)) {
            foreach ($m[1] as $block) {
                $block = trim($block);
                if ($block === '') {
                    continue;
                }
                if (str_starts_with($block, '<![CDATA[') && str_ends_with($block, ']]>')) {
                    $block = substr($block, 9, -3);
                } else {
                    $block = html_entity_decode($block, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                if (trim($block) !== '') {
                    $out[] = $block;
                }
            }
        }
        return $out;
    }

    /**
     * Busy blocks from one VEVENT (or its recurrence), bounded by the window.
     * Uses Sabre\VObject\Recur\EventIterator, which yields DateTimeImmutable
     * start-times per occurrence (handles RRULE, RDATE, EXDATE and
     * RECURRENCE-ID overrides) and pins floating/all-day times to the
     * reference (organizer) timezone.
     * Returns [['uid'=>string,'start_utc'=>DateTimeImmutable,'end_utc'=>DateTimeImmutable], ...].
     */
    function caldav_vevent_blocks(Sabre\VObject\Component\VCalendar $vcal, Sabre\VObject\Component\VEvent $vevent, string $orgTzName, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $uid = isset($vevent->UID) ? (string) $vevent->UID : 'uid-' . md5((string) $vevent->DTSTART . $vevent->serialize());
        $out = [];
        $iterator = new Sabre\VObject\Recur\EventIterator($vcal, $uid, new DateTimeZone($orgTzName));
        $utc = new DateTimeZone('UTC');
        foreach ($iterator as $unused) {
            $start = $iterator->getDtStart();
            $end = $iterator->getDtEnd();
            if ($end === null) {
                continue; // malformed: no DTEND/DURATION
            }
            if ($end <= $windowStart) {
                continue;
            }
            if ($start > $windowEnd) {
                break;
            }
            $out[] = [
                'uid' => $uid,
                'start_utc' => $start->setTimezone($utc),
                'end_utc' => $end->setTimezone($utc),
            ];
        }
        return $out;
    }

    /**
     * Parse raw iCalendar payloads into normalized busy blocks.
     */
    function caldav_parse_busy_blocks(array $rawCalendars, string $orgTzName, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $out = [];
        foreach ($rawCalendars as $raw) {
            $vcal = Sabre\VObject\Reader::read($raw);
            if (!isset($vcal->VEVENT)) {
                continue;
            }
            foreach ($vcal->VEVENT as $vevent) {
                // Override components are handled by the master's EventIterator.
                if (isset($vevent->{'RECURRENCE-ID'})) {
                    continue;
                }
                if (isset($vevent->STATUS) && strtoupper((string) $vevent->STATUS) === 'CANCELLED') {
                    continue;
                }
                foreach (caldav_vevent_blocks($vcal, $vevent, $orgTzName, $windowStart, $windowEnd) as $b) {
                    $out[] = $b;
                }
            }
        }
        return $out;
    }

    /**
     * CalDAV provider entry point: busy blocks for a window.
     *
     * @param array $source calendar_sources row (needs 'calendar_identifier')
     * @param array $creds  ['username'=>..,'password'=>..] (decrypted by caller)
     * @param callable|null $http fn(method,url,body,user,pass): string
     */
    function caldav_fetch_busy_blocks(array $source, array $creds, DateTimeImmutable $from, DateTimeImmutable $to, string $orgTzName, ?callable $http = null): array
    {
        $http = $http ?: 'caldav_http';
        $url = (string) ($source['calendar_identifier'] ?? '');
        if ($url === '') {
            throw new RuntimeException('CalDAV source has no calendar_identifier (calendar URL)');
        }
        $body = caldav_build_calendar_query($from, $to);
        $xml = $http('REPORT', $url, $body, (string) $creds['username'], (string) $creds['password']);
        $raw = caldav_extract_calendar_data($xml);
        return caldav_parse_busy_blocks($raw, $orgTzName, $from, $to);
    }

    /**
     * ICS feed fallback: fetch a published .ics and parse all VEVENTs in range.
     */
    function ics_fetch_busy_blocks(array $source, DateTimeImmutable $from, DateTimeImmutable $to, string $orgTzName, ?callable $http = null): array
    {
        $http = $http ?: 'caldav_http';
        $url = (string) ($source['ics_url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('ICS source has no ics_url');
        }
        $raw = $http('GET', $url, '', '', '');
        return caldav_parse_busy_blocks([$raw], $orgTzName, $from, $to);
    }
}
