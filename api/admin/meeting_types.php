<?php
// api/admin/meeting_types.php — meeting type CRUD (spec 2.6).
require __DIR__ . '/_guard.php';

try {
    $tenantId = admin_guard_tenant();
    $pdo = admin_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        admin_json(['ok' => true, 'meeting_types' => admin_meeting_types_list($pdo, $tenantId)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $data = admin_guard_body();
        admin_guard_csrf($data);
        $id = (int) ($data['id'] ?? 0);
        admin_json(['ok' => admin_meeting_type_delete($pdo, $tenantId, $id)]);
    }

    $data = admin_guard_body();
    admin_guard_csrf($data);
    $result = admin_meeting_type_save($pdo, $tenantId, $data);
    if (!$result['ok']) {
        admin_json(['ok' => false, 'error' => $result['error']], 400);
    }
    admin_json(['ok' => true, 'id' => $result['id']]);
} catch (Throwable $e) {
    admin_json_out($e);
}
