<?php

/**
 * api/availability_ics.php — public, subscribable availability feed (.ics).
 *
 *   GET api/availability_ics.php?tenant={slug}[&type={type}]
 *   Routed pretty:  /t/{tenant}/availability.ics
 *                   /t/{tenant}/availability/{type}.ics
 *
 * Serializes the meeting type's open windows over its booking horizon as an
 * Internet Calendar. Read-only and unauthenticated (availability is public on
 * the booking page too). Availability is computed against the local cache
 * exactly like api/slots.php and fails closed on stale sync (empty calendar).
 *
 * NOTE: "Available" here is bookable time, not Outlook free/busy — Outlook's
 * Scheduling Assistant ignores subscribed calendars (see docs).
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../availability_context_lib.php';
require __DIR__ . '/../ics_lib.php';

$config = require __DIR__ . '/../config.php';

function avail_ics_fail(int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

try {
    if (!isset($config['db']['name']) || $config['db']['name'] === '') {
        avail_ics_fail(500);
    }

    $tenantSlug = (string) ($_GET['tenant'] ?? '');
    if ($tenantSlug === '') {
        avail_ics_fail(404);
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        (int) ($config['db']['port'] ?? 3306),
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $tenant = tenant_load($pdo, $tenantSlug);
    if ($tenant === null) {
        avail_ics_fail(404);
    }
    $tenantId = (int) $tenant['tenant']['id'];
    $orgTzName = (string) ($tenant['settings']['organizer_timezone'] ?? 'Europe/London');

    // Type: explicit, or the first active type by sort order.
    $typeSlug = (string) ($_GET['type'] ?? '');
    if ($typeSlug === '') {
        $t = $pdo->prepare('SELECT slug FROM meeting_types WHERE tenant_id = ? AND active = 1 ORDER BY sort_order, id LIMIT 1');
        $t->execute([$tenantId]);
        $typeSlug = (string) ($t->fetchColumn() ?: '');
    }
    if ($typeSlug === '') {
        avail_ics_fail(404);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone($orgTzName));
    $ctx = availability_ctx_load($pdo, $tenantId, $typeSlug, $now);
    if ($ctx === null) {
        avail_ics_fail(404);
    }

    $result = ics_build_availability($ctx, $now);

    http_response_code(200);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="eratotime-availability.ics"');
    header('Cache-Control: public, max-age=600');
    echo $result['ical'];
} catch (Throwable $e) {
    avail_ics_fail(500);
}
