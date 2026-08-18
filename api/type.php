<?php

/**
 * api/type.php — booking widget config for one meeting type (AJAX type switching).
 *
 *   GET api/type.php?tenant={slug}&type={slug}
 *
 * Returns the same payload book.php embeds in data-config, so the widget can
 * swap meeting types without a page reload. Public + read-only: it only
 * returns the tenant's own config, so no rate limiting is needed.
 */

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';

function type_json_out(Throwable $e, int $status = 500): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

try {
    if (!isset($config['db']['name']) || $config['db']['name'] === '') {
        throw new RuntimeException('DB not configured');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        (int) ($config['db']['port'] ?? 3306),
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $tenantSlug = (string) ($_GET['tenant'] ?? '');
    $typeSlug = (string) ($_GET['type'] ?? '');
    if ($tenantSlug === '' || $typeSlug === '') {
        type_json_out(new RuntimeException('tenant and type parameters are required'), 400);
    }

    $payload = booking_config_build($pdo, $config, $tenantSlug, $typeSlug, '/');
    if ($payload === null) {
        type_json_out(new RuntimeException('tenant or meeting type not found'), 404);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    type_json_out($e, $e instanceof RuntimeException && str_contains($e->getMessage(), 'not found') ? 404 : 500);
}
