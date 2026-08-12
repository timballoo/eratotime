<?php
// api/admin/requests.php — request log GET + mark fulfilled/cancelled POST.
require __DIR__ . '/_guard.php';

try {
    $tenantId = admin_guard_tenant();
    $pdo = admin_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $status = (string) ($_GET['status'] ?? '');
        admin_json(['ok' => true, 'requests' => admin_requests_list($pdo, $tenantId, $status)]);
    }

    $data = admin_guard_body();
    admin_guard_csrf($data);
    $id = (int) ($data['id'] ?? 0);
    $action = (string) ($data['action'] ?? '');
    $status = $action === 'mark_fulfilled' ? 'fulfilled' : ($action === 'mark_cancelled' ? 'cancelled' : '');
    if ($id <= 0 || $status === '') {
        admin_json(['ok' => false, 'error' => 'Invalid action'], 400);
    }
    if (!admin_request_set_status($pdo, $id, $status)) {
        admin_json(['ok' => false, 'error' => 'Request not found'], 404);
    }
    admin_json(['ok' => true]);
} catch (Throwable $e) {
    admin_json_out($e);
}
