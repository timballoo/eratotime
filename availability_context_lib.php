<?php

/**
 * availability_context_lib.php — DB-backed assembly of the pure availability
 * engine's inputs (spec 2.2/4.4). The engine (availability_lib.php) stays
 * pure; this module loads the real rows for a tenant + meeting type and
 * converts UTC blockouts/soft-holds into organizer-tz wall-clock blocks for a
 * given date. Shared by api/slots.php, Phase 5's submission, and Phase 6's
 * admin grid.
 */

if (!function_exists('availability_ctx_load')) {

    /**
     * Load the availability context for one tenant + meeting type.
     * Returns null if the tenant/type is missing or inactive.
     *
     * @return array|null [
     *   'meeting_type' => row,
     *   'working_hours' => rows,
     *   'overrides' => rows,
     *   'blockout_rows' => raw UTC rows (convert per date via availability_ctx_blocks_by_date),
     *   'soft_hold_rows' => raw UTC rows,
     *   'counts' => ['type_day','global_day','global_week'],
     *   'caps' => ['type_daily_cap','global_daily_cap','global_weekly_cap'],
     *   'org_tz' => DateTimeZone,
     *   'org_tz_name' => string,
     *   'stale' => bool (any active sync source is stale -> fail closed),
     * ]
     */
    function availability_ctx_load(PDO $pdo, int $tenantId, string $typeSlug, DateTimeImmutable $now): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT mt.*, g.organizer_timezone, g.global_daily_cap, g.global_weekly_cap
               FROM meeting_types mt
               JOIN global_settings g ON g.tenant_id = mt.tenant_id
              WHERE mt.tenant_id = :tid AND mt.slug = :slug AND mt.active = 1
              LIMIT 1'
        );
        $stmt->execute(['tid' => $tenantId, 'slug' => $typeSlug]);
        $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($type === false) {
            return null;
        }

        $orgTzName = (string) ($type['organizer_timezone'] ?: 'Europe/London');
        $orgTz = new DateTimeZone($orgTzName);

        $whStmt = $pdo->prepare('SELECT day_of_week, start_time, end_time FROM working_hours WHERE tenant_id = ?');
        $whStmt->execute([$tenantId]);
        $workingHours = $whStmt->fetchAll(PDO::FETCH_ASSOC);

        $ovStmt = $pdo->prepare('SELECT date, is_blocked, start_time, end_time, note FROM availability_overrides WHERE tenant_id = ?');
        $ovStmt->execute([$tenantId]);
        $overrides = $ovStmt->fetchAll(PDO::FETCH_ASSOC);

        $blStmt = $pdo->prepare(
            "SELECT b.start_utc, b.end_utc, b.external_uid
               FROM calendar_blockouts b
               JOIN calendar_sources s ON s.id = b.calendar_source_id
              WHERE s.tenant_id = ? AND s.active = 1
                AND b.start_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)"
        );
        $blStmt->execute([$tenantId]);
        $blockoutRows = $blStmt->fetchAll(PDO::FETCH_ASSOC);

        $shStmt = $pdo->prepare(
            "SELECT requested_start_utc, requested_end_utc
               FROM request_log
              WHERE tenant_id = ? AND status = 'pending' AND soft_hold_expires_at > UTC_TIMESTAMP()"
        );
        $shStmt->execute([$tenantId]);
        $softHoldRows = $shStmt->fetchAll(PDO::FETCH_ASSOC);

        $date = $now->format('Y-m-d');
        $counts = [
            'type_day' => availability_ctx_request_count($pdo, $tenantId, $type['id'], $date, $date),
            'global_day' => availability_ctx_request_count($pdo, $tenantId, null, $date, $date),
            'global_week' => availability_ctx_request_count($pdo, $tenantId, null, $date, $now->modify('+6 days')->format('Y-m-d')),
        ];

        $caps = [
            'type_daily_cap' => $type['daily_cap'] !== null ? (int) $type['daily_cap'] : null,
            'global_daily_cap' => $type['global_daily_cap'] !== null ? (int) $type['global_daily_cap'] : null,
            'global_weekly_cap' => $type['global_weekly_cap'] !== null ? (int) $type['global_weekly_cap'] : null,
        ];

        return [
            'meeting_type' => $type,
            'working_hours' => $workingHours,
            'overrides' => $overrides,
            'blockout_rows' => $blockoutRows,
            'soft_hold_rows' => $softHoldRows,
            'counts' => $counts,
            'caps' => $caps,
            'org_tz' => $orgTz,
            'org_tz_name' => $orgTzName,
            'stale' => availability_ctx_sources_stale($pdo, $tenantId),
        ];
    }

    /**
     * Count pending/fulfilled requests for a tenant, optionally one meeting type,
     * within a UTC date span.
     */
    function availability_ctx_request_count(PDO $pdo, int $tenantId, ?int $typeId, string $fromDate, string $toDate): int
    {
        $sql = "SELECT COUNT(*) FROM request_log
                 WHERE tenant_id = :tid AND status IN ('pending','fulfilled')
                   AND requested_start_utc >= :from AND requested_start_utc < :to";
        $params = ['tid' => $tenantId, 'from' => $fromDate . ' 00:00:00', 'to' => $toDate . ' 23:59:59'];
        if ($typeId !== null) {
            $sql .= ' AND meeting_type_id = :mtid';
            $params['mtid'] = $typeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fail-closed (spec 3.5): true if any ACTIVE source has never synced or its
     * last successful sync is stale. The caller must then treat availability as
     * closed rather than risk double-booking.
     */
    function availability_ctx_sources_stale(PDO $pdo, int $tenantId, int $maxStaleHours = 24): bool
    {
        $rows = $pdo->prepare(
            'SELECT last_synced_at FROM calendar_sources WHERE tenant_id = ? AND active = 1'
        );
        $rows->execute([$tenantId]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $source) {
            if (calendar_source_is_stale($source, $maxStaleHours)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert raw UTC blockout/soft-hold rows into organizer-tz wall-clock
     * blocks for one date, e.g. [['start'=>'10:00','end'=>'11:00'], ...].
     */
    function availability_ctx_blocks_by_date(array $rows, DateTimeZone $orgTz, string $date): array
    {
        $out = [];
        foreach ($rows as $r) {
            $start = (new DateTimeImmutable($r['start_utc'], new DateTimeZone('UTC')))->setTimezone($orgTz);
            $end = (new DateTimeImmutable($r['end_utc'], new DateTimeZone('UTC')))->setTimezone($orgTz);
            $sDate = $start->format('Y-m-d');
            $eDate = $end->format('Y-m-d');
            if ($sDate === $date && $eDate === $date) {
                $out[] = ['start' => $start->format('H:i'), 'end' => $end->format('H:i')];
            } elseif ($sDate === $date) {
                $out[] = ['start' => $start->format('H:i'), 'end' => '23:59'];
            } elseif ($eDate === $date) {
                $out[] = ['start' => '00:00', 'end' => $end->format('H:i')];
            }
        }
        return $out;
    }

    /**
     * Build the engine ctx array for one date (spec 2.2). Returns [] if stale.
     */
    function availability_ctx_for_date(array $ctx, string $date): array
    {
        $mt = $ctx['meeting_type'];
        return [
            'date' => $date,
            'working_hours' => $ctx['working_hours'],
            'overrides' => $ctx['overrides'],
            'blockouts' => availability_ctx_blocks_by_date($ctx['blockout_rows'], $ctx['org_tz'], $date),
            'soft_holds' => availability_ctx_blocks_by_date($ctx['soft_hold_rows'], $ctx['org_tz'], $date),
            'meeting_type' => [
                'duration_min' => (int) $mt['duration_min'],
                'buffer_before_min' => (int) $mt['buffer_before_min'],
                'buffer_after_min' => (int) $mt['buffer_after_min'],
                'min_notice_hours' => (int) $mt['min_notice_hours'],
                'max_horizon_days' => (int) $mt['max_horizon_days'],
            ],
            'now' => new DateTimeImmutable('now', $ctx['org_tz']),
            'counts' => $ctx['counts'],
            'caps' => $ctx['caps'],
        ];
    }
}
