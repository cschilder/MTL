<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Config;
use MTL\Core\Logger;

defined('MTL_APP') || exit;

/**
 * Outgoing mail: password resets and invitations, and nothing else.
 *
 * Three transports. 'mail' hands the message to PHP's mail(), which Strato
 * routes through its own relay and which is the only one that works out of the
 * box there. 'smtp' talks to a server directly, for anyone who would rather
 * send through their own provider. 'log' writes the message to storage/logs,
 * which is what development uses.
 */
final class MailService
{
    /**
     * Sends a plain-text message.
     *
     * Text rather than HTML on purpose: the two messages MTL sends are a link
     * and a sentence explaining it, an HTML version would add nothing, and a
     * text-only mail is far less likely to be filed as spam.
     */
    public static function send(string $to, string $subject, string $body): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            Logger::instance()->warning('Refusing to send to an invalid address', ['to' => $to]);

            return false;
        }

        // A newline in either field would let a caller inject extra headers.
        $subject = self::sanitiseHeader($subject);
        $to = self::sanitiseHeader($to);

        $transport = (string) Config::get('mail.transport', 'mail');

        return match ($transport) {
            'log'   => self::writeToLog($to, $subject, $body),
            'smtp'  => self::sendViaSmtp($to, $subject, $body),
            default => self::sendViaMailFunction($to, $subject, $body),
        };
    }

    private static function sendViaMailFunction(string $to, string $subject, string $body): bool
    {
        $fromAddress = self::sanitiseHeader((string) Config::get('mail.from_address', ''));
        $fromName = self::sanitiseHeader((string) Config::get('mail.from_name', 'MTL'));

        $headers = [
            'From: ' . self::encodeName($fromName) . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'MIME-Version: 1.0',
            'X-Mailer: MTL',
            'Auto-Submitted: auto-generated',
        ];

        // The fifth parameter sets the envelope sender, which many hosts
        // require in order to accept the message at all.
        $sent = @mail(
            $to,
            self::encodeSubject($subject),
            self::normaliseBody($body),
            implode("\r\n", $headers),
            $fromAddress === '' ? '' : '-f' . $fromAddress
        );

        if (!$sent) {
            Logger::instance()->error('mail() refused the message', ['to' => $to, 'subject' => $subject]);
        }

        return $sent;
    }

    /**
     * A minimal SMTP client.
     *
     * Enough for AUTH LOGIN over TLS, which is what every provider MTL would
     * plausibly be pointed at supports.
     */
    private static function sendViaSmtp(string $to, string $subject, string $body): bool
    {
        /** @var array<string,mixed> $smtp */
        $smtp = Config::get('mail.smtp', []);

        $host = (string) ($smtp['host'] ?? '');
        $port = (int) ($smtp['port'] ?? 587);
        $encryption = (string) ($smtp['encryption'] ?? 'tls');

        if ($host === '') {
            Logger::instance()->error('SMTP transport selected but no host configured');

            return false;
        }

        $prefix = $encryption === 'ssl' ? 'ssl://' : '';

        $socket = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            15,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            Logger::instance()->error('Could not reach the SMTP server', ['host' => $host, 'error' => $errorMessage]);

            return false;
        }

        stream_set_timeout($socket, 15);

        try {
            $read = static function () use ($socket): string {
                $response = '';

                while (($line = fgets($socket, 515)) !== false) {
                    $response .= $line;

                    // A multi-line reply has a hyphen after the code; the last
                    // line has a space.
                    if (strlen($line) < 4 || $line[3] === ' ') {
                        break;
                    }
                }

                return $response;
            };

            $write = static function (string $command) use ($socket, $read): string {
                fwrite($socket, $command . "\r\n");

                return $read();
            };

            $expect = static function (string $response, string $code, string $step): void {
                if (!str_starts_with($response, $code)) {
                    throw new \RuntimeException($step . ' failed: ' . trim($response));
                }
            };

            $expect($read(), '220', 'Greeting');

            $hostname = parse_url((string) Config::get('app.url', 'localhost'), PHP_URL_HOST) ?: 'localhost';

            $expect($write('EHLO ' . $hostname), '250', 'EHLO');

            if ($encryption === 'tls') {
                $expect($write('STARTTLS'), '220', 'STARTTLS');

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Could not start TLS');
                }

                // The handshake resets the session, so EHLO is repeated.
                $expect($write('EHLO ' . $hostname), '250', 'EHLO after STARTTLS');
            }

            $username = (string) ($smtp['username'] ?? '');

            if ($username !== '') {
                $expect($write('AUTH LOGIN'), '334', 'AUTH');
                $expect($write(base64_encode($username)), '334', 'Username');
                $expect($write(base64_encode((string) ($smtp['password'] ?? ''))), '235', 'Password');
            }

            $fromAddress = (string) Config::get('mail.from_address', '');

            $expect($write('MAIL FROM:<' . $fromAddress . '>'), '250', 'MAIL FROM');
            $expect($write('RCPT TO:<' . $to . '>'), '250', 'RCPT TO');
            $expect($write('DATA'), '354', 'DATA');

            $message = implode("\r\n", [
                'From: ' . self::encodeName((string) Config::get('mail.from_name', 'MTL')) . ' <' . $fromAddress . '>',
                'To: ' . $to,
                'Subject: ' . self::encodeSubject($subject),
                'Date: ' . gmdate('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'Auto-Submitted: auto-generated',
                '',
                // A line consisting of a single dot ends the message, so one in
                // the body has to be escaped.
                preg_replace('/^\./m', '..', self::normaliseBody($body)),
                '.',
            ]);

            fwrite($socket, $message . "\r\n");

            $expect($read(), '250', 'Message body');

            $write('QUIT');

            return true;
        } catch (\Throwable $e) {
            Logger::instance()->error('SMTP delivery failed', ['error' => $e->getMessage()]);

            return false;
        } finally {
            fclose($socket);
        }
    }

    private static function writeToLog(string $to, string $subject, string $body): bool
    {
        Logger::instance()->info('Mail (log transport)', [
            'to'      => $to,
            'subject' => $subject,
        ]);

        $file = storage_path('logs/mail-' . gmdate('Y-m-d') . '.log');

        $entry = str_repeat('=', 72) . "\n"
            . 'To:      ' . $to . "\n"
            . 'Subject: ' . $subject . "\n"
            . 'Date:    ' . gmdate('r') . "\n"
            . str_repeat('-', 72) . "\n"
            . $body . "\n\n";

        return @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    // -------------------------------------------------------------------------
    // Message construction
    // -------------------------------------------------------------------------

    /**
     * The password-reset message.
     */
    public static function sendPasswordReset(string $to, string $name, string $link, int $minutes): bool
    {
        $site = SettingsService::string('site.title', 'MTL');

        $body = <<<TEXT
        Hallo {$name},

        Er is een nieuw wachtwoord aangevraagd voor je account op {$site}.
        Gebruik deze link om er een in te stellen:

        {$link}

        De link verloopt over {$minutes} minuten en werkt één keer.

        Heb je dit niet zelf aangevraagd, dan hoef je niets te doen: zolang je
        de link niet gebruikt, verandert er niets aan je account.

        — {$site}
        TEXT;

        return self::send($to, $site . ' — nieuw wachtwoord instellen', $body);
    }

    /**
     * The invitation message.
     */
    public static function sendInvitation(string $to, string $name, string $link, string $invitedBy, int $days): bool
    {
        $site = SettingsService::string('site.title', 'MTL');

        $body = <<<TEXT
        Hallo {$name},

        {$invitedBy} heeft een account voor je aangemaakt op {$site}.
        Kies via deze link een wachtwoord om het in gebruik te nemen:

        {$link}

        De link verloopt over {$days} dagen.

        — {$site}
        TEXT;

        return self::send($to, $site . ' — je account staat klaar', $body);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Strips anything that could start a new header line. */
    private static function sanitiseHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    /** RFC 2047 encoding, so a non-ASCII subject survives. */
    private static function encodeSubject(string $subject): string
    {
        return preg_match('/[^\x20-\x7E]/', $subject) === 1
            ? '=?UTF-8?B?' . base64_encode($subject) . '?='
            : $subject;
    }

    private static function encodeName(string $name): string
    {
        if (preg_match('/[^\x20-\x7E]/', $name) === 1) {
            return '=?UTF-8?B?' . base64_encode($name) . '?=';
        }

        // A display name containing punctuation has to be quoted.
        return preg_match('/[,;:<>@"]/', $name) === 1
            ? '"' . str_replace('"', '\\"', $name) . '"'
            : $name;
    }

    /**
     * Normalises line endings to CRLF and removes the indentation a heredoc
     * leaves behind.
     */
    private static function normaliseBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // Wrap long lines: SMTP allows 998 characters, and some relays are
        // stricter still.
        $wrapped = wordwrap($body, 78, "\n", false);

        return str_replace("\n", "\r\n", $wrapped);
    }
}
