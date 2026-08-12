#!/usr/bin/env php
<?php

/**
 * bin/setup_caldav.php — one-time setup for the CalDAV (Baikal) source.
 *
 * Reads ERATO_CALDAV_URL / ERATO_CALDAV_USERNAME / ERATO_CALDAV_PASSWORD from
 * .env, encrypts the credentials at rest (spec 4.2), writes them to the seeded
 * `caldav` calendar_sources row, records the calendar URL, and flips it active.
 * The password never appears in git, in this script's output, or in plaintext
 * in the database.
 *
 *   php bin/setup_caldav.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../env.php';

env_load(dirname(__DIR__));

$config = require __DIR__ . '/../config.php';
if (!isset($config['db']['name']) || $config['db']['name'] === '') {
    fwrite(STDERR, "DB not configured — fill .env (ERATO_DB_*)\n");
    exit(1);
}

$url = (string) getenv('ERATO_CALDAV_URL');
$username = (string) getenv('ERATO_CALDAV_USERNAME');
$password = (string) getenv('ERATO_CALDAV_PASSWORD');
if ($url === '' || $username === '' || $password === '') {
    fwrite(STDERR, "Set ERATO_CALDAV_URL, ERATO_CALDAV_USERNAME and ERATO_CALDAV_PASSWORD in .env first\n");
    exit(1);
}

$key = crypto_key_load((string) $config['encryption_key_path']);
$payload = crypto_encrypt(json_encode(['username' => $username, 'password' => $password], JSON_UNESCAPED_SLASHES), $key);

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

$stmt = $pdo->prepare(
    "UPDATE calendar_sources
        SET credentials_encrypted = ?, calendar_identifier = ?, active = 1
      WHERE provider = 'caldav'
        AND tenant_id = (SELECT id FROM tenants WHERE slug = 'meertec' LIMIT 1)"
);
$stmt->execute([$payload, $url]);

if ($stmt->rowCount() === 0) {
    fwrite(STDERR, "No seeded 'caldav' calendar_sources row found for tenant 'meertec' — run the migration first\n");
    exit(1);
}

printf("CalDAV source configured and active: %s\n", $url);
printf("Credentials encrypted at rest with key: %s\n", $config['encryption_key_path']);
exit(0);
