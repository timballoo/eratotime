<?php

/**
 * request_lib.php — request submission (spec 2.3 / 4.2 / 4.5).
 *
 * The unauthenticated write path. Runs in a transaction:
 *   1. per-tenant serialization point (SELECT ... FOR UPDATE on global_settings),
 *   2. availability re-check for the requested slot (anti-double-booking race
 *      guard), using the pre-synced local cache — never a live calendar call,
 *   3. insert request_log (soft-hold for request_hold_hours),
 *   4. queue notification_outbox rows (invitee email, organizer email, and
 *      WhatsApp if enabled) — decoupled from the durable write.
 * The unique key (tenant_id, requested_start_utc, invitee_email) is the last
 * line of defence against identical duplicate submissions (4.2).
 *
 * The caller (api/requests.php) runs the transport guards (rate limit, CSRF,
 * ALTCHA, honeypot) before calling request_submit(); this function owns the
 * domain logic and is fully testable without HTTP.
 */

if (!function_exists('request_submit')) {

    /**
     * Validate + persist a booking request.
     *
     * @return array ['ok'=>bool, 'error'=>?string, 'request_id'=>?int, 'message'=>?string]
     */
    function request_submit(PDO $pdo, array $config, array $input): array
    {
        $tenantSlug = (string) ($input['tenant'] ?? '');
        $typeSlug = (string) ($input['type'] ?? '');
        $slotUtc = (string) ($input['slot_utc'] ?? '');
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $timezone = (string) ($input['timezone'] ?? 'UTC');
        $videoCall = !empty($input['video_call']) ? 1 : 0;
        $questions = is_array($input['questions'] ?? null) ? $input['questions'] : [];
        $guests = is_array($input['guests'] ?? null) ? $input['guests'] : [];

        if ($tenantSlug === '' || $typeSlug === '' || $slotUtc === '') {
            return ['ok' => false, 'error' => 'Missing tenant, type or slot.'];
        }
        if ($name === '') {
            return ['ok' => false, 'error' => 'Please provide your name.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please provide a valid email address.'];
        }
        foreach ($guests as $g) {
            if ($g !== '' && !filter_var($g, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => 'One of the guest email addresses is invalid.'];
            }
        }
        $guests = array_values(array_filter(array_map('trim', $guests), fn($g) => $g !== ''));
        $questions = array_values(array_filter($questions, fn($q) => is_array($q) && isset($q['label'], $q['answer'])));

        try {
            $slot = new DateTimeImmutable($slotUtc, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Invalid slot time.'];
        }

        // Resolve tenant + type up front (outside the txn is fine — read-only).
        $tenant = tenant_load($pdo, $tenantSlug);
        if ($tenant === null) {
            return ['ok' => false, 'error' => 'Tenant not found.'];
        }
        $tenantId = (int) $tenant['tenant']['id'];
        $settings = $tenant['settings'];

        $typeStmt = $pdo->prepare('SELECT * FROM meeting_types WHERE tenant_id = ? AND slug = ? AND active = 1 LIMIT 1');
        $typeStmt->execute([$tenantId, $typeSlug]);
        $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
        if ($type === false) {
            return ['ok' => false, 'error' => 'Meeting type not found.'];
        }
        $typeId = (int) $type['id'];

        $orgTzName = (string) ($settings['organizer_timezone'] ?? 'Europe/London');
        $orgTz = new DateTimeZone($orgTzName);

        $pdo->beginTransaction();
        try {
            // Per-tenant serialization point: serialize concurrent submissions
            // for this tenant so the re-check + insert is race-free (4.2).
            $pdo->prepare('SELECT id FROM global_settings WHERE tenant_id = ? FOR UPDATE')->execute([$tenantId]);

            $ctx = availability_ctx_load($pdo, $tenantId, $typeSlug, new DateTimeImmutable('now', $orgTz));
            if ($ctx === null) {
                throw new RuntimeException('Meeting type not available.');
            }
            if ($ctx['stale']) {
                throw new RuntimeException('Availability is temporarily unavailable — please try again later.');
            }

            $slotOrg = $slot->setTimezone($orgTz);
            $date = $slotOrg->format('Y-m-d');
            $day = availability_day(availability_ctx_for_date($ctx, $date));
            $utcSet = [];
            foreach ($day['slots'] as $s) {
                $utcSet[] = (new DateTimeImmutable($date . ' ' . $s . ':00', $orgTz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if (!in_array($slot->format('Y-m-d H:i:s'), $utcSet, true)) {
                throw new RuntimeException('That time is no longer available — please pick another slot.');
            }

            $holdHours = max(1, (int) ($settings['request_hold_hours'] ?? 24));
            $softHoldExpires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$holdHours} hours")->format('Y-m-d H:i:s');
            $start = $slot->format('Y-m-d H:i:s');
            $end = $slot->add(new DateInterval('PT' . (int) $type['duration_min'] . 'M'))->format('Y-m-d H:i:s');

            $ins = $pdo->prepare(
                "INSERT INTO request_log
                   (tenant_id, meeting_type_id, invitee_name, invitee_email, invitee_timezone,
                    guest_emails, requested_start_utc, requested_end_utc, custom_answers, video_call,
                    status, soft_hold_expires_at, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, UTC_TIMESTAMP())"
            );
            $ins->execute([
                $tenantId, $typeId, $name, $email, $timezone,
                json_encode($guests, JSON_UNESCAPED_UNICODE),
                $start, $end,
                json_encode($questions, JSON_UNESCAPED_UNICODE),
                $videoCall,
                $softHoldExpires,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $queue = $pdo->prepare(
                "INSERT INTO notification_outbox (tenant_id, request_log_id, channel, recipient, template, status, next_retry_at)
                 VALUES (?, ?, 'email', ?, ?, 'pending', UTC_TIMESTAMP())"
            );
            $queue->execute([$tenantId, $requestId, $email, 'invitee_confirmation']);
            $queue->execute([$tenantId, $requestId, $settings['mailbox_destination'] ?? '', 'organizer_request']);
            if (!empty($settings['whatsapp_enabled']) && !empty($settings['whatsapp_destination_number'])) {
                $pdo->prepare(
                    "INSERT INTO notification_outbox (tenant_id, request_log_id, channel, recipient, template, status, next_retry_at)
                     VALUES (?, ?, 'whatsapp', ?, 'whatsapp_organizer', 'pending', UTC_TIMESTAMP())"
                )->execute([$tenantId, $requestId, $settings['whatsapp_destination_number']]);
            }

            $pdo->prepare("INSERT INTO activity_log (tenant_id, event_type, detail) VALUES (?, 'request_created', ?)")
                ->execute([$tenantId, json_encode(['request_id' => $requestId, 'type' => $typeSlug, 'start_utc' => $start])]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (int) $e->getCode() === 23000) {
                return ['ok' => false, 'error' => 'You have already requested this time slot.'];
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Fire-and-forget: attempt immediate notification; failures stay pending
        // for cron/retry_notifications.php (4.5).
        try {
            notify_process_outbox($pdo, $config, 10);
        } catch (Throwable $e) {
            // notifications must never fail the booking
        }

        return [
            'ok' => true,
            'request_id' => $requestId,
            'message' => 'Request received. The organizer will confirm by sending the calendar invitation.',
        ];
    }
}
