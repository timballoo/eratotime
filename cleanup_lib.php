<?php

/**
 * cleanup_lib.php — retention + soft-hold housekeeping (spec 4.5, Phase 7).
 *
 *  - Expire pending requests whose soft-hold window has passed (status
 *    pending -> expired) so the admin log stays truthful (the availability
 *    engine already ignores expired holds).
 *  - Purge request_log rows older than request_log_retention_days for
 *    terminal statuses (fulfilled/cancelled/expired). Active pending holds are
 *    never purged. notification_outbox rows cascade-delete with the request.
 */

if (!function_exists('cleanup_expire_soft_holds')) {

    function cleanup_expire_soft_holds(PDO $pdo, int $tenantId): int
    {
        $stmt = $pdo->prepare(
            "UPDATE request_log SET status = 'expired'
              WHERE tenant_id = ? AND status = 'pending' AND soft_hold_expires_at <= UTC_TIMESTAMP()"
        );
        $stmt->execute([$tenantId]);
        return $stmt->rowCount();
    }

    function cleanup_purge_old_requests(PDO $pdo, int $tenantId, int $retentionDays = 30): int
    {
        $retention = max(7, $retentionDays);
        $stmt = $pdo->prepare(
            "DELETE FROM request_log
              WHERE tenant_id = ? AND sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
                AND status IN ('fulfilled', 'cancelled', 'expired')"
        );
        $stmt->execute([$tenantId, $retention]);
        return $stmt->rowCount();
    }

    /**
     * Run cleanup for one tenant. Returns ['expired'=>int,'purged'=>int].
     */
    function cleanup_run(PDO $pdo, int $tenantId, array $settings): array
    {
        $retention = max(7, (int) ($settings['request_log_retention_days'] ?? 30));
        $expired = cleanup_expire_soft_holds($pdo, $tenantId);
        $purged = cleanup_purge_old_requests($pdo, $tenantId, $retention);
        if ($expired > 0 || $purged > 0) {
            $pdo->prepare("INSERT INTO activity_log (tenant_id, event_type, detail) VALUES (?, 'cleanup', ?)")
                ->execute([$tenantId, json_encode(['expired_soft_holds' => $expired, 'purged_requests' => $purged])]);
        }
        return ['expired' => $expired, 'purged' => $purged];
    }
}
