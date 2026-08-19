<?php
// api/admin/reset.php — password reset via secret token (POST).
// No auth required — the reset secret IS the auth.

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../security_lib.php';
require __DIR__ . '/../../admin_lib.php';

$config = require __DIR__ . '/../../config.php';

admin_session_start();

function reset_json($payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        reset_json(['ok' => false, 'error' => 'POST required'], 405);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($data)) {
        reset_json(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }

    $secret = (string) ($data['secret'] ?? '');
    $password = (string) ($data['password'] ?? '');

    if ($secret === '' || $password === '') {
        reset_json(['ok' => false, 'error' => 'Secret and password are required'], 400);
    }

    if (strlen($password) < 8) {
        reset_json(['ok' => false, 'error' => 'Password must be at least 8 characters'], 400);
    }

    $host = $config['db']['host'] ?? 'localhost';
    $port = (int) ($config['db']['port'] ?? 3306);
    $name = $config['db']['name'] ?? '';
    $user = $config['db']['user'] ?? '';
    $pass = $config['db']['pass'] ?? '';
    $charset = $config['db']['charset'] ?? 'utf8mb4';

    if ($name === '') {
        reset_json(['ok' => false, 'error' => 'DB not configured'], 500);
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset={$charset}",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $tenantId = admin_validate_reset_secret($pdo, $secret);
    if ($tenantId === null) {
        reset_json(['ok' => false, 'error' => 'Invalid or expired reset link'], 403);
    }

    $configPath = dirname(__DIR__, 2) . '/config.php';
    $ok = admin_reset_password($pdo, $tenantId, $password, $configPath);
    if (!$ok) {
        reset_json(['ok' => false, 'error' => 'Failed to update password'], 500);
    }

    reset_json(['ok' => true]);
} catch (Throwable $e) {
    reset_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
