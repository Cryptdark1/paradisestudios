<?php

/**
 * Paradise Studios — payment return page.
 *
 * Where the customer lands after the payment provider redirects them back.
 * Handles BOTH the success URL and the cancel URL, because both arrive here
 * and both must be resolved by asking the server rather than by reading the
 * URL.
 *
 * THE ONE RULE
 * ------------
 * This page never decides whether money was taken.
 *
 * It POSTs to /api/payment-verify.php, which re-asks the payment provider
 * server-to-server and credits nothing unless the provider confirms the
 * amount, the currency and the reference. A redirect back to this page — or a
 * hand-edited URL — is not evidence of anything.
 *
 * The `status` query parameter the provider appends is deliberately IGNORED
 * for every message shown below. It selects nothing and proves nothing. Even
 * on a cancel URL the page still asks the server, because a customer who
 * reached the cancel screen may or may not have completed checkout, and only
 * the provider knows which.
 *
 * WHAT THE PAGE IS ALLOWED TO CLAIM
 * ---------------------------------
 * The wording of each state is tied to what the server actually returned, so
 * the page cannot state more than the backend can prove:
 *
 *   200 confirmed        -> "Payment confirmed"
 *   200 already_confirmed-> "This payment was already confirmed"
 *   409                  -> verification did not pass. This page makes no
 *                           claim about whether the provider moved money.
 *   404                  -> the reference is not on this account.
 *                           Says NOTHING about money, because the server said
 *                           nothing about money.
 *   401/403/429/500/502/503
 *                         -> inconclusive. A retry state, never a claim about
 *                           money, because no one asked the provider.
 *
 * Nothing here asserts escrow, funds held by a third party, or a payout. A
 * confirmed payment means the provider captured it; what happens to the money
 * afterwards is not this page's business.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/icons.php';

if (!defined('PS_BASE_URL')) {
    define('PS_BASE_URL', '/paradisestudios');
}

/*
 * Reuses the existing session handling. An expired session is redirected to
 * login.php by auth.php, exactly as it would be anywhere else in the product.
 * login.php is not modified by this page.
 */
requireLogin();

/*
 * api.js reads this meta tag and attaches the value as the X-CSRF-Token header
 * on every non-GET request. Without it payment-verify.php answers 403.
 */
$csrfToken = ps_csrf_token();

/*
 * The reference is checked for SHAPE only.
 *
 * It is a handle used to ask the server a question, not evidence. Validating
 * the format lets the page refuse a nonsense URL early and prevents an
 * arbitrarily long string being echoed back; it deliberately proves nothing
 * about whether the payment succeeded.
 */
$rawReference = isset($_GET['reference']) && is_string($_GET['reference'])
    ? trim($_GET['reference'])
    : '';

$reference = preg_match('/^ps_[0-9]{1,10}_[A-Za-z0-9_]{1,48}$/', $rawReference) === 1
    ? $rawReference
    : '';

$bookingsUrl = PS_BASE_URL . '/pages/artist/bookings.php';

?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <?php /* Read by public/js/api.js and sent as X-CSRF-Token on every POST. */ ?>
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <title>Payment status &middot; Paradise Studios</title>

    <script>
        /* Same pre-paint theme resolution as login.php, so returning from a
           payment does not flash the wrong theme. */
        (function () {
            var theme = 'light';

            try {
                var stored = window.localStorage.getItem('paradise-theme');

                if (stored === 'light' || stored === 'dark') {
                    theme = stored;
                } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    theme = 'dark';
                }
            } catch (err) {
                /* Private browsing: fall back to light. */
            }

            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/theme.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/app.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/auth.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/auth.css')) ?>">
    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/artist-theme.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/artist-theme.css')) ?>">
</head>

<body class="ps-auth ps-artist">

    <button type="button"
            class="ps-theme-toggle ps-auth__theme"
            data-theme-toggle
            data-next-theme="dark"
            aria-label="Switch theme"
            title="Switch theme">
        &#127769;
    </button>

    <main class="ps-auth__panel">

        <div class="ps-auth__aside" aria-hidden="true">
            <div class="ps-auth__brand">
                <span class="ps-brand__mark"><?= ps_icon('music', 20) ?></span>
                <span class="ps-brand__name">
                    <span>Paradise</span>
                    <span>Studios</span>
                </span>
            </div>
        </div>

        <div class="ps-auth__form">

            <div class="ps-auth__brand ps-auth__brand--mobile">
                <span class="ps-brand__mark"><?= ps_icon('music', 18) ?></span>
                <span class="ps-brand__name">
                    <span>Paradise</span>
                    <span>Studios</span>
                </span>
            </div>

            <!--
                aria-live="polite" so a screen reader announces the outcome once
                the server has answered, rather than silently swapping content.
            -->
            <div role="status" aria-live="polite" aria-busy="true" data-pay-result>

                <?php if ($reference === ''): ?>
                    <!--
                        No usable reference in the URL. This is NOT a statement
                        about money - there is nothing to ask the server about,
                        so nothing is claimed either way.
                    -->
                    <span class="ps-eyebrow">Payment</span>
                    <h1 class="ps-auth__title">Nothing to check</h1>
                    <p class="ps-auth__lead">
                        This page needs a payment reference to check a payment
                        with the provider, and the link did not contain one.
                    </p>

                    <a class="ps-btn ps-btn--primary" href="<?= e($bookingsUrl) ?>">
                        Go to my bookings
                    </a>
                <?php else: ?>
                    <!--
                        Initial "checking" state. Server-rendered so the page
                        says something useful before any JavaScript runs, then
                        replaced by the real outcome.
                    -->
                    <div data-pay-checking>
                        <span class="ps-eyebrow">Payment</span>
                        <h1 class="ps-auth__title">Checking your payment</h1>

                        <div class="ps-loading">
                            <span class="ps-spinner" aria-hidden="true"></span>
                            <span>Asking the payment provider&hellip;</span>
                        </div>

                        <p class="ps-auth__lead">
                            This can take a few seconds. Please stay on this page.
                        </p>
                    </div>
                <?php endif; ?>

            </div>

        </div>
    </main>

    <script src="<?= e(PS_BASE_URL) ?>/public/js/theme.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/theme.js')) ?>"></script>
    <script src="<?= e(PS_BASE_URL) ?>/public/js/api.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/api.js')) ?>"></script>
    <script src="<?= e(PS_BASE_URL) ?>/public/js/artist-api.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/artist-api.js')) ?>"></script>

    <script>
    (function () {
        "use strict";

        /*
         * The reference, validated shape-only by PHP. Server-rendered as a
         * JSON literal so it can never break out of the script context.
         */
        var REFERENCE = <?= json_encode($reference, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var BOOKINGS_URL = <?= json_encode($bookingsUrl) ?>;

        var region = document.querySelector("[data-pay-result]");
        var checking = document.querySelector("[data-pay-checking]");

        if (!REFERENCE || !region || !checking) {
            return;
        }

        region.setAttribute("aria-busy", "true");

        /**
         * Replace the region with an outcome.
         *
         * Every string passed here is either the server's own wording or is
         * deliberately silent about money. `tone` only changes styling.
         */
        function render(options) {
            var html =
                '<span class="ps-eyebrow">' + options.eyebrow + "</span>" +
                '<h1 class="ps-auth__title">' + options.title + "</h1>" +
                '<p class="ps-auth__lead">' + options.body + "</p>";

            if (options.amount) {
                html +=
                    '<div class="ps-auth__alert ps-auth__alert--info">' +
                    '<span>Amount recorded</span>' +
                    "<strong>" + options.amount + "</strong>" +
                    "</div>";
            }

            if (options.notice) {
                html +=
                    '<div class="ps-auth__alert ps-auth__alert--' + options.noticeTone + '">' +
                    "<span>" + options.notice + "</span>" +
                    "</div>";
            }

            html += '<div class="ps-row" style="justify-content:center;gap:10px;flex-wrap:wrap">';

            if (options.retry) {
                html +=
                    '<button type="button" class="ps-btn ps-btn--secondary" data-pay-retry>' +
                    "Try again</button>";
            }

            if (options.bookingId) {
                html +=
                    '<a class="ps-btn ps-btn--secondary" href="' +
                    BOOKINGS_URL + "?booking=" + encodeURIComponent(options.bookingId) +
                    '">View booking #' + options.bookingId + "</a>";
            }

            html +=
                '<a class="ps-btn ps-btn--primary" href="' + BOOKINGS_URL + '">' +
                "Go to my bookings</a>" +
                "</div>";

            region.innerHTML = html;
            region.setAttribute("aria-busy", "false");

            var retry = region.querySelector("[data-pay-retry]");

            if (retry) {
                retry.addEventListener("click", function () {
                    showChecking();
                    verify();
                });
            }
        }

        function showChecking() {
            checking.hidden = false;
            region.setAttribute("aria-busy", "true");
            region.innerHTML = checking.outerHTML;

            region = document.querySelector("[data-pay-result]");
            checking = document.querySelector("[data-pay-checking]");
            region.setAttribute("aria-busy", "true");
        }

        /**
         * Map a server answer onto exactly one honest outcome.
         *
         * `status` is the HTTP status. A verification failure does not prove
         * whether the provider moved money, so 409 must not imply that it did.
         */
        function describe(status, payload) {
            var data = (payload && payload.data) || {};
            var bookingId = data.booking_id || null;
            var amount = typeof data.amount_display === "string" ? data.amount_display : "";

            /* --- still deciding --------------------------------------- */

            /*
             * The provider answered but has not finished: Paystack reports
             * "processing" or "queued" for a card payment still settling.
             *
             * This is deliberately its own state, NOT folded into either the
             * confirmed or the not-confirmed branch. Saying "no money has been
             * taken" would be a claim the server did not make and cannot yet
             * support: the customer may or may not have been charged.
             *
             * api.js resolves any 2xx as success and does not pass the status
             * code on, so the `pending` flag in the payload is what identifies
             * this case. Checked BEFORE `confirmed`, so a settling response can
             * never render as a completed payment.
             */
            if (payload && payload.pending === true) {
                return {
                    eyebrow: "Payment",
                    title: "Payment is still processing",
                    body: "Your bank and the payment provider are still settling this "
                        + "payment. No decision has been made yet.",
                    notice: "This page cannot yet tell you whether the money has moved. "
                        + "Your bookings page shows the current status.",
                    noticeTone: "info",
                    retry: true
                };
            }

            /* --- confirmed ------------------------------------------- */

            if (status === 200 && data.confirmed === true) {
                if (data.already_confirmed === true) {
                    return {
                        eyebrow: "Payment",
                        title: "Already confirmed",
                        body: "This payment was already recorded as complete, so nothing further was charged.",
                        amount: amount,
                        bookingId: bookingId
                    };
                }

                return {
                    eyebrow: "Payment",
                    title: "Payment confirmed",
                    body: "The payment provider confirmed this payment.",
                    amount: amount,
                    bookingId: bookingId,
                    notice: "The studio owner is paid out separately after the session. "
                        + "No funds are held in escrow by anyone.",
                    noticeTone: "info"
                };
            }

            /* --- 409: the verification did not pass ------------------ */

            if (status === 409) {
                return {
                    eyebrow: "Payment",
                    title: "Not confirmed",
                    body: "We could not verify this payment.",
                    notice: "Check your bookings for the current status before trying again. "
                        + "If the status is still unclear, contact support.",
                    noticeTone: "info",
                    bookingId: bookingId,
                    retry: true
                };
            }

            /* --- 404: silent about money ----------------------------- */

            if (status === 404) {
                return {
                    eyebrow: "Payment",
                    title: "Payment not found",
                    body: "We could not find that payment on your account.",
                    notice: "If this looks wrong, check your bookings page for the current status.",
                    noticeTone: "info"
                };
            }

            /* --- 401: session --------------------------------------- */

            if (status === 401) {
                return {
                    eyebrow: "Payment",
                    title: "Session expired",
                    body: "Your session ended before this page could check your payment.",
                    notice: "Sign in again, then open the booking to see its current status.",
                    noticeTone: "info"
                };
            }

            /* --- 403: role or CSRF ---------------------------------- */

            if (status === 403) {
                return {
                    eyebrow: "Payment",
                    title: "Not permitted",
                    body: "This payment could not be checked from this account.",
                    notice: "Reload the page and try again, or check the booking itself.",
                    noticeTone: "info",
                    retry: true
                };
            }

            /* --- 400: malformed request ----------------------------- */

            if (status === 400) {
                return {
                    eyebrow: "Payment",
                    title: "Could not check",
                    body: "That payment reference was not valid.",
                    notice: "Open the booking from your bookings page to pay or review it.",
                    noticeTone: "info"
                };
            }

            /*
             * 429 rate limited, 502/503 provider or configuration problem,
             * 500 unexpected, 0 no network.
             *
             * In EVERY one of these the provider was never successfully asked,
             * so this page knows nothing about whether money moved. It says so
             * and offers a retry. Claiming "no money has been taken" here
             * would be an invention.
             */
            if (status === 429) {
                return {
                    eyebrow: "Payment",
                    title: "Too many checks",
                    body: "You have checked this payment several times in a row.",
                    notice: "Wait a few minutes, then try again.",
                    noticeTone: "info",
                    retry: true
                };
            }

            return {
                eyebrow: "Payment",
                title: "Could not confirm yet",
                body: "We could not reach the payment provider to check this payment.",
                notice: "Your booking page always shows the current status. "
                    + "You can try this check again in a moment.",
                noticeTone: "info",
                retry: true
            };
        }

        function verify() {
            PSPayments.verifyPayment(REFERENCE)
                .then(function (payload) {
                    render(describe(200, payload));
                })
                .catch(function (error) {
                    render(describe(error.status || 0, error.payload));
                });
        }

        showChecking();
        verify();
    })();
    </script>
</body>
</html>