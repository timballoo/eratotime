<?php

/**
 * admin_lib.php — admin panel core (spec 2.6). Single-tenant, single-passphrase.
 *
 * v1 auth is the single shared passphrase from config['admin'] (spec 1.4);
 * the tenant_admins table exists for future real auth but is not used yet.
 * Functions are testable without HTTP where possible.
 */

if (!function_exists('admin_session_start')) {

    // Grid geometry: 07:00 .. 19:30 in 30-minute cells (25 cells).
    define('ADMIN_GRID_START_MIN', 7 * 60);       // 07:00
    define('ADMIN_GRID_END_MIN', 19 * 60 + 30);   // 19:30
    define('ADMIN_GRID_STEP_MIN', 30);

    function admin_grid_cell_count(): int
    {
        return (int) ((ADMIN_GRID_END_MIN - ADMIN_GRID_START_MIN) / ADMIN_GRID_STEP_MIN);
    }

    function admin_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent() === false) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
            @session_start();
        }
    }

    function admin_is_logged_in(): bool
    {
        return !empty($_SESSION['eratotime_admin']);
    }

    function admin_current_tenant_id(): ?int
    {
        return isset($_SESSION['eratotime_tenant_id']) ? (int) $_SESSION['eratotime_tenant_id'] : null;
    }

    /**
     * Attempt login with the shared passphrase (rate-limited 5/5min per IP).
     */
    function admin_attempt_login(array $config, string $username, string $password, string $ip): array
    {
        // Rate-limit FIRST — counts attempts regardless of outcome.
        $dir = (string) ($config['runtime_dir'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eratotime');
        if (!security_rate_limit('admin-login:' . $ip, 5, 300, $dir)) {
            return ['ok' => false, 'error' => 'Too many login attempts — try again later.'];
        }
        $admin = $config['admin'] ?? [];
        $hash = (string) ($admin['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return ['ok' => false, 'error' => 'Incorrect credentials.'];
        }
        $expectedUser = (string) ($admin['username'] ?? 'admin');
        if ($expectedUser !== '' && !hash_equals($expectedUser, $username)) {
            return ['ok' => false, 'error' => 'Incorrect credentials.'];
        }
        admin_session_start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['eratotime_admin'] = true;
        return ['ok' => true];
    }

    function admin_logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Validate a password-reset secret against the tenants table.
     * Returns the tenant_id if valid, null otherwise.
     */
    function admin_validate_reset_secret(PDO $pdo, string $secret): ?int
    {
        if ($secret === '') {
            return null;
        }
        $row = $pdo->prepare("SELECT id FROM tenants WHERE reset_secret = ? AND active = 1 LIMIT 1");
        $row->execute([$secret]);
        $result = $row->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? (int) $result['id'] : null;
    }

    /**
     * Change the admin password in config.php and regenerate the reset secret.
     * $configPath is the absolute path to config.php on disk.
     */
    function admin_reset_password(PDO $pdo, int $tenantId, string $newPassword, string $configPath): bool
    {
        if (!is_file($configPath)) {
            return false;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($newHash === false) {
            return false;
        }

        $configContent = file_get_contents($configPath);
        if ($configContent === false) {
            return false;
        }

        // Replace the password_hash value in config.php.
        $pattern = "/('password_hash'\s*=>\s*')[^']*(')/";
        $updated = preg_replace_callback($pattern, function ($matches) use ($newHash) {
            return $matches[1] . $newHash . $matches[2];
        }, $configContent);

        if ($updated === null || $updated === $configContent) {
            return false;
        }

        if (file_put_contents($configPath, $updated) === false) {
            return false;
        }

        // Regenerate the reset secret so the old URL is invalidated.
        $newSecret = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE tenants SET reset_secret = ? WHERE id = ?")->execute([$newSecret, $tenantId]);

        return true;
    }

    /**
     * Session CSRF token for admin state-changing calls.
     */
    function admin_csrf_token(): string
    {
        admin_session_start();
        if (empty($_SESSION['eratotime_csrf'])) {
            $_SESSION['eratotime_csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['eratotime_csrf'];
    }

    function admin_csrf_check(string $token): bool
    {
        admin_session_start();
        return !empty($_SESSION['eratotime_csrf']) && hash_equals($_SESSION['eratotime_csrf'], $token);
    }

    // --- Grid helpers -------------------------------------------------------

    /**
     * Convert a list of open cell indexes into merged TIME ranges.
     */
    function admin_cell_ranges(array $openIndexes): array
    {
        $indexes = array_values(array_filter(array_map('intval', $openIndexes), fn($i) => $i >= 0 && $i < admin_grid_cell_count()));
        sort($indexes);
        $ranges = [];
        foreach ($indexes as $i) {
            $last = count($ranges) - 1;
            if ($last >= 0 && $ranges[$last][1] === $i) { // contiguous
                $ranges[$last][1] = $i + 1;
            } else {
                $ranges[] = [$i, $i + 1];
            }
        }
        return array_map(fn($r) => [
            'start' => availability_format_time(ADMIN_GRID_START_MIN + $r[0] * ADMIN_GRID_STEP_MIN),
            'end' => availability_format_time(ADMIN_GRID_START_MIN + $r[1] * ADMIN_GRID_STEP_MIN),
        ], $ranges);
    }

    /**
     * Inverse of admin_cell_ranges: which cell indexes an open TIME range covers.
     */
    function admin_range_cells(array $ranges): array
    {
        $cells = [];
        foreach ($ranges as $r) {
            $s = availability_parse_time((string) $r['start']);
            $e = availability_parse_time((string) $r['end']);
            if ($s === null || $e === null || $e <= $s) {
                continue;
            }
            for ($m = $s; $m < $e; $m += ADMIN_GRID_STEP_MIN) {
                if ($m >= ADMIN_GRID_START_MIN && $m < ADMIN_GRID_END_MIN) {
                    $cells[] = (int) (($m - ADMIN_GRID_START_MIN) / ADMIN_GRID_STEP_MIN);
                }
            }
        }
        return array_values(array_unique($cells));
    }

    function admin_cell_minutes(int $index): array
    {
        $s = ADMIN_GRID_START_MIN + $index * ADMIN_GRID_STEP_MIN;
        return [$s, $s + ADMIN_GRID_STEP_MIN];
    }

    /**
     * Load the merged grid for a week (Monday-based). Returns per-day data with
     * precomputed cell states: open / blocked(manual) / busy(readonly) / hold(readonly).
     */
    function admin_grid_load(PDO $pdo, int $tenantId, string $weekDate): array
    {
        $monday = (new DateTimeImmutable($weekDate, new DateTimeZone('UTC')))->modify('monday this week');
        $orgTzName = 'Europe/London';
        $g = $pdo->prepare('SELECT organizer_timezone FROM global_settings WHERE tenant_id = ?');
        $g->execute([$tenantId]);
        if ($row = $g->fetch(PDO::FETCH_ASSOC)) {
            $orgTzName = (string) ($row['organizer_timezone'] ?? 'Europe/London');
        }
        $orgTz = new DateTimeZone($orgTzName);

        $wh = $pdo->prepare('SELECT day_of_week, start_time, end_time FROM working_hours WHERE tenant_id = ?');
        $wh->execute([$tenantId]);
        $workingHours = $wh->fetchAll(PDO::FETCH_ASSOC);

        $ov = $pdo->prepare('SELECT date, is_blocked, start_time, end_time FROM availability_overrides WHERE tenant_id = ? AND date >= ? AND date <= ?');
        $weekStart = $monday->format('Y-m-d');
        $weekEnd = $monday->modify('+6 days')->format('Y-m-d');
        $ov->execute([$tenantId, $weekStart, $weekEnd]);
        $overrides = $ov->fetchAll(PDO::FETCH_ASSOC);

        $ctx = availability_ctx_load($pdo, $tenantId, (string) $pdo->query("SELECT slug FROM meeting_types WHERE tenant_id = {$tenantId} AND active = 1 ORDER BY id LIMIT 1")->fetchColumn(), new DateTimeImmutable('now', $orgTz));

        $days = [];
        for ($d = 0; $d < 7; $d++) {
            $date = $monday->modify("+{$d} days")->format('Y-m-d');
            $dow = (int) $monday->modify("+{$d} days")->format('w');
            $template = availability_day_open_ranges($dow, $workingHours, $date, []);
            $dateOverrides = array_values(array_filter($overrides, fn($o) => $o['date'] === $date));
            $override = null;
            if ($dateOverrides !== []) {
                $blocked = (bool) array_filter($dateOverrides, fn($o) => !empty($o['is_blocked']));
                $openRanges = array_map(
                    fn($o) => ['start' => $o['start_time'], 'end' => $o['end_time']],
                    array_values(array_filter($dateOverrides, fn($o) => !empty($o['start_time'])))
                );
                $override = $blocked ? 'blocked' : admin_range_cells($openRanges);
            }

            $busyCells = [];
            $holdCells = [];
            if ($ctx !== null) {
                $busyCells = admin_range_cells(availability_ctx_blocks_by_date($ctx['blockout_rows'], $orgTz, $date));
                $holdCells = admin_range_cells(availability_ctx_blocks_by_date($ctx['soft_hold_rows'], $orgTz, $date));
            }

            $cells = [];
            $templateCells = $template ? admin_range_cells($template) : [];
            for ($i = 0; $i < admin_grid_cell_count(); $i++) {
                if (in_array($i, $busyCells, true)) {
                    $cells[$i] = 'busy';
                } elseif (in_array($i, $holdCells, true)) {
                    $cells[$i] = 'hold';
                } elseif ($override === 'blocked') {
                    $cells[$i] = 'blocked';
                } elseif (is_array($override) && !in_array($i, $override, true)) {
                    $cells[$i] = 'blocked';
                } elseif (is_array($override) && in_array($i, $override, true)) {
                    $cells[$i] = 'open';
                } elseif (!in_array($i, $templateCells, true)) {
                    $cells[$i] = 'blocked';
                } else {
                    $cells[$i] = 'open';
                }
            }
            $days[] = ['date' => $date, 'day_of_week' => $dow, 'has_override' => $override !== null, 'cells' => $cells];
        }

        return [
            'week' => $weekStart,
            'cell_start' => availability_format_time(ADMIN_GRID_START_MIN),
            'cell_step' => ADMIN_GRID_STEP_MIN,
            'cell_count' => admin_grid_cell_count(),
            'days' => $days,
        ];
    }

    /**
     * Save grid toggles. mode='template' rewrites working_hours per day_of_week;
     * mode='override' rewrites availability_overrides per date. days is keyed
     * by day index (0=Mon..6=Sun) or date ('Y-m-d') with value = array of open
     * cell indexes, or 'blocked' (full-day block for overrides).
     */
    function admin_grid_save(PDO $pdo, int $tenantId, string $weekDate, string $mode, array $days): void
    {
        $monday = (new DateTimeImmutable($weekDate, new DateTimeZone('UTC')))->modify('monday this week');

        if ($mode === 'template') {
            // day key 0=Mon..6=Sun; working_hours.day_of_week is 0=Sun..6=Sat.
            foreach ($days as $key => $value) {
                $dow = ((int) $key + 1) % 7;
                $open = is_array($value) ? $value : [];
                $pdo->prepare('DELETE FROM working_hours WHERE tenant_id = ? AND day_of_week = ?')->execute([$tenantId, $dow]);
                foreach (admin_cell_ranges($open) as $range) {
                    $pdo->prepare('INSERT INTO working_hours (tenant_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)')
                        ->execute([$tenantId, $dow, $range['start'] . ':00', $range['end'] . ':00']);
                }
            }
            return;
        }

        foreach ($days as $date => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                continue;
            }
            $pdo->prepare('DELETE FROM availability_overrides WHERE tenant_id = ? AND date = ?')->execute([$tenantId, $date]);
            if ($value === 'blocked') {
                $pdo->prepare('INSERT INTO availability_overrides (tenant_id, date, is_blocked, start_time, end_time) VALUES (?, ?, 1, NULL, NULL)')
                    ->execute([$tenantId, $date]);
            } elseif (is_array($value) && $value !== []) {
                foreach (admin_cell_ranges($value) as $range) {
                    $pdo->prepare('INSERT INTO availability_overrides (tenant_id, date, is_blocked, start_time, end_time) VALUES (?, ?, 0, ?, ?)')
                        ->execute([$tenantId, $date, $range['start'] . ':00', $range['end'] . ':00']);
                }
            }
            // empty array -> no override rows -> day falls back to template
        }
    }

    // --- Requests -----------------------------------------------------------

    function admin_requests_list(PDO $pdo, int $tenantId, string $status = ''): array
    {
        $sql = "SELECT r.*, mt.name AS type_name, mt.duration_min
                  FROM request_log r JOIN meeting_types mt ON mt.id = r.meeting_type_id
                 WHERE r.tenant_id = :tid";
        $params = ['tid' => $tenantId];
        if ($status !== '' && in_array($status, ['pending', 'fulfilled', 'cancelled', 'expired'], true)) {
            $sql .= ' AND r.status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY r.requested_start_utc DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['guest_emails'] = json_decode((string) $r['guest_emails'], true);
            $r['custom_answers'] = json_decode((string) $r['custom_answers'], true);
        }
        return $rows;
    }

    function admin_request_set_status(PDO $pdo, int $requestId, string $status): bool
    {
        if (!in_array($status, ['fulfilled', 'cancelled'], true)) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT tenant_id FROM request_log WHERE id = ?');
        $stmt->execute([$requestId]);
        $tenantId = $stmt->fetchColumn();
        if ($tenantId === false) {
            return false;
        }
        $pdo->prepare("UPDATE request_log SET status = ? WHERE id = ?")->execute([$status, $requestId]);
        $pdo->prepare("INSERT INTO activity_log (tenant_id, event_type, detail) VALUES (?, ?, ?)")
            ->execute([(int) $tenantId, 'request_' . $status, json_encode(['request_id' => $requestId])]);
        return true;
    }

    // --- Meeting types ------------------------------------------------------

    function admin_meeting_types_list(PDO $pdo, int $tenantId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM meeting_types WHERE tenant_id = ? ORDER BY sort_order, id');
        $stmt->execute([$tenantId]);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($types as &$t) {
            $q = $pdo->prepare('SELECT id, label, type, required, sort_order FROM meeting_type_questions WHERE meeting_type_id = ? ORDER BY sort_order');
            $q->execute([$t['id']]);
            $t['questions'] = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        return $types;
    }

    function admin_meeting_type_save(PDO $pdo, int $tenantId, array $data): array
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $duration = (int) ($data['duration_min'] ?? 30);
        if (!preg_match('/^[a-z0-9\-]{1,64}$/', $slug) || $name === '' || $duration < 5) {
            return ['ok' => false, 'error' => 'Invalid name, slug or duration.'];
        }
        $existing = $pdo->prepare('SELECT id FROM meeting_types WHERE tenant_id = ? AND slug = ? AND id != ?');
        $existing->execute([$tenantId, $slug, $id]);
        if ($existing->fetchColumn() !== false) {
            return ['ok' => false, 'error' => 'That slug is already in use.'];
        }

        $fields = [
            'slug' => $slug, 'name' => $name, 'description' => trim((string) ($data['description'] ?? '')),
            'duration_min' => $duration,             'location_details' => trim((string) ($data['location_details'] ?? '')),
            'video_link' => trim((string) ($data['video_link'] ?? '')),
            'message_template' => trim((string) ($data['message_template'] ?? '')),
            'buffer_before_min' => max(0, (int) ($data['buffer_before_min'] ?? 0)),
            'buffer_after_min' => max(0, (int) ($data['buffer_after_min'] ?? 0)),
            'min_notice_hours' => max(0, (int) ($data['min_notice_hours'] ?? 24)),
            'max_horizon_days' => max(1, (int) ($data['max_horizon_days'] ?? 14)),
            'daily_cap' => ($data['daily_cap'] ?? '') === '' ? null : max(1, (int) $data['daily_cap']),
            'active' => empty($data['active']) ? 0 : 1,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            $sets = implode(', ', array_map(fn($c) => "`{$c}` = :{$c}", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE meeting_types SET {$sets} WHERE id = :id AND tenant_id = :tid");
            $stmt->execute($fields + ['id' => $id, 'tid' => $tenantId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO meeting_types (' . implode(', ', array_map(fn($c) => "`{$c}`", array_keys($fields))) . ', tenant_id) VALUES (' .
                implode(', ', array_map(fn($c) => ":{$c}", array_keys($fields))) . ', :tid)'
            );
            $stmt->execute($fields + ['tid' => $tenantId]);
            $id = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM meeting_type_questions WHERE meeting_type_id = ?')->execute([$id]);
        $questions = is_array($data['questions'] ?? null) ? $data['questions'] : [];
        $insQ = $pdo->prepare('INSERT INTO meeting_type_questions (meeting_type_id, label, type, required, sort_order) VALUES (?, ?, ?, ?, ?)');
        foreach ($questions as $i => $q) {
            $label = trim((string) ($q['label'] ?? ''));
            $type = in_array($q['type'] ?? '', ['text', 'textarea', 'select'], true) ? $q['type'] : 'text';
            if ($label !== '') {
                $insQ->execute([$id, $label, $type, empty($q['required']) ? 0 : 1, (int) ($q['sort_order'] ?? $i)]);
            }
        }
        return ['ok' => true, 'id' => $id];
    }

    function admin_meeting_type_delete(PDO $pdo, int $tenantId, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM meeting_types WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    // --- Settings / sources ------------------------------------------------

    function admin_settings_load(PDO $pdo, int $tenantId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM global_settings WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    function admin_settings_save(PDO $pdo, int $tenantId, array $data): void
    {
        $pdo->prepare(
            "UPDATE global_settings SET organizer_bio = ?, organizer_photo_path = ?, global_daily_cap = ?,
                global_weekly_cap = ?, whatsapp_enabled = ?, whatsapp_destination_number = ?,
                organizer_timezone = ?, request_hold_hours = ?, request_log_retention_days = ?,
                meet_link = ?, dynamic_meet_links = ?, delete_meet_events = ?
             WHERE tenant_id = ?"
        )->execute([
            trim((string) ($data['organizer_bio'] ?? '')),
            ($data['organizer_photo_path'] ?? '') === '' ? null : $data['organizer_photo_path'],
            ($data['global_daily_cap'] ?? '') === '' ? null : max(1, (int) $data['global_daily_cap']),
            ($data['global_weekly_cap'] ?? '') === '' ? null : max(1, (int) $data['global_weekly_cap']),
            empty($data['whatsapp_enabled']) ? 0 : 1,
            trim((string) ($data['whatsapp_destination_number'] ?? '')),
            (string) ($data['organizer_timezone'] ?? 'Europe/London'),
            max(1, (int) ($data['request_hold_hours'] ?? 24)),
            max(7, (int) ($data['request_log_retention_days'] ?? 30)),
            trim((string) ($data['meet_link'] ?? '')) === '' ? null : trim((string) $data['meet_link']),
            empty($data['dynamic_meet_links']) ? 0 : 1,
            empty($data['delete_meet_events']) ? 0 : 1,
            $tenantId,
        ]);
    }

    function admin_sources_list(PDO $pdo, int $tenantId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM calendar_sources WHERE tenant_id = ? ORDER BY active DESC, id');
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Trigger an immediate sync for the tenant's active source(s).
     */
    function admin_sources_sync_now(PDO $pdo, array $config, int $tenantId, ?int $sourceId = null): array
    {
        $sql = 'SELECT s.*, g.organizer_timezone FROM calendar_sources s JOIN global_settings g ON g.tenant_id = s.tenant_id WHERE s.tenant_id = ? AND s.active = 1';
        $params = [$tenantId];
        if ($sourceId !== null) {
            $sql .= ' AND s.id = ?';
            $params[] = $sourceId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $source) {
            $results[] = calendar_sync_source($pdo, $source, $config);
        }
        return $results;
    }

    function admin_dashboard_warnings(PDO $pdo, int $tenantId): array
    {
        $warnings = [];
        $failed = $pdo->prepare("SELECT COUNT(*) FROM notification_outbox WHERE tenant_id = ? AND status = 'failed'");
        $failed->execute([$tenantId]);
        if ((int) $failed->fetchColumn() > 0) {
            $warnings[] = 'Some notification emails have failed delivery and need attention.';
        }
        $stale = $pdo->prepare(
            "SELECT COUNT(*) FROM calendar_sources WHERE tenant_id = ? AND active = 1
               AND (last_synced_at IS NULL OR last_synced_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR))"
        );
        $stale->execute([$tenantId]);
        if ((int) $stale->fetchColumn() > 0) {
            $warnings[] = 'A connected calendar has not synced for 24h — availability is failing closed.';
        }
        return $warnings;
    }

    /**
     * Usage tracking for the dashboard (last $days).
     */
    function admin_dashboard_usage(PDO $pdo, int $tenantId, int $days = 30): array
    {
        $daily = [];
        $stmt = $pdo->prepare(
            "SELECT DATE(sent_at) AS d, COUNT(*) AS n FROM request_log
              WHERE tenant_id = ? AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
              GROUP BY DATE(sent_at) ORDER BY d"
        );
        $stmt->execute([$tenantId, $days]);
        $byDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byDate[$r['d']] = (int) $r['n'];
        }
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $daily[] = ['date' => $d, 'count' => $byDate[$d] ?? 0];
        }

        $byType = $pdo->prepare(
            "SELECT mt.name AS type_name, COUNT(*) AS n
               FROM request_log r JOIN meeting_types mt ON mt.id = r.meeting_type_id
              WHERE r.tenant_id = ? GROUP BY mt.id, mt.name ORDER BY n DESC"
        );
        $byType->execute([$tenantId]);

        $byStatus = ['pending' => 0, 'fulfilled' => 0, 'cancelled' => 0, 'expired' => 0];
        $stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM request_log WHERE tenant_id = ? GROUP BY status');
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($byStatus[$r['status']])) {
                $byStatus[$r['status']] = (int) $r['n'];
            }
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM request_log WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM request_log WHERE tenant_id = ? AND requested_start_utc >= UTC_TIMESTAMP() AND status = 'pending'");
        $stmt->execute([$tenantId]);
        $upcoming = (int) $stmt->fetchColumn();

        return [
            'total' => $total,
            'upcoming' => $upcoming,
            'by_status' => $byStatus,
            'by_type' => $byType->fetchAll(PDO::FETCH_ASSOC),
            'daily' => $daily,
        ];
    }

    // --- Cron jobs (dispatcher, cookingtogetherness pattern) ----------------

    function admin_cron_list(PDO $pdo): array
    {
        return cron_get_jobs($pdo);
    }

    function admin_cron_toggle(PDO $pdo, string $key): bool
    {
        return cron_toggle_job($pdo, $key);
    }

    function admin_cron_update(PDO $pdo, string $key, int $scheduleMin): bool
    {
        return cron_update_schedule($pdo, $key, $scheduleMin);
    }

    function admin_cron_run(PDO $pdo, array $config, string $key): array
    {
        return cron_run_job($pdo, $config, $key);
    }
}
