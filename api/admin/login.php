<?php
// api/admin/login.php — POST login / DELETE logout.
require __DIR__ . '/_guard.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        admin_logout();
        admin_json(['ok' => true]);
    }

    $data = admin_guard_body();
    $result = admin_attempt_login(
        $GLOBALS['admin_config'],
        (string) ($data['username'] ?? ''),
        (string) ($data['password'] ?? ''),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    );
    if (!$result['ok']) {
        admin_json(['ok' => false, 'error' => $result['error']], 401);
    }
    // Bind the session to the single seeded tenant.
    $pdo = admin_db();
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'meertec' AND active = 1 LIMIT 1")->fetchColumn();
    $_SESSION['eratotime_tenant_id'] = $tenantId;
    admin_json(['ok' => true, 'csrf' => admin_csrf_token()]);
} catch (Throwable $e) {
    admin_json_out($e);
}
