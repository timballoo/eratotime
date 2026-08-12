<?php

/**
 * api/altcha.php — ALTCHA challenge endpoint (spec 4.2).
 * Serves a fresh proof-of-work challenge JSON for the booking page widget.
 * Returns 404 when ALTCHA is not enabled (no HMAC key configured).
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../security_lib.php';

$config = require __DIR__ . '/../config.php';

if (!altcha_enabled($config)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'not enabled']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo altcha_challenge_json($config);
