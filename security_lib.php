<?php

/**
 * security_lib.php — the unauthenticated-request security layer (spec 4.2).
 *
 *  - Per-IP file-cache rate limiting (no Redis needed on shared hosting).
 *  - Stateless HMAC-signed CSRF token (issued by the page, verified on submit).
 *  - Honeypot field check.
 *  - ALTCHA proof-of-work challenge/verify (spec 4.2 / Appendix B footgun:
 *    the HMAC key must be persisted in config, not regenerated per request).
 */

if (!function_exists('security_rate_limit')) {

    /**
     * Per-bucket sliding rate limit backed by a file cache.
     * Returns true if the request is allowed. The bucket key is typically
     * "submit:{tenant}:{ip}". Files are pruned by TTL.
     */
    function security_rate_limit(string $bucket, int $max, int $windowSeconds, string $dir): bool
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'rl-' . hash('sha256', $bucket) . '.txt';
        $now = time();

        $counts = [];
        if (is_file($file)) {
            $data = @json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                foreach ($data as $ts => $n) {
                    if ($now - (int) $ts < $windowSeconds) {
                        $counts[(int) $ts] = (int) $n;
                    }
                }
            }
        }
        $counts[$now] = ($counts[$now] ?? 0) + 1;
        $total = array_sum($counts);
        $allowed = $total <= $max;

        if ($allowed) {
            @file_put_contents($file, json_encode($counts), LOCK_EX);
        }
        return $allowed;
    }

    /**
     * Issue a stateless CSRF token valid for $ttlSeconds (default 15 min).
     * Format: base64url(expiry . "." . hmac(expiry, secret)).
     */
    function security_csrf_issue(string $secret, int $ttlSeconds = 900): string
    {
        $exp = time() + $ttlSeconds;
        $sig = hash_hmac('sha256', (string) $exp, $secret);
        return rtrim(strtr(base64_encode($exp . '.' . $sig), '+/', '-_'), '=');
    }

    /**
     * Verify a token produced by security_csrf_issue().
     */
    function security_csrf_verify(string $token, string $secret): bool
    {
        if ($token === '' || $secret === '') {
            return false;
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || !str_contains($decoded, '.')) {
            return false;
        }
        [$exp, $sig] = explode('.', $decoded, 2);
        if (!ctype_digit($exp) || (int) $exp < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $exp, $secret);
        return hash_equals($expected, $sig);
    }

    /**
     * True if the honeypot field was filled (a bot signal).
     */
    function security_honeypot_filled(array $data, string $field = 'website'): bool
    {
        return isset($data[$field]) && trim((string) $data[$field]) !== '';
    }

    /**
     * ALTCHA is active only when a persistent HMAC key is configured.
     */
    function altcha_enabled(array $config): bool
    {
        return (string) ($config['altcha_hmac_key'] ?? '') !== '';
    }

    /**
     * Build the ALTCHA engine. The challenge signature secret IS the persisted
     * HMAC key — never regenerate per request (Appendix B footgun).
     */
    function altcha_instance(array $config): AltchaOrg\Altcha\Altcha
    {
        return new AltchaOrg\Altcha\Altcha(
            (string) ($config['altcha_hmac_key'] ?? ''),
            null,
            AltchaOrg\Altcha\HmacAlgorithm::SHA256
        );
    }

    /**
     * Create a fresh challenge for the booking page widget.
     * Returns the JSON the <altcha-widget challenge-url> endpoint serves.
     */
    function altcha_challenge_json(array $config, int $ttlSeconds = 300): string
    {
        $altcha = altcha_instance($config);
        $options = new AltchaOrg\Altcha\CreateChallengeOptions(
            algorithm: new AltchaOrg\Altcha\Algorithm\Sha(AltchaOrg\Altcha\Algorithm\ShaAlgorithm::SHA256),
            cost: 100000,
            expiresAt: time() + $ttlSeconds,
        );
        return $altcha->createChallenge($options)->toJson();
    }

    /**
     * Verify a widget solution (base64 JSON payload). Returns true if valid.
     */
    function altcha_verify_payload(array $config, string $payload): bool
    {
        if (!altcha_enabled($config)) {
            return true; // disabled: nothing to prove
        }
        if ($payload === '') {
            return false;
        }
        try {
            $payloadObj = AltchaOrg\Altcha\Payload::fromBase64($payload);
            $altcha = altcha_instance($config);
            $result = $altcha->verifySolution(new AltchaOrg\Altcha\VerifySolutionOptions(
                payload: $payloadObj,
                algorithm: new AltchaOrg\Altcha\Algorithm\Sha(AltchaOrg\Altcha\Algorithm\ShaAlgorithm::SHA256),
            ));
            return $result->verified;
        } catch (Throwable $e) {
            return false;
        }
    }
}
