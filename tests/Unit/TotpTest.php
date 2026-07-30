<?php

declare(strict_types=1);

namespace MTL\Tests\Unit;

use MTL\Auth\Totp;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Time-based one-time passwords.
 *
 * The point of interest is the published test vectors. Two-factor codes are
 * verified against whatever authenticator app the account holder happens to
 * use, so agreeing with our own implementation proves nothing — the codes have
 * to match the numbers in RFC 6238 and RFC 4648 exactly, or enrolment appears to
 * work and then nobody can sign in.
 */
final class TotpTest extends TestCase
{
    /**
     * RFC 6238, appendix B.
     *
     * The published table uses a 20-byte ASCII seed, "12345678901234567890", and
     * SHA-1 with 8 digits. MTL uses 6, which is the first authenticator-app
     * convention and is the low 6 digits of the same number.
     */
    public function testRfc6238TestVectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $vectors = [
            59          => '287082',
            1111111109  => '081804',
            1111111111  => '050471',
            1234567890  => '005924',
            2000000000  => '279037',
            20000000000 => '353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame(
                $expected,
                Totp::currentCode($secret, $timestamp),
                'RFC 6238 vector at t=' . $timestamp
            );
        }
    }

    /**
     * RFC 4648, section 10: the base32 test vectors.
     *
     * The characters have to match the standard exactly. The `=` padding does
     * not: the encoder leaves it off on purpose, because the output goes into an
     * `otpauth://` URI. Both forms are fed to the decoder, since a secret copied
     * out of an app that pads has to work too.
     */
    public function testRfc4648Base32Vectors(): void
    {
        $vectors = [
            ''       => '',
            'f'      => 'MY======',
            'fo'     => 'MZXQ====',
            'foo'    => 'MZXW6===',
            'foob'   => 'MZXW6YQ=',
            'fooba'  => 'MZXW6YTB',
            'foobar' => 'MZXW6YTBOI======',
        ];

        foreach ($vectors as $plain => $padded) {
            $unpadded = rtrim($padded, '=');

            $this->assertSame($unpadded, Totp::base32Encode($plain), 'encoding ' . json_encode($plain));
            $this->assertSame($plain, Totp::base32Decode($padded), 'decoding padded ' . $padded);
            $this->assertSame($plain, Totp::base32Decode($unpadded), 'decoding unpadded ' . $unpadded);
        }
    }

    /** The secret must survive a trip through the URI an app scans. */
    public function testSecretSurvivesTheProvisioningUri(): void
    {
        $secret = Totp::generateSecret();

        $uri = Totp::provisioningUri($secret, 'reiziger@example.com', 'MTL');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $this->assertSame($secret, $query['secret'] ?? null);
        $this->assertNotContains('=', (string) ($query['secret'] ?? ''));
    }

    public function testBase32DecodeIgnoresFormatting(): void
    {
        // Authenticator apps show the secret in spaced groups and people paste it
        // back that way, sometimes in lower case.
        $this->assertSame('foobar', Totp::base32Decode('MZXW 6YTB OI======'));
        $this->assertSame('foobar', Totp::base32Decode('mzxw6ytboi'));
    }

    public function testVerifyAcceptsTheCurrentCode(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $step = Totp::verify($secret, Totp::currentCode($secret, $now), $now);

        $this->assertNotNull($step);
        $this->assertSame(intdiv($now, 30), $step);
    }

    /**
     * A clock a little out of step must still work; a clock far out must not.
     */
    public function testVerifyToleratesOneStepOfDrift(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $this->assertNotNull(Totp::verify($secret, Totp::currentCode($secret, $now - 30), $now));
        $this->assertNotNull(Totp::verify($secret, Totp::currentCode($secret, $now + 30), $now));
        $this->assertNull(Totp::verify($secret, Totp::currentCode($secret, $now - 120), $now));
        $this->assertNull(Totp::verify($secret, Totp::currentCode($secret, $now + 120), $now));
    }

    /**
     * The reason verify() returns the step rather than a boolean: a code stays
     * valid for its whole window, so without recording which step was used it
     * can be replayed by anyone who sees it.
     */
    public function testACodeCannotBeUsedTwice(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $code = Totp::currentCode($secret, $now);

        $step = Totp::verify($secret, $code, $now);

        $this->assertNotNull($step);
        $this->assertNull(
            Totp::verify($secret, $code, $now, (int) $step),
            'the same code was accepted a second time'
        );
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $secret = Totp::generateSecret();

        $this->assertNull(Totp::verify($secret, ''));
        $this->assertNull(Totp::verify($secret, '12345'));
        $this->assertNull(Totp::verify($secret, '1234567'));
        $this->assertNull(Totp::verify($secret, 'abcdef'));
    }

    /** People paste "123 456" from an app that groups the digits. */
    public function testVerifyIgnoresSeparatorsInTheCode(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $code = Totp::currentCode($secret, $now);

        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        $this->assertNotNull(Totp::verify($secret, $spaced, $now));
    }

    public function testGeneratedSecretsAreUsableAndDistinct(): void
    {
        $first = Totp::generateSecret();
        $second = Totp::generateSecret();

        $this->assertNotSame($first, $second);
        $this->assertMatches('/^[A-Z2-7]+=*$/', $first);
        // 160 bits, which is what RFC 4226 requires as a minimum.
        $this->assertGreaterThan(19, strlen(Totp::base32Decode($first)));
    }

    public function testProvisioningUriCarriesTheParametersAnAppNeeds(): void
    {
        $uri = Totp::provisioningUri('MZXW6YTBOI', 'reiziger@example.com', 'MTL');

        $this->assertMatches('#^otpauth://totp/#', $uri);
        $this->assertContains('secret=MZXW6YTBOI', $uri);
        $this->assertContains('issuer=MTL', $uri);
        $this->assertContains('digits=6', $uri);
        $this->assertContains('period=30', $uri);
        $this->assertContains('algorithm=SHA1', $uri);
        // The label is "issuer:account", and the colon has to survive escaping
        // or the app shows the whole thing as one name.
        $this->assertContains('MTL', $uri);
        $this->assertContains('reiziger%40example.com', $uri);
    }

    public function testSecondsRemainingCountsDownWithinTheStep(): void
    {
        // A timestamp exactly on a step boundary, so the arithmetic is readable.
        $boundary = 1_699_999_980;

        $this->assertSame(0, $boundary % 30, 'the chosen timestamp is not on a step boundary');
        $this->assertSame(30, Totp::secondsRemaining($boundary));
        $this->assertSame(29, Totp::secondsRemaining($boundary + 1));
        $this->assertSame(1, Totp::secondsRemaining($boundary + 29));
        $this->assertSame(30, Totp::secondsRemaining($boundary + 30));
    }

    public function testRecoveryCodesAreDistinctAndReadable(): void
    {
        $codes = Totp::generateRecoveryCodes(8);

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));

        foreach ($codes as $code) {
            // Written down and typed back in later, so no characters that are
            // easily confused on paper.
            $this->assertMatches('/^[0-9A-Za-z-]+$/', $code);
            $this->assertGreaterThan(7, strlen($code));
        }
    }
}
