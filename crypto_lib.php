<?php

/**
 * crypto_lib.php — symmetric encryption at rest (spec 4.2).
 *
 * OAuth refresh tokens and CalDAV credentials are encrypted in the database
 * with sodium_crypto_secretbox (XSalsa20-Poly1305). The 32-byte key lives in
 * a file OUTSIDE the web root (path from config['encryption_key_path']); the
 * file is created automatically with a fresh random key on first use.
 *
 * This deliberately diverges from Easy!Appointments, which stored its Google
 * token as a plaintext JSON blob in the DB (Appendix B).
 */

if (!function_exists('crypto_key_load')) {

    /**
     * Load (or create) the 32-byte secret key file. Returns the raw key.
     */
    function crypto_key_load(string $path): string
    {
        $path = (string) $path;
        if ($path === '') {
            throw new RuntimeException('crypto_key_load(): encryption_key_path is not configured');
        }
        if (is_file($path)) {
            $key = file_get_contents($path);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
            throw new RuntimeException('crypto_key_load(): key file exists but is not ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes: ' . $path);
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true)) {
                throw new RuntimeException('crypto_key_load(): cannot create key directory: ' . $dir);
            }
        }
        $key = sodium_crypto_secretbox_keygen();
        if (file_put_contents($path, $key, LOCK_EX) === false) {
            throw new RuntimeException('crypto_key_load(): cannot write key file: ' . $path);
        }
        @chmod($path, 0600);
        return $key;
    }

    /**
     * Encrypt a string. Returns base64(nonce . ciphertext).
     */
    function crypto_encrypt(string $plaintext, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a string produced by crypto_encrypt(). Returns null on failure.
     */
    function crypto_decrypt(string $payload, string $key): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        return $plain === false ? null : $plain;
    }
}
