<?php

/**
 * notify_lib.php — outbound notifications (spec 2.5 / 4.5).
 *
 * Compose and send the two emails + optional WhatsApp for a request, driven by
 * notification_outbox rows. Subject prefixes are constants (spec 2.5, designed
 * for email-client filtering). The organizer email carries an `.ics`
 * calendar-import file (built with Sabre/VObject) pre-filled with the time,
 * the `Eratotime:` title, LOCATION = the fixed Meet link, and the answers —
 * the organizer imports it into the Baïkal calendar (Thunderbird). That `.ics`
 * is for the organizer's own calendar only; it is never sent to the invitee.
 *
 * The Gmail address must never appear here — every email From: header comes
 * from global_settings.mailbox_destination (stephen@meertec.ltd).
 *
 * Delivery: PHPMailer over SMTP. In dev (no SMTP host configured) sends are
 * no-ops so the pipeline is testable; a config['smtp']['on_send'] callable can
 * intercept the composed message in tests.
 */

if (!function_exists('notify_send_email')) {

    /**
     * Deliver an email via PHPMailer/SMTP. Returns true on success.
     * $ics is a raw text/calendar string attached as an .ics file.
     */
    function notify_send_email(array $config, string $to, string $subject, string $html, string $alt, ?string $ics = null): bool
    {
        $smtp = $config['smtp'] ?? [];
        if (isset($smtp['on_send']) && is_callable($smtp['on_send'])) {
            return (bool) call_user_func($smtp['on_send'], $to, $subject, $html, $alt, $ics);
        }
        if (empty($smtp['host'])) {
            return true; // dev mode: no SMTP configured — treat as sent
        }
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->Port = (int) ($smtp['port'] ?? 465);
            $mail->SMTPAuth = !empty($smtp['user']);
            if (!empty($smtp['user'])) {
                $mail->Username = $smtp['user'];
                $mail->Password = $smtp['pass'];
            }
            $secure = $smtp['secure'] ?? 'ssl';
            if ($secure !== '') {
                $mail->SMTPSecure = $secure;
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($smtp['from'] ?? 'stephen@meertec.ltd', 'Eratotime');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $alt;
            if ($ics !== null) {
                $mail->addStringAttachment($ics, 'eratotime-meeting.ics', 'base64', 'text/calendar');
            }
            $mail->send();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * WhatsApp via CallMeBot/TextMeBot (pattern from the FIFA sweepstake app).
     * No-op in dev (no API key configured).
     */
    function notify_send_whatsapp(array $config, string $number, string $text): bool
    {
        $key = (string) ($config['whatsapp_api_key'] ?? '');
        if ($key === '') {
            return true;
        }
        $url = 'https://api.callmebot.com/whatsapp.php?phone=' . rawurlencode($number) . '&apikey=' . rawurlencode($key) . '&text=' . rawurlencode($text);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $resp !== false && $code === 200;
    }

    /**
     * Format a UTC datetime in a named timezone as e.g. "Wed 12 Aug 2026, 10:00".
     */
    function notify_format_time(string $utc, string $tzName): string
    {
        $dt = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tzName));
        return $dt->format('D j M Y, H:i');
    }

    /**
     * Build the organizer's .ics calendar-import file (Sabre/VObject).
     * SUMMARY follows the 2.5 convention; LOCATION carries the fixed Meet link.
     */
    function notify_build_ics(array $request, array $type, array $settings): string
    {
        $answers = is_array($request['custom_answers'] ?? null) ? $request['custom_answers'] : json_decode((string) ($request['custom_answers'] ?? '{}'), true);
        $answers = is_array($answers) ? $answers : [];
        $lines = [];
        foreach ($answers as $a) {
            $label = $a['label'] ?? 'Question';
            $answer = (string) ($a['answer'] ?? '');
            if ($answer !== '') {
                $lines[] = $label . ': ' . $answer;
            }
        }
        $guests = is_array($request['guest_emails'] ?? null) ? $request['guest_emails'] : json_decode((string) ($request['guest_emails'] ?? '[]'), true);
        if (is_array($guests) && $guests !== []) {
            $lines[] = 'Guests: ' . implode(', ', $guests);
        }
        $lines[] = 'Organiser: ' . ($settings['mailbox_destination'] ?? '');

        $vcal = new Sabre\VObject\Component\VCalendar();
        $vcal->add('VEVENT', [
            'UID' => 'eratotime-' . $request['id'] . '-' . bin2hex(random_bytes(6)),
            'DTSTAMP' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'SUMMARY' => 'Eratotime: ' . $type['name'] . ' — ' . $request['invitee_name'],
            'DTSTART' => new DateTimeImmutable($request['requested_start_utc'], new DateTimeZone('UTC')),
            'DTEND' => new DateTimeImmutable($request['requested_end_utc'], new DateTimeZone('UTC')),
            'LOCATION' => (string) ($type['location_details'] ?? ''),
            'DESCRIPTION' => implode("\n", $lines),
        ]);
        return $vcal->serialize();
    }

    /**
     * Invitee confirmation email (2.5).
     */
    function notify_compose_invitee(array $request, array $type, array $settings): array
    {
        $tz = (string) ($request['invitee_timezone'] ?? 'UTC');
        $when = notify_format_time($request['requested_start_utc'], $tz);
        $subject = '[Eratotime] Confirmation — ' . $type['name'];
        $html = '<div style="font-family:Helvetica,Arial,sans-serif;color:#1B1A17;max-width:560px">' .
            '<h2 style="font-size:20px;margin:0 0 12px">Your request is in</h2>' .
            '<p>Hi ' . htmlspecialchars($request['invitee_name']) . ',</p>' .
            '<p>We received your request for:</p>' .
            '<p style="background:#F6F3EC;border-left:3px solid #B08D57;padding:12px 16px">' .
            '<strong>' . htmlspecialchars($type['name']) . '</strong> · ' . htmlspecialchars($when) . '<br>' .
            'Duration: ' . (int) $type['duration_min'] . ' minutes</p>';
        if (!empty($type['location_details'])) {
            $loc = $type['location_details'];
            $href = preg_match('#^https?://#i', (string) $loc) ? (string) $loc : 'mailto:' . $loc;
            $html .= '<p>Where: <a href="' . htmlspecialchars($href) . '">' . htmlspecialchars((string) $loc) . '</a></p>';
        }
        $html .= '<p>Dr Stephen D. Jones will confirm the meeting by sending the calendar invitation. ' .
            'If you need to change or cancel, reply to this email or contact ' .
            htmlspecialchars((string) ($settings['mailbox_destination'] ?? '')) . '.</p></div>';
        $alt = "Your request is in\n\n" . $request['invitee_name'] . ",\n\nWe received your request for:\n" .
            $type['name'] . ' · ' . $when . ' (' . $type['duration_min'] . ' min).\n' .
            'The organiser will confirm by sending the calendar invitation.';
        return ['subject' => $subject, 'html' => $html, 'alt' => $alt];
    }

    /**
     * Organizer new-request notification + .ics import attachment (2.5).
     */
    function notify_compose_organizer(array $request, array $type, array $settings): array
    {
        $orgTz = (string) ($settings['organizer_timezone'] ?? 'Europe/London');
        $when = notify_format_time($request['requested_start_utc'], $orgTz);
        $subject = '[Eratotime Request] ' . $type['name'] . ' — ' . $request['invitee_name'];

        $answers = is_array($request['custom_answers'] ?? null) ? $request['custom_answers'] : [];
        $guests = is_array($request['guest_emails'] ?? null) ? $request['guest_emails'] : [];

        $html = '<div style="font-family:Helvetica,Arial,sans-serif;color:#1B1A17;max-width:560px">' .
            '<h2 style="font-size:20px;margin:0 0 12px">New booking request</h2>' .
            '<p style="background:#F6F3EC;border-left:3px solid #B08D57;padding:12px 16px">' .
            '<strong>' . htmlspecialchars($type['name']) . '</strong> · ' . htmlspecialchars($when) . '<br>' .
            'Duration: ' . (int) $type['duration_min'] . ' min</p>' .
            '<p><strong>Invitee:</strong> ' . htmlspecialchars($request['invitee_name']) . ' &lt;' . htmlspecialchars($request['invitee_email']) . '&gt;</p>';
        if ($guests !== []) {
            $html .= '<p><strong>Guests:</strong> ' . htmlspecialchars(implode(', ', $guests)) . '</p>';
        }
        foreach ($answers as $a) {
            $label = (string) ($a['label'] ?? 'Question');
            $answer = (string) ($a['answer'] ?? '');
            if ($answer !== '') {
                $html .= '<p><strong>' . htmlspecialchars($label) . ':</strong> ' . nl2br(htmlspecialchars($answer)) . '</p>';
            }
        }
        if (!empty($type['location_details'])) {
            $html .= '<p><strong>Location / meeting link:</strong> ' . htmlspecialchars((string) $type['location_details']) . '</p>';
        }
        $html .= '<p>Import the attached <strong>eratotime-meeting.ics</strong> into the Baïkal calendar (Thunderbird) ' .
            'to create the event pre-filled — the Meet link is already in it. Then mark the request fulfilled in the admin panel.</p></div>';

        $alt = "New booking request\n\n" . $type['name'] . ' · ' . $when . "\nInvitee: " . $request['invitee_name'] .
            ' <' . $request['invitee_email'] . ">\n" .
            (($guests !== []) ? 'Guests: ' . implode(', ', $guests) . "\n" : '') .
            'Import the attached .ics to create the event (Meet link included).';
        return ['subject' => $subject, 'html' => $html, 'alt' => $alt];
    }

    /**
     * Process due notification_outbox rows: compose, send, mark. Called inline
     * after booking and by cron/retry_notifications.php. Returns per-row results.
     */
    function notify_process_outbox(PDO $pdo, array $config, int $limit = 50, array $settingsByTenant = []): array
    {
        $rows = $pdo->prepare(
            "SELECT o.id AS outbox_id, o.template, o.recipient, o.status, o.attempts, o.last_attempt_at, o.next_retry_at,
                    r.id AS request_id, r.invitee_name, r.invitee_email, r.invitee_timezone,
                    r.guest_emails, r.custom_answers, r.requested_start_utc, r.requested_end_utc,
                    mt.name AS type_name, mt.duration_min, mt.location_details,
                    g.organizer_timezone, g.mailbox_destination, g.whatsapp_destination_number
               FROM notification_outbox o
               JOIN request_log r ON r.id = o.request_log_id
               JOIN meeting_types mt ON mt.id = r.meeting_type_id
               JOIN global_settings g ON g.tenant_id = r.tenant_id
              WHERE o.status = 'pending' AND (o.next_retry_at IS NULL OR o.next_retry_at <= UTC_TIMESTAMP())
              ORDER BY o.id LIMIT {$limit}"
        );
        $rows->execute();
        $results = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $request = [
                'id' => $row['request_id'],
                'invitee_name' => $row['invitee_name'],
                'invitee_email' => $row['invitee_email'],
                'invitee_timezone' => $row['invitee_timezone'],
                'guest_emails' => $row['guest_emails'],
                'custom_answers' => $row['custom_answers'],
                'requested_start_utc' => $row['requested_start_utc'],
                'requested_end_utc' => $row['requested_end_utc'],
            ];
            $type = ['name' => $row['type_name'], 'duration_min' => $row['duration_min'], 'location_details' => $row['location_details']];
            $settings = [
                'organizer_timezone' => $row['organizer_timezone'],
                'mailbox_destination' => $row['mailbox_destination'],
                'whatsapp_destination_number' => $row['whatsapp_destination_number'],
            ];
            $outboxId = (int) $row['outbox_id'];

            $sent = false;
            if ($row['template'] === 'whatsapp_organizer') {
                $text = 'New request: ' . $type['name'] . ' — ' . notify_format_time($row['requested_start_utc'], $settings['organizer_timezone']) . ' (' . $row['invitee_name'] . ')';
                $sent = notify_send_whatsapp($config, (string) $row['recipient'], $text);
            } else {
                $composed = $row['template'] === 'organizer_request'
                    ? notify_compose_organizer($request, $type, $settings)
                    : notify_compose_invitee($request, $type, $settings);
                $ics = $row['template'] === 'organizer_request' ? notify_build_ics($request, $type, $settings) : null;
                $sent = notify_send_email($config, (string) $row['recipient'], $composed['subject'], $composed['html'], $composed['alt'], $ics);
            }

            $attempts = (int) $row['attempts'] + 1;
            if ($sent) {
                $pdo->prepare("UPDATE notification_outbox SET status='sent', attempts=?, last_attempt_at=UTC_TIMESTAMP(), last_error=NULL WHERE id=?")
                    ->execute([$attempts, $outboxId]);
                $results[] = ['outbox_id' => $outboxId, 'ok' => true];
            } else {
                $giveUp = $attempts >= 20 || ($row['last_attempt_at'] !== null && (time() - strtotime((string) $row['last_attempt_at'])) > 86400);
                $next = $giveUp ? null : $now->modify('+' . min(60 * (int) $attempts, 3600) . ' seconds')->format('Y-m-d H:i:s');
                $pdo->prepare("UPDATE notification_outbox SET status=?, attempts=?, last_attempt_at=UTC_TIMESTAMP(), next_retry_at=?, last_error=? WHERE id=?")
                    ->execute([$giveUp ? 'failed' : 'pending', $attempts, $next, 'delivery failed', $outboxId]);
                $results[] = ['outbox_id' => $outboxId, 'ok' => false];
            }
        }
        return $results;
    }
}
