<?php

/**
 * Paradise Studios - outbound mail.
 *
 * Two transports, and the distinction between them is the whole point of this
 * file:
 *
 *   brevo     A real HTTPS call to Brevo's transactional API. This is a genuine
 *             email delivery attempt and the only one that produces `true` for
 *             is_email_delivery.
 *   dev_file  A local file that developers read instead of an inbox. This is
 *             NOT email delivery and never claims to be. It exists only so the
 *             verification flow can be exercised on a machine with no API key.
 *
 * SELECTION IS EXPLICIT, NEVER INFERRED
 * -------------------------------------
 * MAIL_DEV_FILE set  -> dev_file. That setting IS the opt-in; nothing else can
 *                      turn the dev sink on.
 * MAIL_DEV_FILE unset -> brevo, and if Brevo is misconfigured the send FAILS.
 *
 * There is deliberately NO fallback from brevo to dev_file. If Brevo rejects a
 * message or the key is missing, the caller gets a failure. Silently writing to
 * a local file instead would report success for a mail that was never sent and
 * would hide a broken production configuration until someone was locked out of
 * their account.
 *
 * NOTHING SENSITIVE IS EVER LOGGED
 * --------------------------------
 * The API key, the api-key header and the message body are never written to
 * error_log(). Only a reason code and the provider's own status code are. The
 * subject line carries no verification code, and the body is never logged at any
 * level, so a careless log level cannot leak an account takeover primitive.
 */

if (!defined('PS_MAILER_LOADED')) {
    define('PS_MAILER_LOADED', true);

    require_once __DIR__ . '/../config/env.php';

    final class PsMailerException extends RuntimeException
    {
    }

    final class Mailer
    {
        /** Brevo's documented transactional send endpoint. */
        private const BREVO_SEND_PATH = '/v3/smtp/email';

        /** Warn about the dev sink at most once per process. */
        private static bool $devWarningLogged = false;

        /**
         * Send one message.
         *
         * @param array{to:string, subject:string, body:string, toName?:string} $message
         * @return array{
         *     sent:bool,
         *     transport:string,
         *     is_email_delivery:bool,
         *     reason:string,
         *     status_code:?int
         * }
         */
        public static function send(array $message): array
        {
            $to = trim((string) ($message['to'] ?? ''));
            $toName = trim((string) ($message['toName'] ?? ''));

            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                return self::failure('invalid_recipient', 'Recipient address is not valid.');
            }

            $subject = trim((string) ($message['subject'] ?? ''));
            $body = (string) ($message['body'] ?? '');

            if ($subject === '' || $body === '') {
                return self::failure('invalid_message', 'Subject and body are required.');
            }

            /*
             * EXPLICIT OPT-IN. The presence of MAIL_DEV_FILE is the only thing
             * that selects the development sink.
             */
            $sink = self::devSinkPath();

            if ($sink !== null) {
                return self::sendToDevSink($to, $subject, $body, $sink);
            }

            return self::sendViaBrevo($to, $toName, $subject, $body);
        }

        /* =============================================================
           Transport selection
           ============================================================= */

        /**
         * Which transport a send would use right now.
         *
         * @return 'dev_file'|'brevo'|'unconfigured'
         */
        public static function transportMode(): string
        {
            if (self::devSinkPath() !== null) {
                return 'dev_file';
            }

            return self::brevoConfigurationError() === null ? 'brevo' : 'unconfigured';
        }

        /**
         * True when mail will actually go somewhere.
         */
        public static function isConfigured(): bool
        {
            return self::transportMode() !== 'unconfigured';
        }

        /**
         * What Brevo needs, and why it is not satisfied. Null means ready.
         *
         * Returns a short operator-facing reason. Never includes the key.
         */
        public static function brevoConfigurationError(): ?string
        {
            if (!function_exists('curl_init')) {
                return 'PHP cURL is not available, so the Brevo API cannot be called.';
            }

            if (self::brevoApiKey() === '') {
                return 'BREVO_API_KEY is not set.';
            }

            if (filter_var(self::fromAddress(), FILTER_VALIDATE_EMAIL) === false) {
                return 'MAIL_FROM_ADDRESS is missing or not a valid address.';
            }

            $base = self::brevoApiBase();

            if (!self::isTrustworthyApiBase($base)) {
                return 'BREVO_API_BASE must be an https:// URL.';
            }

            return null;
        }

        /**
         * May the API base be used?
         *
         * https is required, because the api-key header and every message body
         * would otherwise cross the network in plaintext. The single exception
         * is a loopback host: a request to 127.0.0.1 or ::1 never leaves the
         * machine, so plaintext there exposes nothing to a third party. That is
         * what lets the transport be tested against a local test double without
         * weakening the production path.
         *
         * Anything else - a real hostname over http, an unknown scheme, a URL
         * that does not parse - is refused.
         */
        private static function isTrustworthyApiBase(string $base): bool
        {
            if (filter_var($base, FILTER_VALIDATE_URL) === false) {
                return false;
            }

            $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
            $host = strtolower((string) parse_url($base, PHP_URL_HOST));

            if ($scheme === 'https') {
                return true;
            }

            return $scheme === 'http' && in_array(
                $host,
                ['127.0.0.1', '::1', '[::1]', 'localhost'],
                true
            );
        }

        /**
         * Operator-facing configuration state. Safe to print: no value is ever
         * returned, only whether it is present.
         *
         * @return array<string,bool|string>
         */
        public static function configurationReport(): array
        {
            return [
                'transport' => self::transportMode(),
                'curl_available' => function_exists('curl_init'),
                'BREVO_API_KEY' => self::brevoApiKey() !== '',
                'MAIL_FROM_ADDRESS' => filter_var(self::fromAddress(), FILTER_VALIDATE_EMAIL) !== false,
                'MAIL_FROM_NAME' => self::fromName() !== '',
                'MAIL_DEV_FILE' => self::devSinkPath() !== null,
                'brevo_ready' => self::brevoConfigurationError() === null,
                'problem' => self::brevoConfigurationError() ?? '',
            ];
        }

        /* =============================================================
           Brevo
           ============================================================= */

        /**
         * POST the message to Brevo's transactional API.
         *
         * Every failure is returned, never thrown, so the caller decides what to
         * tell the user.
         */
        private static function sendViaBrevo(
            string $to,
            string $toName,
            string $subject,
            string $body
        ): array {
            $configurationError = self::brevoConfigurationError();

            if ($configurationError !== null) {
                /*
                 * NOT a fallback. Brevo was selected, so failing to reach it is
                 * a failure, and it must never quietly become a file write.
                 */
                error_log('[paradisestudios] Brevo mail not sent: ' . $configurationError);

                return self::failure('not_configured', $configurationError);
            }

            $url = rtrim(self::brevoApiBase(), '/') . self::BREVO_SEND_PATH;

            /*
             * Brevo v3 /smtp/email payload. textContent is sent alongside
             * htmlContent so the message is readable in clients that refuse
             * HTML.
             */
            $payload = [
                'sender' => [
                    'name' => self::fromName(),
                    'email' => self::fromAddress(),
                ],
                'to' => [
                    array_filter([
                        'email' => $to,
                        'name' => $toName !== '' ? $toName : null,
                    ], static fn ($value) => $value !== null),
                ],
                'subject' => $subject,
                'textContent' => str_replace(["\r\n", "\r"], "\n", $body),
                'htmlContent' => self::toHtml($subject, $body),
            ];

            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                return self::failure('encode_failed', 'The message could not be encoded.');
            }

            $curl = curl_init($url);

            if ($curl === false) {
                return self::failure('transport_unavailable', 'Could not initialise an HTTP client.');
            }

            /*
             * The api-key header carries the secret. It is passed straight to
             * cURL and never stored in a variable that could be logged, never
             * echoed, and never included in an error message.
             */
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encoded,
                CURLOPT_HTTPHEADER => [
                    'api-key: ' . self::brevoApiKey(),
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $response = curl_exec($curl);
            $curlErrorNumber = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            if ($curlErrorNumber !== 0 || $response === false) {
                /*
                 * curl_error() can contain a URL but never the api-key header,
                 * and only the numeric code is kept for the log line.
                 */
                error_log(
                    '[paradisestudios] Brevo mail transport error (curl ' . $curlErrorNumber . ').'
                );

                return self::failure(
                    'transport_error',
                    'Could not reach the email provider.',
                    null
                );
            }

            /*
             * Brevo answers 201 Created on acceptance. Anything else is a
             * refusal, including a 200 that carries an error body.
             */
            if ($status === 201) {
                return [
                    'sent' => true,
                    'transport' => 'brevo',
                    'is_email_delivery' => true,
                    'reason' => '',
                    'status_code' => $status,
                ];
            }

            $providerMessage = self::providerMessage((string) $response);

            /*
             * Status only. The provider's message is returned to the caller for
             * logging decisions but is never logged here in full, because a
             * provider can echo request content back.
             */
            error_log(
                '[paradisestudios] Brevo rejected a mail with HTTP '
                . $status . ($providerMessage !== '' ? ' (' . $providerMessage . ')' : '')
            );

            return self::failure(
                self::reasonForStatus($status),
                $providerMessage !== ''
                    ? 'The email provider rejected the message: ' . $providerMessage
                    : 'The email provider rejected the message (HTTP ' . $status . ').',
                $status
            );
        }

        /** A short, safe reason code for the caller. */
        private static function reasonForStatus(int $status): string
        {
            return match ($status) {
                400 => 'provider_rejected_request',
                401 => 'provider_unauthorized',
                403 => 'provider_forbidden',
                404 => 'provider_endpoint_missing',
                429 => 'provider_rate_limited',
                default => $status >= 500 ? 'provider_unavailable' : 'provider_error',
            };
        }

        /**
         * Pull a human-readable message out of a Brevo error body.
         *
         * Only the `message` and `code` fields are read. The whole body is never
         * returned, because a provider can echo request content back and this
         * value ends up in a log line.
         */
        private static function providerMessage(string $body): string
        {
            if ($body === '' || strlen($body) > 2048) {
                return '';
            }

            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                return '';
            }

            $message = isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : '';

            $code = isset($decoded['code']) && is_string($decoded['code'])
                ? $decoded['code']
                : '';

            $parts = [];

            if ($message !== '') {
                $parts[] = $message;
            }

            if ($code !== '' && !str_contains($message, $code)) {
                $parts[] = $code;
            }

            $joined = implode(' / ', $parts);

            /* Keep it short so a log line stays readable. */
            return substr(preg_replace('/\s+/', ' ', $joined) ?? '', 0, 200);
        }

        /** A plain, dependency-free HTML body for clients that want one. */
        private static function toHtml(string $subject, string $body): string
        {
            $escapedSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
            $escapedBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

            return '<div style="font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
                . 'font-size:15px;line-height:1.6;color:#191f2e">'
                . '<h2 style="margin:0 0 16px">' . $escapedSubject . '</h2>'
                . $escapedBody
                . '<p style="margin-top:24px;color:#6a7383;font-size:13px">Paradise Studios</p>'
                . '</div>';
        }

        /* =============================================================
           Local development sink
           ============================================================= */

        /**
         * Where the dev sink writes, if one is configured and safe.
         *
         * Null when no sink is configured OR the path is unsafe, so a bad
         * setting degrades to "no sink" rather than to "write verification
         * codes into the document root".
         */
        public static function devSinkPath(): ?string
        {
            $path = trim((string) ps_env('MAIL_DEV_FILE', ''));

            if ($path === '') {
                return null;
            }

            $documentRoot = strtolower((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

            if ($documentRoot !== '') {
                $documentRoot = rtrim(strtolower($documentRoot), '/') . '/';

                if (str_starts_with(strtolower($path), $documentRoot)) {
                    error_log(
                        '[paradisestudios] MAIL_DEV_FILE points inside the web root and was REFUSED.'
                    );

                    return null;
                }
            }

            return $path;
        }

        /**
         * Append to the dev sink.
         *
         * Reported as transport 'dev_file' with is_email_delivery FALSE. This
         * is a local file write and the caller must never describe it as a sent
         * email.
         */
        private static function sendToDevSink(
            string $to,
            string $subject,
            string $body,
            string $path
        ): array {
            if (!self::$devWarningLogged) {
                self::$devWarningLogged = true;

                error_log(
                    '[paradisestudios] MAIL_DEV_FILE is set: mail is being written to a LOCAL FILE. '
                    . 'This is NOT email delivery and Brevo is not being used.'
                );
            }

            $directory = dirname($path);

            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                return self::failure('dev_sink_unavailable', 'Dev mail directory is not writable.');
            }

            $entry = str_repeat('=', 62) . "\n"
                . 'To:      ' . $to . "\n"
                . 'Subject: ' . $subject . "\n"
                . 'At:      ' . gmdate('Y-m-d H:i:s') . " UTC\n"
                . str_repeat('-', 62) . "\n"
                . $body . "\n\n";

            if (@file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
                return self::failure('dev_sink_unavailable', 'Dev mail file is not writable.');
            }

            @chmod($path, 0600);

            return [
                'sent' => true,
                'transport' => 'dev_file',
                /*
                 * False on purpose. The code reached a file, not an inbox.
                 */
                'is_email_delivery' => false,
                'reason' => '',
                'status_code' => null,
            ];
        }

        /* =============================================================
           Internals
           ============================================================= */

        /**
         * @return array{sent:bool, transport:string, is_email_delivery:bool, reason:string, status_code:?int}
         */
        private static function failure(string $reason, string $message, ?int $statusCode = null): array
        {
            return [
                'sent' => false,
                'transport' => 'none',
                'is_email_delivery' => false,
                'reason' => $reason . ': ' . $message,
                'status_code' => $statusCode,
            ];
        }

        private static function brevoApiKey(): string
        {
            return trim((string) ps_env('BREVO_API_KEY', ''));
        }

        private static function brevoApiBase(): string
        {
            $base = trim((string) ps_env('BREVO_API_BASE', 'https://api.brevo.com'));

            return $base !== '' ? $base : 'https://api.brevo.com';
        }

        private static function fromAddress(): string
        {
            return trim((string) ps_env('MAIL_FROM_ADDRESS', ''));
        }

        private static function fromName(): string
        {
            return trim((string) ps_env('MAIL_FROM_NAME', 'Paradise Studios'));
        }
    }
}