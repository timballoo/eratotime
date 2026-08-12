<?php

use PHPUnit\Framework\TestCase;

/**
 * Encryption-at-rest tests (spec 4.2) — sodium secretbox round-trip and key
 * file lifecycle. Uses a throwaway temp key file.
 */
final class CryptoLibTest extends TestCase
{
    private string $keyDir;
    private string $keyPath;

    protected function setUp(): void
    {
        $this->keyDir = sys_get_temp_dir() . '/eratotime-crypto-' . bin2hex(random_bytes(4));
        $this->keyPath = $this->keyDir . '/enc.key';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->keyDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->keyDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->keyDir);
        }
    }

    public function testKeyFileIsCreatedOnFirstLoad(): void
    {
        $key = crypto_key_load($this->keyPath);
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
        self::assertFileExists($this->keyPath);
        // Second load returns the same key.
        self::assertSame($key, crypto_key_load($this->keyPath));
    }

    public function testRoundTrip(): void
    {
        $key = crypto_key_load($this->keyPath);
        $payload = crypto_encrypt('{"username":"stephen@meertec.ltd","password":"s3cr3t"}', $key);
        self::assertNotSame('', $payload);
        self::assertStringNotContainsString('s3cr3t', $payload, 'plaintext must not be visible in ciphertext');
        self::assertSame('{"username":"stephen@meertec.ltd","password":"s3cr3t"}', crypto_decrypt($payload, $key));
    }

    public function testTamperedPayloadFailsToDecrypt(): void
    {
        $key = crypto_key_load($this->keyPath);
        $payload = crypto_encrypt('secret', $key);
        $tampered = ($payload[0] === 'A' ? 'B' : 'A') . substr($payload, 1);
        self::assertNull(crypto_decrypt($tampered, $key));
        self::assertNull(crypto_decrypt('', $key));
        self::assertNull(crypto_decrypt('not-base64!!', $key));
    }

    public function testWrongKeyFailsToDecrypt(): void
    {
        $keyA = crypto_key_load($this->keyPath);
        $payload = crypto_encrypt('secret', $keyA);
        $keyB = str_repeat('x', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        self::assertNull(crypto_decrypt($payload, $keyB));
    }

    public function testEmptyKeyPathThrows(): void
    {
        $this->expectException(RuntimeException::class);
        crypto_key_load('');
    }
}
