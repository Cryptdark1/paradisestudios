<?php

/**
 * Paradise Studios - the verification email, in one place.
 *
 * Kept apart from Mailer so the transport can be swapped without touching the
 * content, and apart from EmailVerification so the code lifecycle knows nothing
 * about email.
 */

if (!defined('PS_VERIFICATION_MAILER_LOADED')) {
    define('PS_VERIFICATION_MAILER_LOADED', true);

    require_once __DIR__ . '/mailer.php';

    final class VerificationMailer
    {
        /**
         * Send the six-digit code.
         *
         * The caller passes the raw code straight through. Nothing here returns
         * it, and the subject line never contains it.
         *
         * @return array{sent:bool, transport:string, is_email_delivery:bool,
         *               reason:string, status_code:?int}
         */
        public static function sendCode(string $email, string $name, string $code, int $ttlMinutes): array
        {
            $body = self::body($name, $code, $ttlMinutes);

            /*
             * NOTE: no code in the subject. Subjects are the part most likely to
             * end up in a mail server log or a support screenshot.
             */
            return Mailer::send([
                'to' => $email,
                'toName' => $name,
                'subject' => 'Your Paradise Studios verification code',
                'body' => $body,
            ]);
        }

        /**
         * Was the code genuinely handed to an email provider?
         *
         * False for the local dev sink. Callers must use this, not `sent`, when
         * deciding whether to tell a user "we emailed you" - a file write is not
         * an email delivery.
         */
        public static function wasEmailed(array $result): bool
        {
            return (bool) ($result['sent'] ?? false)
                && (bool) ($result['is_email_delivery'] ?? false);
        }

        private static function body(string $name, string $code, int $ttlMinutes): string
        {
            $greeting = trim($name) !== '' ? 'Hi ' . trim($name) . ',' : 'Hello,';

            return $greeting . "\n\n"
                . "Your Paradise Studios verification code is:\n\n"
                . "    " . $code . "\n\n"
                . 'It expires in ' . $ttlMinutes . ' minutes. '
                . "If it expires before you use it, request a new one.\n\n"
                . "If you did not create a Paradise Studios account, you can "
                . "ignore this message.\n";
        }
    }
}