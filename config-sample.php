<?php

/**
 * config-sample.php — COPY THIS FILE TO config.php and fill in real values.
 *
 * config.php is gitignored and must never be committed: it holds DB
 * credentials, the encryption key path, and the ALTCHA HMAC key (spec 4.2).
 * Secrets should live in a gitignored .env file (owner preference, 2026-08)
 * rather than in this file — this sample reads every value from the
 * environment, and env.php loads `.env` into the environment at startup.
 * The encryption key file itself must live OUTSIDE the web root (spec 4.2 / 6).
 */

require_once __DIR__ . '/env.php';
env_load(__DIR__);

return [

    'db' => [
        'host'    => getenv('ERATO_DB_HOST') ?: 'localhost',
        'port'    => (int) (getenv('ERATO_DB_PORT') ?: 3306),
        'name'    => getenv('ERATO_DB_NAME') ?: '',           // e.g. u835116879_meertec_erato
        'user'    => getenv('ERATO_DB_USER') ?: '',           // e.g. u835116879_admin
        'pass'    => getenv('ERATO_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    // Path to the file holding the sodium key for credentials_encrypted
    // (OAuth refresh tokens, CalDAV credentials). Must be outside the web root.
    'encryption_key_path' => getenv('ERATO_ENC_KEY_PATH') ?: dirname(__DIR__) . '/eratotime-keys/enc.key',

    // ALTCHA proof-of-work HMAC key (spec 4.2 / Appendix B footgun): generate
    // ONCE at install time (e.g. bin2hex(random_bytes(32))) and persist here.
    // Regenerating per-request makes challenges unverifiable (Easy!Appointments bug).
    'altcha_hmac_key' => getenv('ERATO_ALTCHA_HMAC_KEY') ?: '',

    'admin' => [
        'username'      => 'admin',
        'password_hash' => '', // password_hash($passphrase, PASSWORD_DEFAULT) — single shared passphrase (spec 1.4)
    ],

    'app' => [
        'base_url' => getenv('ERATO_BASE_URL') ?: '',         // https://www.meertec.ltd
        'timezone' => 'Europe/London',                        // organizer timezone (default; also in global_settings)
    ],

    'caldav' => [
        'calendar_url' => getenv('ERATO_CALDAV_URL') ?: '',   // Baïkal calendar of record
        'username'     => getenv('ERATO_CALDAV_USERNAME') ?: '',
        'password'     => getenv('ERATO_CALDAV_PASSWORD') ?: '', // read only by bin/setup_caldav.php to encrypt into calendar_sources
    ],

    'smtp' => [
        'host' => getenv('ERATO_SMTP_HOST') ?: '',
        'port' => (int) (getenv('ERATO_SMTP_PORT') ?: 465),
        'user' => getenv('ERATO_SMTP_USER') ?: '',            // stephen@meertec.ltd
        'pass' => getenv('ERATO_SMTP_PASS') ?: '',
        'secure' => getenv('ERATO_SMTP_SECURE') ?: 'ssl',     // ssl|tls|''
        'from'  => getenv('ERATO_SMTP_FROM') ?: 'stephen@meertec.ltd',
        'from_name' => 'Eratotime',
    ],

];
