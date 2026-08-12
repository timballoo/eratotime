<?php

use PHPUnit\Framework\TestCase;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\Algorithm\ShaAlgorithm;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;

/**
 * Security layer tests (spec 4.2): file-cache rate limiting, stateless CSRF,
 * honeypot, and ALTCHA proof-of-work challenge/verify.
 */
final class SecurityLibTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/eratotime-sec-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->tmpDir);
        }
    }

    public function testRateLimitAllowsUpToMaxThenBlocks(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue(security_rate_limit('bucket:x', 3, 60, $this->tmpDir), "request {$i} should be allowed");
        }
        self::assertFalse(security_rate_limit('bucket:x', 3, 60, $this->tmpDir), '4th request should be blocked');
    }

    public function testRateLimitBucketsAreIndependent(): void
    {
        security_rate_limit('bucket:a', 1, 60, $this->tmpDir);
        self::assertFalse(security_rate_limit('bucket:a', 1, 60, $this->tmpDir));
        self::assertTrue(security_rate_limit('bucket:b', 1, 60, $this->tmpDir), 'different bucket must be independent');
    }

    public function testCsrfIssueAndVerify(): void
    {
        $secret = 'test-secret';
        $token = security_csrf_issue($secret);
        self::assertTrue(security_csrf_verify($token, $secret));
        self::assertFalse(security_csrf_verify($token, 'wrong-secret'));
        self::assertFalse(security_csrf_verify($token . 'x', $secret));
        self::assertFalse(security_csrf_verify('', $secret));
        self::assertFalse(security_csrf_verify('garbage', $secret));
    }

    public function testCsrfExpires(): void
    {
        $secret = 'test-secret';
        $token = security_csrf_issue($secret, -10); // already expired
        self::assertFalse(security_csrf_verify($token, $secret));
    }

    public function testHoneypot(): void
    {
        self::assertTrue(security_honeypot_filled(['website' => 'spam'], 'website'));
        self::assertFalse(security_honeypot_filled(['website' => '  '], 'website'));
        self::assertFalse(security_honeypot_filled([], 'website'));
    }

    public function testAltchaDisabledAcceptsAnything(): void
    {
        self::assertFalse(altcha_enabled(['altcha_hmac_key' => '']));
        self::assertTrue(altcha_verify_payload(['altcha_hmac_key' => ''], 'not-a-real-payload'));
    }

    public function testAltchaChallengeVerifyRoundTrip(): void
    {
        $config = ['altcha_hmac_key' => 'test-hmac-key'];
        self::assertTrue(altcha_enabled($config));

        // The widget would solve the server's challenge; for the test we create
        // a LOW-cost challenge (solve would otherwise brute-force ~25M hashes)
        // and solve it. Verification derives the key from the challenge's own
        // parameters, so a low-cost challenge verifies identically.
        $altcha = altcha_instance($config);
        $challenge = $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: new Sha(ShaAlgorithm::SHA256),
            cost: 2000,
            expiresAt: time() + 300,
        ));
        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            challenge: $challenge,
            algorithm: new Sha(ShaAlgorithm::SHA256),
            timeout: 5.0,
        ));
        self::assertNotNull($solution, 'test solve should find a solution');

        $payload = new Payload($challenge, $solution);
        self::assertTrue(altcha_verify_payload($config, $payload->toBase64()));

        // The production challenge endpoint still produces parseable JSON.
        $served = Challenge::fromArray(json_decode(altcha_challenge_json($config), true));
        self::assertNotNull($served->signature, 'challenge from the endpoint must be HMAC-signed');

        // Tampered payload must fail.
        self::assertFalse(altcha_verify_payload($config, $payload->toBase64() . 'AAAA'));
        self::assertFalse(altcha_verify_payload($config, ''));
        self::assertFalse(altcha_verify_payload($config, '!!!not-base64!!!'));
    }
}
