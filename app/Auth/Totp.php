<?php

declare(strict_types=1);

namespace MTL\Auth;

defined('MTL_APP') || exit;

/**
 * Time-based one-time passwords (RFC 6238) for the optional second factor.
 *
 * Compatible with Google Authenticator, Aegis, 1Password and the rest: SHA-1,
 * six digits, a thirty-second step. Those parameters are fixed because several
 * popular apps silently ignore anything else in the enrolment URI.
 */
final class Totp
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const ALGORITHM = 'sha1';

    /**
     * How many steps either side of the current one are accepted. One step
     * tolerates roughly thirty seconds of clock drift in each direction.
     */
    private const WINDOW = 1;

    /** A fresh base32 secret, 160 bits as the RFC recommends for SHA-1. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The otpauth:// URI that goes into the enrolment QR code.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Verifies a code against the secret.
     *
     * Returns the matched time step so the caller can store it and refuse to
     * accept the same code twice — without that, a code stays valid for its
     * whole window and can be replayed.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $lastUsedStep = 0): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $timestamp ??= time();
        $current = intdiv($timestamp, self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; ++$offset) {
            $step = $current + $offset;

            if ($step <= $lastUsedStep) {
                continue;
            }

            if (hash_equals(self::codeForStep($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function currentCode(string $secret, ?int $timestamp = null): string
    {
        return self::codeForStep($secret, intdiv($timestamp ?? time(), self::PERIOD));
    }

    /** Seconds until the current code expires, for the countdown in the UI. */
    public static function secondsRemaining(?int $timestamp = null): int
    {
        return self::PERIOD - (($timestamp ?? time()) % self::PERIOD);
    }

    private static function codeForStep(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            return str_repeat('0', self::DIGITS);
        }

        // The counter is a 64-bit big-endian integer.
        $binaryStep = pack('J', $step);

        $hash = hash_hmac(self::ALGORITHM, $binaryStep, $key, true);

        // Dynamic truncation: the low nibble of the last byte selects a
        // four-byte window, whose top bit is masked off.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Base32 without the `=` padding RFC 4648 specifies.
     *
     * Deliberate: the output goes into an `otpauth://` URI, where `=` has to be
     * percent-escaped and several authenticator apps then read the secret
     * wrongly. Padding carries no information — it only rounds the text out to a
     * multiple of eight characters — and base32Decode() accepts it either way,
     * so a secret copied out of an app that does pad still works.
     */
    public static function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        // Pad to a multiple of five bits, the width of one base32 character.
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    public static function base32Decode(string $encoded): string
    {
        // Authenticator apps display secrets in groups separated by spaces and
        // some users paste the padding along with it.
        $encoded = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $encoded) ?? '');

        if ($encoded === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            // A trailing partial byte is padding and is discarded.
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }

    /**
     * Single-use codes for when the authenticator app is gone.
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; ++$i) {
            $codes[] = \MTL\Support\Str::randomCode(5) . '-' . \MTL\Support\Str::randomCode(5);
        }

        return $codes;
    }
}
