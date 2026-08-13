<?php
// api/admin/dashboard.php — dashboard warnings + counts.
require __DIR__ . '/_guard.php';

try {
    $tenantId = admin_guard_tenant();
    $pdo = admin_db();

    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM request_log WHERE tenant_id = ? GROUP BY status');
    $stmt->execute([$tenantId]);
    $counts = ['pending' => 0, 'fulfilled' => 0, 'cancelled' => 0, 'expired' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[$r['status']] = (int) $r['n'];
    }

    $usage = admin_dashboard_usage($pdo, $tenantId, 30);

    admin_json(['ok' => true, 'warnings' => admin_dashboard_warnings($pdo, $tenantId), 'counts' => $counts, 'usage' => $usage]);
} catch (Throwable $e) {
    admin_json_out($e);
}
