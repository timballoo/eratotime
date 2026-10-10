<?php

/**
 * api/ics.php — single-request .ics download (the invitee's "Add to calendar").
 *
 *   GET api/ics.php?id={requestId}&token={token}
 *
 * The token is a stateless HMAC over the request id (security_signed_token,
 * signed with config['csrf_secret']), so the endpoint is unauthenticated but
 * non-enumerable and needs no DB column. Serves the same VEVENT as the
 * organizer's import file (notify_build_ics): SUMMARY "Eratotime: ...",
 * LOCATION = the meeting link. Never exposes the Google/Gmail address.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../security_lib.php';
require __DIR__ . '/../notify_lib.php';

$config = require __DIR__ . '/../config.php';

function ics_fail(int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

try {
    if (!isset($config['db']['name']) || $config['db']['name'] === '') {
        ics_fail(500);
    }

    $id = (int) ($_GET['id'] ?? 0);
    $token = (string) ($_GET['token'] ?? '');
    $secret = (string) ($config['csrf_secret'] ?? '');
    if ($id <= 0 || $token === '' || $secret === '') {
        ics_fail(404);
    }

    // Light per-IP rate limit so the endpoint can't be used as a probe.
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $runtimeDir = (string) ($config['runtime_dir'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eratotime');
    if (!security_rate_limit('ics:' . $ip, 60, 300, $runtimeDir)) {
        ics_fail(429);
    }

    $payload = security_signed_token_verify($token, $secret);
    if ($payload !== 'request:' . $id) {
        ics_fail(404);
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        (int) ($config['db']['port'] ?? 3306),
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $stmt = $pdo->prepare(
        'SELECT r.*, mt.name AS type_name, mt.duration_min, mt.location_details, mt.video_link, mt.message_template,
                g.organizer_timezone, g.mailbox_destination, g.email_signature_path
           FROM request_log r
           LEFT JOIN meeting_types mt ON mt.id = r.meeting_type_id
           JOIN global_settings g ON g.tenant_id = r.tenant_id
          WHERE r.id = ?
          LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        ics_fail(404);
    }

    $request = [
        'id' => $row['id'],
        'invitee_name' => $row['invitee_name'],
        'invitee_email' => $row['invitee_email'],
        'invitee_timezone' => $row['invitee_timezone'],
        'guest_emails' => $row['guest_emails'],
        'custom_answers' => $row['custom_answers'],
        'video_call' => $row['video_call'],
        'meet_link' => $row['meet_link'],
        'requested_start_utc' => $row['requested_start_utc'],
        'requested_end_utc' => $row['requested_end_utc'],
    ];
    $type = [
        'name' => $row['type_name'] ?? 'Meeting',
        'duration_min' => (int) ($row['duration_min'] ?? 30),
        'location_details' => $row['location_details'],
        'video_link' => $row['video_link'],
        'message_template' => $row['message_template'],
    ];
    $settings = [
        'organizer_timezone' => $row['organizer_timezone'] ?? 'Europe/London',
        'mailbox_destination' => $row['mailbox_destination'] ?? '',
        'email_signature_path' => $row['email_signature_path'] ?? null,
    ];

    $ics = notify_build_ics($request, $type, $settings);

    http_response_code(200);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="eratotime-meeting.ics"');
    header('Cache-Control: private, no-store');
    echo $ics;
} catch (Throwable $e) {
    ics_fail(500);
}
