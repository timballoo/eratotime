<?php

/**
 * providers/meet_provider.php — dynamic Google Meet link generation.
 *
 * Generates a unique Google Meet link per booking via the Google Calendar API v3.
 * Uses a Service Account to authenticate and create a temporary event on a
 * dedicated "Eratotime Meet Rooms" calendar, extracts the Meet link from the
 * conference data, and optionally deletes the temporary event.
 *
 * The Gmail address (meertec.ltd@gmail.com) never appears in any output —
 * it is purely a server-side API credential.  Attendees see
 * stephen@meertec.ltd as the organizer (from the email From: header and
 * the .ics file).
 *
 * Requires:
 *   - google/apiclient (already in composer.json)
 *   - Service Account JSON key (outside web root)
 *   - A shared calendar with the Service Account granted "Make changes to events"
 *
 * Config keys (in $config['google_meet']):
 *   'service_account_path' — absolute path to the Service Account JSON key
 *   'calendar_id'          — Google Calendar ID (from Settings -> Integrate)
 */

if (!function_exists('meet_build_client')) {

    /**
     * Build an authenticated Google API client using the Service Account.
     *
     * @param array $config  Full app config (needs config['google_meet'])
     * @return \Google_Client
     * @throws RuntimeException if the Service Account path is missing or invalid
     */
    function meet_build_client(array $config): \Google_Client
    {
        $meetConfig = $config['google_meet'] ?? [];
        $saPath = (string) ($meetConfig['service_account_path'] ?? '');

        if ($saPath === '' || !is_file($saPath)) {
            throw new RuntimeException('Google Meet Service Account JSON not found: ' . ($saPath ?: '(not configured)'));
        }

        $client = new \Google\Client([
            'credentials' => $saPath,
            'scopes' => [\Google\Service\Calendar::CALENDAR],
        ]);

        return $client;
    }

    /**
     * Generate a unique Google Meet link by creating a temporary calendar event.
     *
     * Returns ['link' => string, 'event_id' => string] on success, or null on failure.
     * The caller can optionally delete the event using meet_delete_event().
     *
     * @param array         $config   Full app config
     * @param string        $summary  Event summary (for the temp event)
     * @param DateTimeImmutable $start Event start time
     * @param DateTimeImmutable $end   Event end time
     * @param string        $tz       IANA timezone name (e.g. 'Europe/London')
     * @return array|null  ['link' => string, 'event_id' => string] or null
     */
    function meet_create_link(array $config, string $summary, DateTimeImmutable $start, DateTimeImmutable $end, string $tz): ?array
    {
        $meetConfig = $config['google_meet'] ?? [];
        $calendarId = (string) ($meetConfig['calendar_id'] ?? '');

        if ($calendarId === '') {
            return null;
        }

        try {
            $client = meet_build_client($config);
            $service = new \Google\Service\Calendar($client);

            $utc = new DateTimeZone('UTC');
            $startUtc = $start->setTimezone($utc);
            $endUtc = $end->setTimezone($utc);

            $event = new \Google\Service\Calendar\Event([
                'summary' => $summary !== '' ? $summary : 'Eratotime booking',
                'start' => [
                    'dateTime' => $startUtc->format('Y-m-d\TH:i:s\Z'),
                    'timeZone' => $tz,
                ],
                'end' => [
                    'dateTime' => $endUtc->format('Y-m-d\TH:i:s\Z'),
                    'timeZone' => $tz,
                ],
                'transparency' => 'transparent',
                'visibility' => 'private',
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => bin2hex(random_bytes(16)),
                        'conferenceSolutionKey' => [
                            'type' => 'hangoutsMeet',
                        ],
                    ],
                ],
            ]);

            $created = $service->events->insert($calendarId, $event, [
                'conferenceDataVersion' => 1,
                'sendUpdates' => 'none',
            ]);

            $link = $created->getHangoutLink();
            if ($link === null || $link === '') {
                $entryPoints = $created->getConferenceData()->getEntryPoints() ?? [];
                foreach ($entryPoints as $ep) {
                    if ($ep->getEntryPointType() === 'video') {
                        $link = $ep->getUri();
                        break;
                    }
                }
            }

            if ($link === null || $link === '') {
                return null;
            }

            return [
                'link' => $link,
                'event_id' => (string) $created->getId(),
            ];
        } catch (Throwable $e) {
            error_log('[Eratotime] meet_create_link failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a temporary calendar event by ID.
     *
     * @param array    $config  Full app config
     * @param string   $eventId Google Calendar event ID
     * @return bool
     */
    function meet_delete_event(array $config, string $eventId): bool
    {
        $meetConfig = $config['google_meet'] ?? [];
        $calendarId = (string) ($meetConfig['calendar_id'] ?? '');

        if ($calendarId === '' || $eventId === '') {
            return false;
        }

        try {
            $client = meet_build_client($config);
            $service = new \Google\Service\Calendar($client);
            $service->events->delete($calendarId, $eventId, ['sendUpdates' => 'none']);
            return true;
        } catch (Throwable $e) {
            error_log('[Eratotime] meet_delete_event failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check whether dynamic Meet link generation is configured and usable.
     *
     * @param array $config  Full app config
     * @return bool
     */
    function meet_is_configured(array $config): bool
    {
        $meetConfig = $config['google_meet'] ?? [];
        $saPath = (string) ($meetConfig['service_account_path'] ?? '');
        $calendarId = (string) ($meetConfig['calendar_id'] ?? '');

        return $saPath !== '' && is_file($saPath) && $calendarId !== '';
    }
}
