#!/usr/bin/env php
<?php

/**
 * bin/generate_keys.php — generate fresh CSRF + ALTCHA secrets for .env.
 *
 * These are NOT obtained from anywhere — you generate them once per
 * environment with a secure random source and keep them secret. Paste the
 * output into .env (ERATO_CSRF_KEY, ERATO_ALTCHA_HMAC_KEY).
 *
 *   php bin/generate_keys.php
 */

$csrf = bin2hex(random_bytes(32));
$altcha = bin2hex(random_bytes(32));

echo "ERATO_CSRF_KEY={$csrf}\n";
echo "ERATO_ALTCHA_HMAC_KEY={$altcha}\n";
echo "\nAdd these to .env. Regenerate per environment; never share them.\n";
