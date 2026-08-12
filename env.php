<?php

/**
 * env.php — minimal .env loader (no dependency).
 *
 * Reads a KEY=VALUE file (project-root .env) into the environment so that
 * getenv()/$_ENV work everywhere. Secrets (DB password, CalDAV password,
 * SMTP credentials, ALTCHA key) live ONLY in the gitignored .env file —
 * never in a committed .php file (owner preference, 2026-08).
 *
 * On Hostinger the .env must sit where the webserver cannot serve it:
 * either outside public_html (code deployed above the web root) or guarded
 * by an .htaccess deny. It is NOT committed to git.
 */

if (!function_exists('env_load')) {

    /**
     * Parse and apply a .env file. Real values never overwrite values already
     * present in the environment (so deployment-time env injection wins).
     */
    function env_load(string $dir, bool $overwrite = false): void
    {
        $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            if ($key === '') {
                continue;
            }
            if ($overwrite || getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
