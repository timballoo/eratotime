<?php

/**
 * api/slots.php — available dates / slots (spec 2.3, section 7).
 *
 *   GET api/slots.php?tenant={slug}&type={type}&month={yyyy-mm}  -> dates with open slots
 *   GET api/slots.php?tenant={slug}&type={type}&date={yyyy-mm-dd}-> slots for that day
 *
 * All computation runs against the local cache (blockouts + soft-holds) — never
 * a live call to the calendar (spec 4.4). Slots are returned as organizer-tz
 * wall-clock times plus their UTC instants so the client can render in the
 * invitee's timezone. If any active sync source is stale, availability fails
 * closed (returns no dates/slots) per spec 3.5.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../availability_context_lib.php';

$config = require __DIR__ . '/../config.php';

function slots_json_out(Throwable $e, int $status = 500): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

function slots_json_ok(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!isset($config['db']['name']) || $config['db']['name'] === '') {
        throw new RuntimeException('DB not configured');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        (int) ($config['db']['port'] ?? 3306),
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $tenantSlug = (string) ($_GET['tenant'] ?? '');
    $typeSlug = (string) ($_GET['type'] ?? '');
    if ($tenantSlug === '' || $typeSlug === '') {
        slots_json_out(new RuntimeException('tenant and type parameters are required'), 400);
    }

    $tenant = tenant_load($pdo, $tenantSlug);
    if ($tenant === null) {
        slots_json_out(new RuntimeException('tenant not found'), 404);
    }
    $tenantId = (int) $tenant['tenant']['id'];

    $ctx = availability_ctx_load($pdo, $tenantId, $typeSlug, new DateTimeImmutable('now', new DateTimeZone($tenant['settings']['organizer_timezone'] ?? 'Europe/London')));
    if ($ctx === null) {
        slots_json_out(new RuntimeException('meeting type not found or inactive'), 404);
    }

    $type = $ctx['meeting_type'];
    $typeInfo = [
        'slug' => $type['slug'],
        'name' => $type['name'],
        'description' => $type['description'],
        'duration_min' => (int) $type['duration_min'],
        'location_details' => $type['location_details'],
    ];

    // Fail closed: no availability if any active source is stale.
    if ($ctx['stale']) {
        slots_json_ok([
            'ok' => true, 'stale' => true, 'type' => $typeInfo,
            'org_tz' => $ctx['org_tz_name'], 'dates' => [], 'slots' => [], 'schedule' => null,
        ]);
    }

    $now = new DateTimeImmutable('now', $ctx['org_tz']);

    if (isset($_GET['month'])) {
        $month = (string) $_GET['month'];
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            slots_json_out(new RuntimeException('month must be yyyy-mm'), 400);
        }
        $dates = [];
        $days = (int) $now->format('j');
        $first = new DateTimeImmutable($month . '-01 12:00:00', $ctx['org_tz']);
        $total = (int) $first->format('t');
        for ($d = 1; $d <= $total; $d++) {
            $date = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
            if ($date < $now->format('Y-m-d')) {
                continue; // past dates never have open slots
            }
            $r = availability_day(availability_ctx_for_date($ctx, $date));
            if ($r['slots'] !== []) {
                $dates[] = $date;
            }
        }
        slots_json_ok(['ok' => true, 'stale' => false, 'type' => $typeInfo, 'org_tz' => $ctx['org_tz_name'], 'dates' => $dates]);
    }

    $date = (string) ($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        slots_json_out(new RuntimeException('date must be yyyy-mm-dd'), 400);
    }
    $r = availability_day(availability_ctx_for_date($ctx, $date));
    $utcSlots = [];
    foreach ($r['slots'] as $slot) {
        $dt = new DateTimeImmutable($date . ' ' . $slot . ':00', $ctx['org_tz']);
        $utcSlots[] = $dt->setTimezone(new DateTimeZone('UTC'))->format('c');
    }
    slots_json_ok([
        'ok' => true, 'stale' => false, 'type' => $typeInfo,
        'org_tz' => $ctx['org_tz_name'],
        'schedule' => $r['schedule'],
        'slots' => $r['slots'],
        'utc_slots' => $utcSlots,
    ]);
} catch (Throwable $e) {
    slots_json_out($e, $e instanceof RuntimeException && in_array($e->getMessage(), ['tenant not found', 'meeting type not found or inactive'], true) ? 404 : 500);
}
