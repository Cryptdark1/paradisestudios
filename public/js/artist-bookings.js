/**
 * Paradise Studios — My Bookings.
 *
 * Endpoints used:
 *   GET  /api/my-bookings.php
 *   GET  /api/booking-details.php?id=
 *   PUT  /api/cancel-booking.php?id=
 *   PUT  /api/reschedule-booking.php?id=
 *   PUT  /api/complete-booking.php?id=
 *   POST /api/payment-initialize.php   (via PSPayments)
 *
 * PAYMENT
 * -------
 * Booking state and payment state are separate, and both are shown. `status` is
 * the session (pending / escrow_held / completed / cancelled). `payment_state` is
 * the money (unpaid / pending / paid / failed / refunded / ...), reported by the
 * server on every booking row.
 *
 * The Pay button appears only where PaymentService::loadPayableBooking() would
 * accept the call. Those rules live on the server and are mirrored below so the
 * UI does not offer something that would be refused. The mirroring is only ever
 * MORE restrictive: where the two might disagree the button stays hidden, and
 * the server always has the final say.
 *
 * Nothing here marks a booking paid. This file never reads a query parameter as
 * evidence of payment and never computes an authoritative amount. When the
 * customer returns from the provider, payment-return.php asks the server; this
 * page simply re-reads the server's answer on the next load.
 */
(function (global) {
    "use strict";

    var API = global.ArtistAPI;
    var UI = global.UI;
    var A = global.ArtistUI;
    var escape = UI.escape;

    /* Studio opening hours, mirroring the booking form. */
    var OPEN_HOUR = 8;
    var CLOSE_HOUR = 22;

    var state = {
        bookings: null,
        tab: "all",
        query: "",
        busy: false
    };

    var els = {};
    var detailModal = null;
    var rescheduleModal = null;

    /* Active booking for the reschedule form. */
    var rescheduleTarget = null;
    var rescheduleStart = null;

    /* ---------------------------------------------------------------------
       Payment
       --------------------------------------------------------------------- */

    /*
     * payment_state -> presentation and eligibility.
     *
     * The values are exactly those of the bookings.payment_state ENUM. An
     * unknown value falls back to a neutral "unknown" badge and, crucially, to
     * NOT payable: if the server ever grows a state this file has not seen,
     * the safe response is to hide the Pay button rather than offer it.
     */
    var PAYMENT_MAP = {
        unpaid:             { label: "Unpaid", tone: "warning", icon: "warning" },
        pending:            { label: "Payment in progress", tone: "info", icon: "clock" },
        paid:               { label: "Paid", tone: "success", icon: "ticket" },
        failed:             { label: "Payment failed", tone: "danger", icon: "alert" },
        expired:            { label: "Payment expired", tone: "warning", icon: "clock" },
        partially_refunded: { label: "Partly refunded", tone: "info", icon: "info" },
        refunded:           { label: "Refunded", tone: "neutral", icon: "minus" },
        legacy_unverified:  { label: "Payment not recorded", tone: "neutral", icon: "info" }
    };

    /* Session states the server refuses to take money for. */
    var UNPAYABLE_SESSION_STATES = ["cancelled", "completed", "disputed"];

    /*
     * payment_state values this file will offer Pay for.
     *
     * Deliberately excludes paid, partially_refunded, refunded and
     * legacy_unverified. The server's own guard only rejects `paid` and
     * `legacy_unverified`, so `partially_refunded` would technically pass and
     * could start a SECOND full-amount payment for a booking that was already
     * partly charged. That is a backend question, not a frontend one - but
     * hiding the button costs nothing and removes the risk in the meantime.
     */
    var PAYABLE_PAYMENT_STATES = ["unpaid", "failed", "expired", "pending"];

    function paymentMeta(booking) {
        var key = String((booking && booking.payment_state) || "").toLowerCase();

        return (
            PAYMENT_MAP[key] || {
                label: key ? key.replace(/_/g, " ") : "Unknown",
                tone: "neutral",
                icon: "info"
            }
        );
    }

    /**
     * Payment badge. Same .ps-badge classes as the session badge, so the two
     * read as one system without changing the shared status map.
     */
    function paymentBadge(booking) {
        var meta = paymentMeta(booking);

        return (
            '<span class="ps-badge ps-badge--' +
            meta.tone +
            '" data-payment-state="' +
            escape(String((booking && booking.payment_state) || "unknown")) +
            '">' +
            UI.icon(meta.icon, 12) +
            '<span class="ps-badge__label">' +
            escape(meta.label) +
            "</span>" +
            "</span>"
        );
    }

    /** Is the checkout window for a pending payment still open? */
    function checkoutWindowOpen(booking) {
        var expiresAt = booking && booking.payment_expires_at;

        if (!expiresAt) {
            return false;
        }

        var expiry = Date.parse(String(expiresAt).replace(" ", "T") + "Z");

        /* An unparseable expiry is treated as closed, so the button appears
           rather than leaving the artist stuck. The server still decides. */
        return isNaN(expiry) ? false : expiry > Date.now();
    }

    /**
     * May this booking be paid for, according to the server's rules?
     *
     * Mirrors loadPayableBooking():
     *   - an amount above zero
     *   - a session that is not cancelled, completed or disputed
     *   - a payment_state that is not paid or legacy_unverified
     *
     * Plus the stricter exclusions described on PAYABLE_PAYMENT_STATES.
     */
    function canPay(booking) {
        if (!booking) {
            return false;
        }

        var status = String(booking.status || "").toLowerCase();

        if (UNPAYABLE_SESSION_STATES.indexOf(status) !== -1) {
            return false;
        }

        var state = String(booking.payment_state || "").toLowerCase();

        if (PAYABLE_PAYMENT_STATES.indexOf(state) === -1) {
            return false;
        }

        return UI.number(booking.total_amount_minor) > 0;
    }

    /** A pending payment whose window has lapsed should read as payable again. */
    function payButtonLabel(booking) {
        var state = String(booking.payment_state || "").toLowerCase();

        if (state === "pending") {
            return checkoutWindowOpen(booking)
                ? "Continue payment"
                : "Pay again";
        }

        if (state === "failed" || state === "expired") {
            return "Pay again";
        }

        return "Pay Now";
    }

    /* ---------------------------------------------------------------------
       Filtering
       --------------------------------------------------------------------- */

    function visibleBookings() {
        var buckets = A.bucketBookings(state.bookings || []);
        var rows;

        if (state.tab === "upcoming") {
            rows = buckets.upcoming;
        } else if (state.tab === "past") {
            rows = buckets.past;
        } else if (state.tab === "cancelled") {
            rows = buckets.cancelled;
        } else {
            rows = (state.bookings || []).slice();
        }

        var query = state.query.trim().toLowerCase();

        if (query) {
            rows = rows.filter(function (booking) {
                return (
                    String(booking.studio_name || "").toLowerCase().indexOf(query) !== -1 ||
                    String(booking.studio_location || "").toLowerCase().indexOf(query) !== -1 ||
                    String(booking.id).indexOf(query) !== -1
                );
            });
        }

        return rows;
    }

    /* ---------------------------------------------------------------------
       Row rendering
       --------------------------------------------------------------------- */

    /** Buttons allowed for a given status, mirroring the backend rules. */
    function actionsFor(booking) {
        var status = String(booking.status).toLowerCase();
        var id = UI.number(booking.id);
        var buttons = "";

        if (status === "escrow_held") {
            buttons +=
                '<button type="button" class="ps-btn ps-btn--secondary ps-btn--sm" ' +
                'data-action="view" data-booking-id="' +
                id +
                '">View</button>' +
                '<button type="button" class="ps-btn ps-btn--ghost ps-btn--sm" ' +
                'data-action="reschedule" data-booking-id="' +
                id +
                '" data-ps-tip="Change the time">Reschedule</button>' +
                '<button type="button" class="ps-btn ps-btn--ghost ps-btn--sm" ' +
                'data-action="cancel" data-booking-id="' +
                id +
                '">Cancel</button>' +
                '<button type="button" class="ps-btn ps-btn--primary ps-btn--sm" ' +
                'data-action="complete" data-booking-id="' +
                id +
                '" data-ps-tip="Marks the session finished">Complete</button>';
        } else {
            buttons +=
                '<button type="button" class="ps-btn ps-btn--secondary ps-btn--sm" ' +
                'data-action="view" data-booking-id="' +
                id +
                '">View</button>';
        }

        /*
         * Pay button.
         *
         * Added alongside the existing rules rather than inside them, so the
         * behaviour for an already-paid escrow_held booking is completely
         * unchanged - canPay() is false for a paid booking, so nothing is added
         * there.
         *
         * Rendered first when the session is not escrow_held, because paying is
         * what the artist has to do next for an unpaid booking; Complete is
         * refused by the server until a payment is confirmed.
         */
        if (canPay(booking)) {
            buttons =
                '<button type="button" class="ps-btn ps-btn--primary ps-btn--sm" ' +
                'data-action="pay" data-booking-id="' +
                id +
                '">' +
                escape(payButtonLabel(booking)) +
                "</button>" +
                buttons;
        }

        /*
         * An unpaid booking must also be cancellable. cancel-booking.php
         * accepts payment_state unpaid / failed / expired, but the escrow_held
         * branch above does not match a 'pending' session, so without this an
         * unpaid booking would offer neither payment nor cancellation.
         */
        var state = String(booking.payment_state || "").toLowerCase();

        if (
            status !== "escrow_held" &&
            ["unpaid", "failed", "expired"].indexOf(state) !== -1
        ) {
            buttons +=
                '<button type="button" class="ps-btn ps-btn--ghost ps-btn--sm" ' +
                'data-action="cancel" data-booking-id="' +
                id +
                '">Cancel</button>';
        }

        return buttons;
    }

    function rowMarkup(booking) {
        var started = UI.parseDate(booking.start_time);
        var ended = UI.parseDate(booking.end_time);
        var status = String(booking.status).toLowerCase();
        var id = UI.number(booking.id);

        return (
            "<tr>" +
            '<td data-label="Studio">' +
            '<span class="ps-stateline__who">' +
            '<span class="ps-avatar ps-avatar--sm" aria-hidden="true">' +
            escape(UI.initials(booking.studio_name)) +
            "</span>" +
            '<span class="ps-stateline__who-text">' +
            "<strong>" +
            escape(booking.studio_name || "Studio") +
            "</strong>" +
            "<small>" +
            escape(booking.studio_location || "") +
            "</small>" +
            "</span>" +
            "</span>" +
            "</td>" +
            '<td class="num" data-label="Date">' +
            escape(started ? UI.date(booking.start_time) : "—") +
            (status !== "cancelled" && started
                ? "<small>" + escape(UI.friendlyDate(booking.start_time)) + "</small>"
                : "") +
            "</td>" +
            '<td class="num" data-label="Time">' +
            escape(started ? UI.time(booking.start_time) : "—") +
            (ended ? " – " + escape(UI.time(booking.end_time)) : "") +
            "</td>" +
            '<td class="num" data-label="Total">' +
            escape(UI.money(booking.total_amount)) +
            "</td>" +
            '<td data-label="Status">' +
            UI.statusBadge(booking.status) +
            "</td>" +
            '<td data-label="Payment">' +
            paymentBadge(booking) +
            "</td>" +
            '<td class="actions" data-label="Actions">' +
            '<span class="ps-bkactions">' +
            actionsFor(booking) +
            "</span>" +
            "</td>" +
            "</tr>" +
            '<tr class="ps-refunder" data-refunder="' +
            id +
            '" hidden><td colspan="7">' +
            '<div class="ps-refund-note">' +
            UI.icon("info", 16) +
            "<span>" +
            escape(state.refundNote || "") +
            "</span>" +
            "</div>" +
            "</td></tr>"
        );
    }

    /* ---------------------------------------------------------------------
       List + summary
       --------------------------------------------------------------------- */

    function render() {
        if (!els.rows || !state.bookings) {
            return;
        }

        els.rows.setAttribute("aria-busy", "false");

        var rows = visibleBookings();

        if (!rows.length) {
            var filtered = state.query || state.tab !== "all";

            els.rows.innerHTML =
                '<tr><td colspan="7">' +
                UI.stateHtml({
                    icon: filtered ? "search" : "calendar",
                    title: filtered ? "No bookings match" : "No bookings yet",
                    message: filtered
                        ? "Try a different tab or search term."
                        : "Find a studio and book your first session.",
                    compact: true,
                    actionHref: filtered
                        ? null
                        : global.location.pathname.replace(/[^/]*$/, "studios.php"),
                    actionLabel: filtered ? "Clear filters" : "Discover studios"
                }) +
                "</td></tr>";

            var btn = UI.qs("[data-state-action]", els.rows);

            if (btn) {
                btn.addEventListener("click", function () {
                    if (filtered) {
                        state.query = "";
                        state.tab = "all";

                        if (els.search) {
                            els.search.value = "";
                        }

                        syncTabs();
                        render();
                    }
                });
            }

            renderSummary(rows);
            return;
        }

        els.rows.innerHTML = rows.map(rowMarkup).join("");
        renderSummary(rows);
    }

    function renderSummary(rows) {
        if (!els.summary || !state.bookings) {
            return;
        }

        var buckets = A.bucketBookings(state.bookings);

        var parts = [
            buckets.upcoming.length + " upcoming",
            buckets.past.length + " past"
        ];

        if (buckets.cancelled.length) {
            parts.push(buckets.cancelled.length + " cancelled");
        }

        var spent = state.bookings.reduce(function (total, booking) {
            var status = String(booking.status).toLowerCase();

            /* Cancelled sessions were never completed, so they are excluded
               from the "booked" spend figure. */
            if (status === "cancelled") {
                return total;
            }

            return total + UI.number(booking.total_amount);
        }, 0);

        if (spent > 0) {
            parts.push(UI.money(spent) + " booked");
        }

        els.summary.textContent =
            (rows.length === state.bookings.length
                ? state.bookings.length + " total bookings"
                : "Showing " + rows.length + " of " + state.bookings.length) +
            " · " +
            parts.join(" · ") +
            ".";
    }

    function renderError(error) {
        if (!els.rows) {
            return;
        }

        els.rows.setAttribute("aria-busy", "false");
        UI.renderError(els.rows, error, loadBookings);

        if (els.summary) {
            els.summary.textContent = "Bookings could not be loaded.";
        }
    }

    /* ---------------------------------------------------------------------
       Detail dialog
       --------------------------------------------------------------------- */

    function bookingById(id) {
        return (state.bookings || []).filter(function (booking) {
            return UI.number(booking.id) === id;
        })[0];
    }

    function openDetail(id, trigger) {
        var cached = bookingById(id);
        var body = UI.qs("[data-modal-body]");
        var actions = UI.qs("[data-modal-actions]");

        detailModal.open(trigger);

        setHtml(
            actions,
            cached
                ? '<span class="ps-bkactions">' + actionsFor(cached) + "</span>"
                : ""
        );

        body.innerHTML =
            '<div class="ps-loading"><span class="ps-spinner" aria-hidden="true"></span>' +
            "<span>Loading booking&hellip;</span></div>";

        UI.qs("[data-modal-subtitle]").textContent =
            cached ? "Booking #" + UI.number(cached.id) : "";

        API.bookingDetails(id)
            .then(function (payload) {
                renderDetail(payload.data || {});
            })
            .catch(function (error) {
                UI.renderError(body, error, function () {
                    openDetail(id);
                });
            });
    }

    function renderDetail(booking) {
        var started = UI.parseDate(booking.start_time);
        var ended = UI.parseDate(booking.end_time);
        var status = String(booking.status).toLowerCase();
        var hours = started && ended
            ? Math.max(1, Math.round((ended - started) / 3600000))
            : 0;

        UI.qs("[data-modal-subtitle]").textContent = "Booking #" + UI.number(booking.id);

        setHtml(
            UI.qs("[data-modal-body]"),
            '<div class="ps-bkdetail__studio">' +
            '<span class="ps-avatar ps-avatar--artist" aria-hidden="true">' +
            escape(UI.initials(booking.studio_name)) +
            "</span>" +
            '<span class="ps-bkdetail__text">' +
            '<span class="ps-bkdetail__name">' +
            escape(booking.studio_name || "Studio") +
            "</span>" +
            '<span class="ps-bkdetail__where">' +
            escape(
                [booking.studio_location, booking.studio_address]
                    .filter(Boolean)
                    .join(" · ") || "Location not provided"
            ) +
            "</span>" +
            "</span>" +
            "</div>" +
            '<dl class="ps-dl">' +
            row("Booking reference", "#" + escape(UI.number(booking.id))) +
            row("Date", escape(UI.date(booking.start_time)) + (started ? " (" + escape(UI.friendlyDate(booking.start_time)) + ")" : "")) +
            row("Start time", escape(UI.time(booking.start_time))) +
            row("End time", ended ? escape(UI.time(booking.end_time)) : "—") +
            (hours ? row("Duration", hours + (hours === 1 ? " hour" : " hours")) : "") +
            row("Hourly rate", escape(UI.rate(booking.hourly_rate))) +
            row("Booking status", UI.statusBadge(booking.status)) +
            row("Payment status", paymentBadge(booking)) +
            row("Booked on", escape(UI.dateTime(booking.created_at))) +
            "</dl>" +
            '<div class="ps-bkdetail__amount">' +
            '<span class="ps-bkdetail__amount-label">' +
            (canPay(booking) ? "Amount to pay" : "Total for this session") +
            "<small>" +
            (canPay(booking)
                ? "Calculated by the server from the studio's hourly rate"
                : "Confirmed by the server when the booking was created") +
            "</small>" +
            "</span>" +
            '<span class="ps-bkdetail__amount-value">' +
            escape(UI.money(booking.total_amount)) +
            "</span>" +
            "</div>" +
            paymentNoticeHtml(booking) +
            (status === "escrow_held"
                ? '<div class="ps-refund-note">' +
                  UI.icon("info", 16) +
                  "<span>Cancelling releases this hold. The amount returned depends on how close the " +
                  "session is, and is calculated by the server.</span>" +
                  "</div>"
                : "")
        );

        setHtml(
            UI.qs("[data-modal-actions]"),
            '<span class="ps-bkactions">' + actionsFor(booking) + "</span>"
        );
    }

    function row(label, value) {
        return (
            '<div class="ps-dl__row"><dt>' +
            escape(label) +
            "</dt><dd>" +
            value +
            "</dd></div>"
        );
    }

    /**
     * A short, honest explanation under the amount in the detail dialog.
     *
     * Wording is chosen per payment_state and says only what the server's state
     * supports. In particular it never claims a payment succeeded, and never
     * claims funds are held by anyone.
     */
    function paymentNoticeHtml(booking) {
        var state = String(booking.payment_state || "").toLowerCase();
        var text = "";

        switch (state) {
            case "unpaid":
                text = "This session has not been paid for yet. "
                    + "Pay now to confirm the booking.";
                break;

            case "pending":
                text = checkoutWindowOpen(booking)
                    ? "A payment for this session is in progress. "
                        + "Continue to finish it, or start again."
                    : "The previous checkout was not completed. "
                        + "You can pay again for this booking.";
                break;

            case "paid":
                text = "The payment provider confirmed this payment. "
                    + "Nothing is held in escrow by anyone, and no payout is automatic.";
                break;

            case "failed":
            case "expired":
                text = "The last payment attempt did not complete. "
                    + "You can pay again for this booking.";
                break;

            case "partially_refunded":
                text = "Part of this payment was returned to you.";
                break;

            case "refunded":
                text = "This payment was returned to you in full.";
                break;

            case "legacy_unverified":
                text = "This booking predates online payments, so no payment record exists for it.";
                break;

            default:
                return "";
        }

        return (
            '<div class="ps-refund-note">' +
            UI.icon("info", 16) +
            "<span>" +
            escape(text) +
            "</span>" +
            "</div>"
        );
    }

    /* ---------------------------------------------------------------------
       Pay
       --------------------------------------------------------------------- */

    /*
     * Bookings with a payment request already in flight.
     *
     * Deliberately per booking rather than one page-wide flag: paying booking
     * A must not disable booking B. A plain object avoids Set for older
     * browsers, which this codebase still supports elsewhere.
     */
    var paying = {};

    /**
     * Only ever navigate to a plain http(s) URL.
     *
     * The server returns the authorization URL, but a redirect target is
     * worth validating anyway: a `javascript:` value would turn this function
     * into a code-execution sink. Cheap insurance against a bug or a
     * compromised response.
     */
    function isSafeRedirect(url) {
        if (typeof url !== "string" || url === "") {
            return false;
        }

        try {
            var parsed = new URL(url, global.location.href);

            return parsed.protocol === "http:" || parsed.protocol === "https:";
        } catch (err) {
            return false;
        }
    }

    /** Turn a failed payment-initialize into something a person can act on. */
    function payErrorMessage(error) {
        var status = error && error.status;

        switch (status) {
            case 0:
                return {
                    title: "Cannot reach the server",
                    body: "Check your connection and try again.",
                    tone: "error"
                };

            case 400:
                /* The server refused because the booking is not payable.
                   Its message is specific and safe to show. */
                return {
                    title: "This booking cannot be paid",
                    body: (error && error.message) || "The booking is not in a payable state.",
                    tone: "warning"
                };

            case 401:
                return {
                    title: "Session expired",
                    body: "Sign in again, then start the payment from your bookings.",
                    tone: "error"
                };

            case 403:
                return {
                    title: "Not permitted",
                    body: "This payment could not be started from your account.",
                    tone: "error"
                };

            case 404:
                return {
                    title: "Booking not found",
                    body: "This booking is not available on your account.",
                    tone: "error"
                };

            case 409:
                return {
                    title: "Already paid",
                    body: "This booking has already been paid for. Your list has been refreshed.",
                    tone: "warning"
                };

            case 429:
                return {
                    title: "Too many attempts",
                    body: "You have started several payments in a row. Wait a few minutes and try again.",
                    tone: "warning"
                };

            case 502:
            case 503:
                return {
                    title: "Payments unavailable",
                    body: "The payment provider could not be reached. Try again shortly.",
                    tone: "error"
                };

            default:
                return {
                    title: "Payment could not start",
                    body: (error && error.message) || "Something went wrong. Please try again.",
                    tone: "error"
                };
        }
    }

    function payBooking(id, trigger) {
        var booking = bookingById(id);

        if (!booking) {
            return;
        }

        /*
         * Duplicate-submission guard.
         *
         * Two layers: the flag rejects a second click before any request is
         * made, and the disabled+loading button stops the visual affordance.
         * The server is idempotent about CONFIRMATION but a second
         * initialise would create a second pending payment row, so the click
         * itself must not get through twice.
         */
        if (paying[id]) {
            return;
        }

        paying[id] = true;

        if (trigger) {
            UI.setButtonLoading(trigger, true, "Redirecting…");
            trigger.disabled = true;
        }

        PSPayments.initializePayment(id)
            .then(function (payload) {
                var data = payload.data || {};
                var url = data.authorization_url;

                if (!isSafeRedirect(url)) {
                    var missing = new Error("no authorization url");
                    missing.status = 0;

                    throw missing;
                }

                /*
                 * Hand off to the provider.
                 *
                 * Nothing is marked paid here and no amount is displayed from
                 * this response as though it settled anything. The server
                 * decides, payment-return.php asks it, and this page re-reads
                 * the result afterwards.
                 */
                global.location.assign(url);
            })
            .catch(function (error) {
                delete paying[id];

                if (trigger) {
                    trigger.disabled = false;
                    UI.setButtonLoading(trigger, false);
                }

                var message = payErrorMessage(error);

                UI.toast(message.body, {
                    type: message.tone,
                    title: message.title,
                    timeout: 7000
                });

                /*
                 * 400 / 404 / 409 mean the server's view of this booking
                 * disagrees with ours, so re-read it rather than leaving a
                 * button the user can keep pressing.
                 */
                if (error && [400, 404, 409].indexOf(error.status) !== -1) {
                    loadBookings();
                }
            });
    }

    /* ---------------------------------------------------------------------
       Cancel
       --------------------------------------------------------------------- */

    function cancelBooking(id, trigger) {
        var booking = bookingById(id);

        if (!booking) {
            return;
        }

        UI.confirm({
            title: "Cancel this booking?",
            message:
                (booking.studio_name || "This studio") +
                " on " +
                UI.date(booking.start_time) +
                " at " +
                UI.time(booking.start_time) +
                " will be released.",
            confirmLabel: "Cancel booking",
            tone: "danger"
        }).then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            /* Guard against a double click while the request is in flight. */
            if (state.busy) {
                return;
            }
            state.busy = true;

            UI.setButtonLoading(trigger, true, "Cancelling…");

            API.cancelBooking(id)
                .then(function (response) {
                    var data = response.data || {};

                    UI.toast(
                        "Booking cancelled. A " +
                            UI.number(data.refund_percentage) +
                            "% credit (" +
                            UI.money(data.refund_amount) +
                            ") was calculated for this booking.",
                        {
                            type: "success",
                            title: "Booking cancelled",
                            timeout: 7000
                        }
                    );

                    /* Show the refund wording inline, under the affected row. */
                    state.refundNote =
                        "Cancelled. The server calculated a " +
                        UI.number(data.refund_percentage) +
                        "% credit of " +
                        UI.money(data.refund_amount) +
                        " toward " +
                        UI.money(data.total_amount) +
                        ". This is a calculated figure only — no funds have been transferred.";

                    detailModal.close();
                    return loadBookings().then(function () {
                        revealRefund(id);
                    });
                })
                .catch(function (error) {
                    UI.toast(error.message, { type: "error", title: "Could not cancel" });
                })
                .then(function () {
                    state.busy = false;
                    UI.setButtonLoading(trigger, false);
                });
        });
    }

    function revealRefund(id) {
        var node = UI.qs('[data-refunder="' + id + '"]');

        if (node) {
            node.hidden = false;
            setHtml(UI.qs("span", node), state.refundNote || "");
        }
    }

    /* ---------------------------------------------------------------------
       Reschedule
       --------------------------------------------------------------------- */

    function openReschedule(id, trigger) {
        var booking = bookingById(id);

        if (!booking) {
            return;
        }

        rescheduleTarget = booking;
        rescheduleStart = null;

        UI.clearFormErrors(els.rescheduleForm);

        var started = UI.parseDate(booking.start_time);

        UI.qs("[data-reschedule-subtitle]").textContent =
            (booking.studio_name || "Studio") +
            " · currently " +
            (started ? UI.date(booking.start_time) + " " + UI.time(booking.start_time) : "—");

        /* Default the duration to the existing booking length. */
        var ended = UI.parseDate(booking.end_time);
        var hours = started && ended
            ? Math.max(1, Math.round((ended - started) / 3600000))
            : 1;

        els.rescheduleHours.value = String(hours);

        /* Pre-fill with the current date so the slots are immediately useful. */
        var today = new Date();
        els.rescheduleDate.min =
            today.getFullYear() +
            "-" +
            String(today.getMonth() + 1).padStart(2, "0") +
            "-" +
            String(today.getDate()).padStart(2, "0");
        els.rescheduleDate.value = els.rescheduleDate.min;

        updateRescheduleTotal();
        buildRescheduleSlots();

        rescheduleModal.open(trigger);
    }

    function buildRescheduleSlots() {
        var picker = UI.qs("[data-reschedule-slots]");
        var value = els.rescheduleDate.value;

        if (!value) {
            setHtml(
                picker,
                UI.stateHtml({
                    icon: "calendar",
                    title: "Pick a date",
                    message: "Choose a date to see available times.",
                    compact: true
                })
            );
            return;
        }

        var day = new Date(value + "T00:00:00");
        var hours = Number(els.rescheduleHours.value);
        var now = new Date();

        /* Busy slots for this studio come from the artist's own bookings that
           are still active; other artists' bookings are only discoverable via
           the server's 409 response. */
        var busy = (state.bookings || []).filter(function (booking) {
            return (
                UI.number(booking.studio_id) === UI.number(rescheduleTarget.studio_id) &&
                UI.number(booking.id) !== UI.number(rescheduleTarget.id) &&
                ["escrow_held", "confirmed", "in_session", "pending"].indexOf(
                    String(booking.status).toLowerCase()
                ) !== -1
            );
        });

        var slots = [];

        for (var minutes = OPEN_HOUR * 60; minutes < CLOSE_HOUR * 60; minutes += 60) {
            var start = new Date(
                day.getFullYear(),
                day.getMonth(),
                day.getDate(),
                Math.floor(minutes / 60),
                minutes % 60,
                0
            );

            var endsAt = new Date(start.getTime() + hours * 3600000);

            if (endsAt.getHours() > CLOSE_HOUR || endsAt.getDate() !== start.getDate()) {
                continue;
            }

            var isPast = start.getTime() <= now.getTime() + 15 * 60000;

            var isTaken = busy.some(function (booking) {
                var bStart = UI.parseDate(booking.start_time);
                var bEnd = UI.parseDate(booking.end_time);

                return bStart && bEnd && bStart < endsAt && bEnd > start;
            });

            slots.push({ start: start, past: isPast, taken: isTaken });
        }

        if (!slots.length) {
            setHtml(
                picker,
                UI.stateHtml({
                    icon: "clock",
                    title: "No times fit",
                    message:
                        "No whole-hour slot of " + hours + " hours fits on this date.",
                    compact: true
                })
            );
            return;
        }

        setHtml(
            picker,
            slots
                .map(function (slot) {
                    var label = slot.start.getHours();
                    var suffix = label >= 12 ? "PM" : "AM";
                    var display = label % 12 === 0 ? 12 : label % 12;
                    var key = slot.start.getTime();
                    var disabled = slot.past || slot.taken;

                    return (
                        '<button type="button" class="ps-slot" data-slot="' +
                        key +
                        '" aria-pressed="' +
                        (rescheduleStart === key ? "true" : "false") +
                        '"' +
                        (disabled ? " disabled" : "") +
                        ">" +
                        display +
                        ":00 " +
                        suffix +
                        "<small>" +
                        (slot.taken ? "Booked" : slot.past ? "Past" : hours + (hours === 1 ? " hr" : " hrs")) +
                        "</small>" +
                        "</button>"
                    );
                })
                .join("")
        );

        UI.qsa("[data-slot]", picker).forEach(function (button) {
            button.addEventListener("click", function () {
                rescheduleStart = Number(button.getAttribute("data-slot"));

                UI.qsa("[data-slot]", picker).forEach(function (other) {
                    other.setAttribute(
                        "aria-pressed",
                        Number(other.getAttribute("data-slot")) === rescheduleStart
                            ? "true"
                            : "false"
                    );
                });

                updateRescheduleTotal();
            });
        });
    }

    function updateRescheduleTotal() {
        if (!rescheduleTarget) {
            return;
        }

        /*
         * The booking row does not carry hourly_rate, so the rate is derived
         * from the booking's own figures: total_amount / its duration. This is
         * only used for a preview marked "≈"; the server recalculates the
         * authoritative total from studios.hourly_rate when it saves.
         */
        var started = UI.parseDate(rescheduleTarget.start_time);
        var ended = UI.parseDate(rescheduleTarget.end_time);
        var rate = 0;

        if (started && ended && UI.number(rescheduleTarget.total_amount) > 0) {
            var originalHours = (ended - started) / 3600000;

            if (originalHours > 0) {
                rate = UI.number(rescheduleTarget.total_amount) / originalHours;
            }
        }

        var hours = Number(els.rescheduleHours.value);

        UI.qs("[data-reschedule-total]").textContent = rate
            ? "≈ " + UI.money(rate * hours)
            : "—";
    }

    function submitReschedule(event) {
        event.preventDefault();

        if (state.busy || !rescheduleTarget) {
            return;
        }

        UI.clearFormErrors(els.rescheduleForm);

        if (!rescheduleStart) {
            UI.setFormError(els.rescheduleForm, "Choose a new start time.");
            return;
        }

        var hours = Number(els.rescheduleHours.value);
        var start = new Date(rescheduleStart);
        var end = new Date(start.getTime() + hours * 3600000);

        state.busy = true;
        UI.setButtonLoading(UI.qs("[data-reschedule-submit]"), true, "Saving…");

        API.rescheduleBooking(UI.number(rescheduleTarget.id), {
            start_time: formatTime(start),
            end_time: formatTime(end)
        })
            .then(function (response) {
                rescheduleModal.close();

                UI.toast("Session moved to " + UI.dateTime(response.data.start_time) + ".", {
                    type: "success",
                    title: "Booking rescheduled"
                });

                return loadBookings();
            })
            .catch(function (error) {
                UI.setFormError(els.rescheduleForm, error.message);
                UI.toast(error.message, {
                    type: error.status === 409 ? "warning" : "error",
                    title: "Could not reschedule"
                });
            })
            .then(function () {
                state.busy = false;
                UI.setButtonLoading(UI.qs("[data-reschedule-submit]"), false);
            });
    }

    function formatTime(date) {
        function pad(n) {
            return String(n).padStart(2, "0");
        }

        return (
            date.getFullYear() +
            "-" +
            pad(date.getMonth() + 1) +
            "-" +
            pad(date.getDate()) +
            " " +
            pad(date.getHours()) +
            ":" +
            pad(date.getMinutes()) +
            ":" +
            pad(date.getSeconds())
        );
    }

    /* ---------------------------------------------------------------------
       Complete
       --------------------------------------------------------------------- */

    function completeBooking(id, trigger) {
        var booking = bookingById(id);

        if (!booking) {
            return;
        }

        UI.confirm({
            title: "Mark this session complete?",
            message:
                "Confirming tells Paradise Studios that the session at " +
                (booking.studio_name || "this studio") +
                " has finished. Buzz XP is awarded by the server.",
            confirmLabel: "Mark complete"
        }).then(function (confirmed) {
            if (!confirmed || state.busy) {
                return;
            }

            state.busy = true;
            UI.setButtonLoading(trigger, true, "Completing…");

            API.completeBooking(id)
                .then(function (response) {
                    var xp = UI.number((response.data || {}).xp_earned);

                    UI.toast(
                        "Session completed. " + xp + " Buzz XP was added to your meter.",
                        { type: "success", title: "Session complete" }
                    );

                    detailModal.close();
                    return loadBookings();
                })
                .catch(function (error) {
                    UI.toast(error.message, { type: "error", title: "Could not complete" });
                })
                .then(function () {
                    state.busy = false;
                    UI.setButtonLoading(trigger, false);
                });
        });
    }

    /* ---------------------------------------------------------------------
       Loading
       --------------------------------------------------------------------- */

    function loadBookings() {
        return API.myBookings()
            .then(function (payload) {
                state.bookings = payload.data || [];
                render();
            })
            .catch(function (error) {
                state.bookings = null;
                renderError(error);
            });
    }

    /* ---------------------------------------------------------------------
       Wiring
       --------------------------------------------------------------------- */

    function setHtml(node, html) {
        if (node) {
            node.innerHTML = html;
        }
    }

    function syncTabs() {
        UI.qsa("[data-tab]").forEach(function (button) {
            var isActive = button.getAttribute("data-tab") === state.tab;

            button.classList.toggle("is-active", isActive);
            button.setAttribute("aria-selected", isActive ? "true" : "false");
        });
    }

    function cacheElements() {
        els.summary = UI.qs("[data-bookings-summary]");
        els.rows = UI.qs("[data-booking-rows]");
        els.search = UI.qs("[data-bookings-search]");
        els.rescheduleForm = UI.qs("[data-reschedule-form]");
        els.rescheduleDate = UI.qs("[data-reschedule-date]");
        els.rescheduleHours = UI.qs("[data-reschedule-hours]");
    }

    function init() {
        cacheElements();

        detailModal = UI.modal("#booking-modal");
        rescheduleModal = UI.modal("#reschedule-modal");

        /* Delegated actions work for both the table and the detail dialog. */
        document.addEventListener("click", function (event) {
            var button = event.target.closest("[data-action]");
            var id = button ? UI.number(button.getAttribute("data-booking-id")) : null;

            if (!button || !id) {
                return;
            }

            var action = button.getAttribute("data-action");

            if (action === "view") {
                openDetail(id, button);
            } else if (action === "pay") {
                payBooking(id, button);
            } else if (action === "cancel") {
                cancelBooking(id, button);
            } else if (action === "reschedule") {
                openReschedule(id, button);
            } else if (action === "complete") {
                completeBooking(id, button);
            }
        });

        UI.qsa("[data-tab]").forEach(function (button) {
            button.addEventListener("click", function () {
                state.tab = button.getAttribute("data-tab");
                syncTabs();
                render();
            });
        });

        if (els.search) {
            els.search.addEventListener(
                "input",
                UI.debounce(function () {
                    state.query = els.search.value;
                    render();
                }, 200)
            );
        }

        if (els.rescheduleForm) {
            els.rescheduleForm.addEventListener("submit", submitReschedule);

            els.rescheduleDate.addEventListener("change", function () {
                rescheduleStart = null;
                buildRescheduleSlots();
            });

            els.rescheduleHours.addEventListener("change", function () {
                rescheduleStart = null;
                buildRescheduleSlots();
                updateRescheduleTotal();
            });
        }

        loadBookings();

        /* Deep link from the dashboard: ?booking=<id> opens that booking. */
        var requested = new URLSearchParams(global.location.search).get("booking");

        if (requested) {
            openDetail(Number(requested));
        }
    }

    global.ParadiseArtistBookings = { init: init };

    UI.onReady(init);
})(window);