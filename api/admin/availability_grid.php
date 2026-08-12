<?php
// api/admin/availability_grid.php — weekly grid GET/POST (spec 2.6).
require __DIR__ . '/_guard.php';

try {
    $tenantId = admin_guard_tenant();
    $pdo = admin_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $week = (string) ($_GET['week'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
            admin_json(['ok' => false, 'error' => 'week must be yyyy-mm-dd'], 400);
        }
        admin_json(['ok' => true, 'grid' => admin_grid_load($pdo, $tenantId, $week)]);
    }

    $data = admin_guard_body();
    admin_guard_csrf($data);
    $week = (string) ($data['week'] ?? '');
    $mode = (string) ($data['mode'] ?? '');
    $days = is_array($data['days'] ?? null) ? $data['days'] : [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) || !in_array($mode, ['template', 'override'], true)) {
        admin_json(['ok' => false, 'error' => 'Invalid week or mode'], 400);
    }
    admin_grid_save($pdo, $tenantId, $week, $mode, $days);
    $pdo->prepare("INSERT INTO activity_log (tenant_id, event_type, detail) VALUES (?, 'availability_grid_saved', ?)")
        ->execute([$tenantId, json_encode(['week' => $week, 'mode' => $mode])]);
    admin_json(['ok' => true]);
} catch (Throwable $e) {
    admin_json_out($e);
}
