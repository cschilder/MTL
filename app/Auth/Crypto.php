<?php

declare(strict_types=1);

namespace MTL\Auth;

use MTL\Core\Config;

defined('MTL_APP') || exit;

/**
 * Authenticated symmetric encryption for the few values that must be stored
 * reversibly: TOTP secrets and recovery codes.
 *
 * libsodium's secretbox (XSalsa20-Poly1305) when available, AES-256-GCM
 * through OpenSSL otherwise. Both are authenticated, so tampering is detected
 * on decryption rather than producing garbage.
 *
 * Every ciphertext carries a one-byte version prefix so the format can change
 * later without a migration.
 */
final class Crypto
{
    private const VERSION_SODIUM = "\x01";
    private const VERSION_OPENSSL = "\x02";

    /**
     * Derives the 32-byte encryption key from the configured application key.
     *
     * A separate derivation rather than the raw key so the same secret can
     * safely be reused for other purposes (signing, for instance) without the
     * two sharing key material.
     */
    private static function key(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $configured = (string) Config::get('app.key', '');

        if ($configured === '') {
            throw new \RuntimeException(
                'app.key is not set. Run `php bin/console.php key:generate` and put the value in config/config.php.'
            );
        }

        $raw = base64_decode($configured, true);

        // Accept a key that was pasted without base64 encoding rather than
        // failing in a way that is hard to diagnose.
        if ($raw === false || strlen($raw) < 16) {
            $raw = $configured;
        }

        return $cached = hash_hkdf('sha256', $raw, 32, 'mtl.encryption.v1');
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

            return self::VERSION_SODIUM . $nonce . $cipher;
        }

        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('Neither libsodium nor OpenSSL is available; MTL cannot store secrets safely.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::VERSION_OPENSSL . $iv . $tag . $cipher;
    }

    /**
     * Returns null when the value cannot be decrypted, which happens when the
     * application key was rotated. Callers treat that as "the secret is gone"
     * rather than as an error.
     */
    public static function decrypt(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $key = self::key();
        $version = $payload[0];
        $body = substr($payload, 1);

        try {
            if ($version === self::VERSION_SODIUM) {
                if (!function_exists('sodium_crypto_secretbox_open')) {
                    return null;
                }

                $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                if (strlen($body) <= $nonceLength) {
                    return null;
                }

                $plain = sodium_crypto_secretbox_open(
                    substr($body, $nonceLength),
                    substr($body, 0, $nonceLength),
                    $key
                );

                return $plain === false ? null : $plain;
            }

            if ($version === self::VERSION_OPENSSL) {
                if (strlen($body) <= 28) {
                    return null;
                }

                $plain = openssl_decrypt(
                    substr($body, 28),
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    substr($body, 0, 12),
                    substr($body, 12, 16)
                );

                return $plain === false ? null : $plain;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Keyed hash for values that only ever need to be compared, such as the
     * secret half of a remember-me token.
     */
    public static function hmac(string $value, string $context = 'default'): string
    {
        return hash_hmac('sha256', $value, hash_hkdf('sha256', self::key(), 32, 'mtl.hmac.' . $context));
    }

    /**
     * Signs a payload so it can travel through an untrusted place (a URL, a
     * cookie) and be verified on the way back.
     */
    public static function sign(string $payload, string $context = 'default'): string
    {
        return $payload . '.' . substr(self::hmac($payload, $context), 0, 32);
    }

    public static function verifySigned(string $signed, string $context = 'default'): ?string
    {
        $position = strrpos($signed, '.');

        if ($position === false) {
            return null;
        }

        $payload = substr($signed, 0, $position);
        $signature = substr($signed, $position + 1);

        $expected = substr(self::hmac($payload, $context), 0, 32);

        return hash_equals($expected, $signature) ? $payload : null;
    }
}
