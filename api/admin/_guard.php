<?php

/**
 * api/admin/_guard.php — shared bootstrap + auth for admin endpoints.
 * Includes: autoload, config, admin_lib + security_lib, session, auth check,
 * JSON body parsing, and JSON output helpers. Each admin endpoint requires it.
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../security_lib.php';
require __DIR__ . '/../../admin_lib.php';
require __DIR__ . '/../../availability_context_lib.php';

$GLOBALS['admin_config'] = require __DIR__ . '/../../config.php';

admin_session_start();

function admin_json($payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function admin_json_out(Throwable $e, int $status = 500): never
{
    admin_json(['ok' => false, 'error' => $e->getMessage()], $status);
}

function admin_guard_tenant(): int
{
    if (!admin_is_logged_in()) {
        admin_json(['ok' => false, 'error' => 'Not authenticated'], 401);
    }
    $tenantId = admin_current_tenant_id();
    if ($tenantId === null) {
        admin_json(['ok' => false, 'error' => 'No tenant context'], 401);
    }
    return $tenantId;
}

function admin_guard_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($data)) {
        admin_json(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }
    return $data;
}

function admin_guard_csrf(array $data): void
{
    if (!admin_csrf_check((string) ($data['csrf'] ?? ''))) {
        admin_json(['ok' => false, 'error' => 'Session expired — reload the page'], 403);
    }
}

function admin_db(): PDO
{
    $config = $GLOBALS['admin_config'];
    if (!isset($config['db']['name']) || $config['db']['name'] === '') {
        admin_json(['ok' => false, 'error' => 'DB not configured'], 500);
    }
    return new PDO(sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        (int) ($config['db']['port'] ?? 3306),
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    ), $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
