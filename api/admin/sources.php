<?php
// api/admin/sources.php — calendar sources list + sync-now.
require __DIR__ . '/_guard.php';

try {
    $tenantId = admin_guard_tenant();
    $pdo = admin_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        admin_json(['ok' => true, 'sources' => admin_sources_list($pdo, $tenantId)]);
    }

    $data = admin_guard_body();
    admin_guard_csrf($data);
    $action = (string) ($data['action'] ?? '');
    if ($action !== 'sync_now') {
        admin_json(['ok' => false, 'error' => 'Invalid action'], 400);
    }
    $results = admin_sources_sync_now($pdo, $GLOBALS['admin_config'], $tenantId, isset($data['source_id']) ? (int) $data['source_id'] : null);
    admin_json(['ok' => true, 'results' => $results]);
} catch (Throwable $e) {
    admin_json_out($e);
}
