<?php

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;

/**
 * CalDAV provider tests (Phase 3): REPORT XML building, multistatus
 * extraction, sabre/vobject parsing (timed / all-day / cancelled / floating /
 * recurring), and the full fetch pipeline against a mock HTTP transport.
 * No live network and no credentials required.
 */
final class CalDavProviderTest extends TestCase
{
    private const ORG_TZ = 'Europe/London';

    private function window(): array
    {
        return [
            new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-31 00:00:00', new DateTimeZone('UTC')),
        ];
    }

    private function vcal(string $vevents): VCalendar
    {
        return Sabre\VObject\Reader::read(
            "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Eratotime test//EN\n" . $vevents . "END:VCALENDAR\n"
        );
    }

    // --- REPORT XML ---------------------------------------------------------

    public function testBuildCalendarQueryXml(): void
    {
        [$from, $to] = $this->window();
        $xml = caldav_build_calendar_query($from, $to);
        self::assertStringContainsString('calendar-query', $xml);
        self::assertStringContainsString('urn:ietf:params:xml:ns:caldav', $xml);
        self::assertStringContainsString('comp-filter name="VCALENDAR"', $xml);
        self::assertStringContainsString('comp-filter name="VEVENT"', $xml);
        self::assertStringContainsString('time-range start="20260801T000000Z" end="20260831T000000Z"', $xml);
        self::assertStringContainsString('calendar-data', $xml);
    }

    // --- Multistatus extraction ---------------------------------------------

    public function testExtractCalendarDataFromMultistatus(): void
    {
        $ical = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-1\nDTSTART:20260812T100000Z\nDTEND:20260812T110000Z\nSUMMARY:Busy\nEND:VEVENT\n"
        )->serialize();

        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/calendars/u/1.ics</d:href>
    <d:propstat><d:prop>
      <c:calendar-data>' . $ical . '</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>';

        $data = caldav_extract_calendar_data($xml);
        self::assertCount(1, $data);
        self::assertStringContainsString('UID:evt-1', $data[0]);
        self::assertStringContainsString('DTSTART:20260812T100000Z', $data[0]);
    }

    // --- Parsing ------------------------------------------------------------

    public function testParsesTimedEventWithTimezone(): void
    {
        $vcal = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-tz\nDTSTART;TZID=Europe/London:20260812T100000\nDTEND;TZID=Europe/London:20260812T110000\nEND:VEVENT\n"
        );
        $blocks = caldav_parse_busy_blocks([$vcal->serialize()], self::ORG_TZ, ...$this->window());
        self::assertCount(1, $blocks);
        self::assertSame('evt-tz', $blocks[0]['uid']);
        // 10:00 BST = 09:00 UTC.
        self::assertSame('2026-08-12 09:00:00', $blocks[0]['start_utc']->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-12 10:00:00', $blocks[0]['end_utc']->format('Y-m-d H:i:s'));
    }

    public function testParsesAllDayEventAsFullDayInOrganizerTz(): void
    {
        $vcal = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-allday\nDTSTART;VALUE=DATE:20260815\nDTEND;VALUE=DATE:20260816\nEND:VEVENT\n"
        );
        $blocks = caldav_parse_busy_blocks([$vcal->serialize()], self::ORG_TZ, ...$this->window());
        self::assertCount(1, $blocks);
        self::assertSame('evt-allday', $blocks[0]['uid']);
        // Full day in London (BST in August) = 2026-08-14 23:00 UTC .. 2026-08-15 23:00 UTC.
        self::assertSame('2026-08-14 23:00:00', $blocks[0]['start_utc']->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-15 23:00:00', $blocks[0]['end_utc']->format('Y-m-d H:i:s'));
    }

    public function testCancelledEventIsSkipped(): void
    {
        $vcal = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-cancelled\nDTSTART:20260812T100000Z\nDTEND:20260812T110000Z\nSTATUS:CANCELLED\nEND:VEVENT\n" .
            "BEGIN:VEVENT\nUID:evt-ok\nDTSTART:20260812T110000Z\nDTEND:20260812T120000Z\nEND:VEVENT\n"
        );
        $blocks = caldav_parse_busy_blocks([$vcal->serialize()], self::ORG_TZ, ...$this->window());
        self::assertCount(1, $blocks);
        self::assertSame('evt-ok', $blocks[0]['uid']);
    }

    public function testFloatingTimeIsInterpretedInOrganizerTz(): void
    {
        $vcal = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-floating\nDTSTART:20260812T140000\nDTEND:20260812T150000\nEND:VEVENT\n"
        );
        $blocks = caldav_parse_busy_blocks([$vcal->serialize()], self::ORG_TZ, ...$this->window());
        self::assertCount(1, $blocks);
        // 14:00 wall-clock in London (BST) = 13:00 UTC.
        self::assertSame('2026-08-12 13:00:00', $blocks[0]['start_utc']->format('Y-m-d H:i:s'));
    }

    public function testWeeklyRecurringEventExpandsWithinWindow(): void
    {
        // Every Monday 10:00-11:00 UTC, starting Mon 2026-08-03. Window Aug 2026.
        $vcal = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-recur\nDTSTART:20260803T100000Z\nDTEND:20260803T110000Z\n" .
            "RRULE:FREQ=WEEKLY;COUNT=6\nEND:VEVENT\n"
        );
        $blocks = caldav_parse_busy_blocks([$vcal->serialize()], self::ORG_TZ, ...$this->window());
        // Mondays in Aug 2026: 3, 10, 17, 24 (and 31 outside window's [1,31] range end).
        self::assertCount(4, $blocks);
        self::assertSame('2026-08-24 10:00:00', $blocks[3]['start_utc']->format('Y-m-d H:i:s'));
    }

    public function testEventOutsideWindowIsIgnored(): void
    {
        $vcal = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-out\nDTSTART:20260715T100000Z\nDTEND:20260715T110000Z\nEND:VEVENT\n"
        );
        $blocks = caldav_parse_busy_blocks([$vcal->serialize()], self::ORG_TZ, ...$this->window());
        self::assertCount(0, $blocks);
    }

    // --- End-to-end fetch with a mock transport ------------------------------

    public function testFetchPipelineWithMockHttp(): void
    {
        $ical = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-a\nDTSTART;TZID=Europe/London:20260812T100000\nDTEND;TZID=Europe/London:20260812T110000\nEND:VEVENT\n"
        )->serialize();
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
            '<d:response><d:href>/1.ics</d:href><d:propstat><d:prop><c:calendar-data>' . $ical . '</c:calendar-data></d:prop></d:propstat></d:response>' .
            '</d:multistatus>';

        $called = [];
        $http = function (string $method, string $url, string $body, string $user, string $pass) use (&$called, $xml): string {
            $called = [$method, $url, $user, $pass, $body];
            return $xml;
        };

        [$from, $to] = $this->window();
        $source = ['calendar_identifier' => 'https://www.meertec.ltd/baikal/html/dav.php/calendars/stephen@meertec.ltd/default/'];
        $blocks = caldav_fetch_busy_blocks($source, ['username' => 'stephen@meertec.ltd', 'password' => 'pw'], $from, $to, self::ORG_TZ, $http);

        self::assertSame('REPORT', $called[0]);
        self::assertSame($source['calendar_identifier'], $called[1]);
        self::assertSame('stephen@meertec.ltd', $called[2]);
        self::assertSame('pw', $called[3]);
        self::assertStringContainsString('time-range', $called[4]);
        self::assertCount(1, $blocks);
        self::assertSame('evt-a', $blocks[0]['uid']);
    }

    public function testMissingCalendarUrlThrows(): void
    {
        [$from, $to] = $this->window();
        $this->expectException(RuntimeException::class);
        caldav_fetch_busy_blocks([], ['username' => 'u', 'password' => 'p'], $from, $to, self::ORG_TZ);
    }

    public function testIcsFeedFallback(): void
    {
        $ical = $this->vcal(
            "BEGIN:VEVENT\nUID:evt-ics\nDTSTART:20260812T100000Z\nDTEND:20260812T110000Z\nEND:VEVENT\n"
        )->serialize();
        $http = fn() => $ical;
        [$from, $to] = $this->window();
        $blocks = ics_fetch_busy_blocks(['ics_url' => 'https://example.org/feed.ics'], $from, $to, self::ORG_TZ, $http);
        self::assertCount(1, $blocks);
        self::assertSame('evt-ics', $blocks[0]['uid']);
    }
}
