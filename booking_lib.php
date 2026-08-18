<?php

/**
 * booking_lib.php — shared public booking-page config builder (Phase 4).
 *
 * Builds the JSON payload that powers the booking widget (js/booking.js): the
 * meeting type's details + questions + CSRF/ALTCHA config, plus the list of
 * switchable meeting types. Used by book.php (inline data-config) and
 * api/type.php (AJAX type switching), so the two can't drift apart.
 *
 * Depends on tenant_lib.php (tenant_load) and security_lib.php
 * (security_csrf_issue, altcha_enabled), both composer-autoloaded.
 */

if (!function_exists('booking_config_build')) {

    /**
     * Build the booking widget config for one tenant + meeting type.
     * Returns null when the tenant or the (active) meeting type is missing.
     *
     * @return array|null shape: base, tenant_slug, type_slug, type{...},
     *                    organizer{...}, questions[], types[], csrf, altcha_enabled
     */
    function booking_config_build(PDO $pdo, array $config, string $tenantSlug, string $typeSlug, string $basePath): ?array
    {
        $tenant = tenant_load($pdo, $tenantSlug);
        if ($tenant === null) {
            return null;
        }
        $tenantId = (int) $tenant['tenant']['id'];
        $settings = $tenant['settings'];

        $stmt = $pdo->prepare(
            'SELECT mt.* FROM meeting_types mt WHERE mt.tenant_id = ? AND mt.slug = ? AND mt.active = 1 LIMIT 1'
        );
        $stmt->execute([$tenantId, $typeSlug]);
        $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($type === false) {
            return null;
        }

        $qStmt = $pdo->prepare('SELECT label, type, required, sort_order FROM meeting_type_questions WHERE meeting_type_id = ? ORDER BY sort_order');
        $qStmt->execute([$type['id']]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        $tStmt = $pdo->prepare('SELECT slug, name, duration_min, sort_order FROM meeting_types WHERE tenant_id = ? AND active = 1 ORDER BY sort_order');
        $tStmt->execute([$tenantId]);
        $types = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'base' => $basePath,
            'tenant_slug' => $tenantSlug,
            'type_slug' => $typeSlug,
            'type' => [
                'name' => $type['name'],
                'description' => $type['description'],
                'duration_min' => (int) $type['duration_min'],
                'location_details' => $type['location_details'],
                'video_link' => $type['video_link'],
            ],
            'organizer' => [
                'name' => $tenant['tenant']['display_name'] ?? 'Meertec',
                'bio' => $settings['organizer_bio'] ?? null,
                'photo' => $settings['organizer_photo_path'] ?? null,
            ],
            'questions' => $questions,
            'types' => $types,
            'csrf' => security_csrf_issue((string) ($config['csrf_secret'] ?? '')),
            'altcha_enabled' => altcha_enabled($config),
        ];
    }
}
