<?php

/**
 * api/requests.php — POST booking-request submission (spec 2.3 / section 7).
 *
 * The single unauthenticated write path. Transport guards run first (spec 4.2):
 *   1. per-IP rate limiting (file cache),
 *   2. honeypot,
 *   3. stateless CSRF token (issued by book.php),
 *   4. ALTCHA proof-of-work (when enabled).
 * Domain logic then runs in request_lib::request_submit() (transaction +
 * availability re-check + soft-hold + outbox queue). Notifications are
 * fire-and-forget; failures stay pending for cron/retry_notifications.php.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../security_lib.php';
require __DIR__ . '/../request_lib.php';

$config = require __DIR__ . '/../config.php';

function requests_json(Throwable|array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    $raw = file_get_contents('php://input');
    $input = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($input)) {
        requests_json(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    $tenantSlug = (string) ($input['tenant'] ?? '');
    if ($tenantSlug === '') {
        requests_json(['ok' => false, 'error' => 'Missing tenant.'], 400);
    }

    // 1. Rate limit (per tenant + IP).
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $runtimeDir = (string) ($config['runtime_dir'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eratotime');
    if (!security_rate_limit('submit:' . $tenantSlug . ':' . $ip, 10, 600, $runtimeDir)) {
        requests_json(['ok' => false, 'error' => 'Too many requests — please try again later.'], 429);
    }

    // 2. Honeypot.
    if (security_honeypot_filled($input, 'website')) {
        requests_json(['ok' => true, 'message' => 'Request received. The organizer will confirm.'], 200); // silently drop bots
    }

    // 3. CSRF.
    $csrfSecret = (string) ($config['csrf_secret'] ?? '');
    if (!security_csrf_verify((string) ($input['csrf'] ?? ''), $csrfSecret)) {
        requests_json(['ok' => false, 'error' => 'Session expired — please reload the page and try again.'], 403);
    }

    // 4. ALTCHA (only when enabled).
    if (altcha_enabled($config) && !altcha_verify_payload($config, (string) ($input['altcha'] ?? ''))) {
        requests_json(['ok' => false, 'error' => 'Could not verify the anti-bot check — please try again.'], 403);
    }

    $result = request_submit($pdo, $config, $input);
    if (!$result['ok']) {
        requests_json(['ok' => false, 'error' => $result['error']], 400);
    }
    requests_json(['ok' => true, 'message' => $result['message'], 'request_id' => $result['request_id']]);
} catch (Throwable $e) {
    requests_json(['ok' => false, 'error' => 'Something went wrong — please try again.'], 500);
}
