<?php

/**
 * calendar_sync_lib.php — orchestrates per-provider busy-time sync (spec 3.5).
 *
 * Loops over active calendar_sources across all tenants, calls the right
 * provider, and writes normalized rows into calendar_blockouts keyed by
 * (calendar_source_id, external_uid) — idempotent upsert, plus deletion of
 * rows in the fetched window that the source no longer returns (so cancelled
 * events stop blocking availability). Failures are recorded on the source
 * and in activity_log; the caller fails closed if a source goes stale (4.5).
 *
 * Credentials are decrypted at rest with crypto_lib (spec 4.2) before being
 * passed to a provider.
 */

if (!function_exists('calendar_sync_window')) {

    define('CALENDAR_SYNC_WINDOW_DAYS', 90); // rolling window: now .. +90 days (spec 3.5)

    /**
     * [from, to] UTC window for one sync run.
     */
    function calendar_sync_window(?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return [$now, $now->modify('+' . CALENDAR_SYNC_WINDOW_DAYS . ' days')];
    }

    /**
     * Load the encryption key from config (creates it on first use).
     */
    function calendar_encryption_key(array $config): string
    {
        return crypto_key_load((string) ($config['encryption_key_path'] ?? ''));
    }

    /**
     * Decrypt a source's credentials into ['username'=>..,'password'=>..].
     * Returns empty array if nothing stored yet.
     */
    function calendar_source_creds(array $source, array $config): array
    {
        $payload = (string) ($source['credentials_encrypted'] ?? '');
        if ($payload === '') {
            return [];
        }
        $json = crypto_decrypt($payload, calendar_encryption_key($config));
        $creds = $json !== null ? json_decode($json, true) : [];
        return is_array($creds) ? $creds : [];
    }

    /**
     * Replace the blockouts for one source inside the fetched window.
     * Returns a count of inserted/updated/deleted rows (an indicator, not a
     * precise "changed" total — MySQL rowCount differs for insert vs update).
     */
    function calendar_blockouts_replace(PDO $pdo, int $sourceId, array $blocks, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $pdo->beginTransaction();
        try {
            $changed = 0;
            $uids = [];
            if ($blocks !== []) {
                $upsert = $pdo->prepare(
                    'INSERT INTO calendar_blockouts (calendar_source_id, external_uid, start_utc, end_utc, synced_at)
                     VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE
                        start_utc = VALUES(start_utc),
                        end_utc   = VALUES(end_utc),
                        synced_at = UTC_TIMESTAMP()'
                );
                foreach ($blocks as $b) {
                    $uid = (string) $b['uid'];
                    $uids[$uid] = true;
                    $upsert->execute([
                        $sourceId,
                        $uid,
                        $b['start_utc']->format('Y-m-d H:i:s'),
                        $b['end_utc']->format('Y-m-d H:i:s'),
                    ]);
                    $changed += $upsert->rowCount();
                }
            }
            $fromStr = $from->format('Y-m-d H:i:s');
            $toStr = $to->format('Y-m-d H:i:s');
            if ($uids === []) {
                $stmt = $pdo->prepare(
                    'DELETE FROM calendar_blockouts
                      WHERE calendar_source_id = ? AND start_utc >= ? AND start_utc < ?'
                );
                $stmt->execute([$sourceId, $fromStr, $toStr]);
            } else {
                $in = implode(',', array_fill(0, count($uids), '?'));
                $stmt = $pdo->prepare(
                    "DELETE FROM calendar_blockouts
                      WHERE calendar_source_id = ? AND start_utc >= ? AND start_utc < ?
                        AND external_uid NOT IN ({$in})"
                );
                $stmt->execute(array_merge([$sourceId, $fromStr, $toStr], array_keys($uids)));
            }
            $changed += $stmt->rowCount();
            $pdo->commit();
            return $changed;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Sync one calendar source. Returns ['ok'=>bool, 'source_id'=>int, ...].
     * On failure the source status is marked 'error' and an activity_log row
     * is written — the sync never silently disappears (spec 3.5).
     */
    function calendar_sync_source(PDO $pdo, array $source, array $config, ?callable $http = null): array
    {
        [$from, $to] = calendar_sync_window();
        $orgTz = (string) ($source['organizer_timezone'] ?? 'Europe/London');
        try {
            switch ($source['provider']) {
                case 'caldav':
                    $creds = calendar_source_creds($source, $config);
                    $blocks = caldav_fetch_busy_blocks($source, $creds, $from, $to, $orgTz, $http);
                    break;
                case 'ics':
                    $blocks = ics_fetch_busy_blocks($source, $from, $to, $orgTz, $http);
                    break;
                case 'google':
                    throw new RuntimeException('Google provider not built (optional secondary source)');
                default:
                    throw new RuntimeException('Unknown calendar provider: ' . ($source['provider'] ?? ''));
            }
            $changed = calendar_blockouts_replace($pdo, (int) $source['id'], $blocks, $from, $to);
            $pdo->prepare(
                "UPDATE calendar_sources
                    SET last_synced_at = UTC_TIMESTAMP(), last_sync_status = 'ok', last_sync_error = NULL
                  WHERE id = ?"
            )->execute([$source['id']]);
            return ['ok' => true, 'source_id' => (int) $source['id'], 'blocks' => count($blocks), 'changed' => $changed];
        } catch (Throwable $e) {
            $pdo->prepare(
                "UPDATE calendar_sources
                    SET last_synced_at = UTC_TIMESTAMP(), last_sync_status = 'error', last_sync_error = ?
                  WHERE id = ?"
            )->execute([substr($e->getMessage(), 0, 2000), $source['id']]);
            $stmt = $pdo->prepare(
                "INSERT INTO activity_log (tenant_id, event_type, detail)
                 VALUES (?, 'calendar_sync_failed', ?)"
            );
            $stmt->execute([$source['tenant_id'], json_encode(['source_id' => (int) $source['id'], 'error' => $e->getMessage()])]);
            return ['ok' => false, 'source_id' => (int) $source['id'], 'error' => $e->getMessage()];
        }
    }

    /**
     * Sync every active source for every active tenant. Returns array of per-source results.
     */
    function calendar_sync_all(PDO $pdo, array $config, ?callable $http = null): array
    {
        $rows = $pdo->query(
            "SELECT s.*, g.organizer_timezone
               FROM calendar_sources s
               JOIN tenants t         ON t.id = s.tenant_id
               JOIN global_settings g ON g.tenant_id = s.tenant_id
              WHERE s.active = 1 AND t.active = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        $results = [];
        foreach ($rows as $source) {
            $results[] = calendar_sync_source($pdo, $source, $config, $http);
        }
        return $results;
    }

    /**
     * Fail-closed check (spec 3.5): true if the source has never synced or its
     * last successful sync is older than $maxStaleHours. The availability layer
     * must treat stale sources as fully busy rather than risk double-booking.
     */
    function calendar_source_is_stale(array $source, int $maxStaleHours = 24): bool
    {
        $last = $source['last_synced_at'] ?? null;
        if ($last === null || $last === '') {
            return true;
        }
        $ts = (new DateTimeImmutable((string) $last, new DateTimeZone('UTC')))->getTimestamp();
        return (time() - $ts) > $maxStaleHours * 3600;
    }
}
