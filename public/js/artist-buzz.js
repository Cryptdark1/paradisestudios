/**
 * Paradise Studios — Buzz XP and Reviews.
 *
 * Endpoints:
 *   GET  /api/buzz.php       XP / rank / progress — all values server-computed
 *   GET  /api/my-bookings.php  completed bookings that could be reviewed
 *   GET  /api/reviews.php    reviews already written
 *   POST /api/reviews.php    submit a review
 *
 * This module never awards or modifies XP. It only displays the figures
 * api/buzz.php returns.
 */
(function (global) {
    "use strict";

    var API = global.ArtistAPI;
    var UI = global.UI;
    var A = global.ArtistUI;
    var escape = UI.escape;

    /*
     * Rank thresholds. These match api/buzz.php exactly and are used only to
     * draw the milestone ladder — the XP figures themselves always come from
     * the endpoint, never from this table.
     */
    var RANKS = [
        { name: "Local", min: 0, max: 499, icon: "map-pin" },
        { name: "City Star", min: 500, max: 1999, icon: "star" },
        { name: "National Icon", min: 2000, max: null, icon: "badge-check" }
    ];

    var state = {
        buzz: null,
        bookings: null,
        reviews: null,
        rating: 0,
        reviewTarget: null,
        submitting: false
    };

    var els = {};
    var reviewModal = null;

    /* ---------------------------------------------------------------------
       Buzz XP
       --------------------------------------------------------------------- */

    function renderBuzz() {
        if (!els.buzz || !state.buzz) {
            return;
        }

        var data = state.buzz;
        var xp = UI.number(data.xp);

        UI.qs("[data-buzz-xp]").textContent = xp.toLocaleString(UI.LOCALE);

        /*
         * Inject the donut once. The SVG is prepended so the XP total rendered
         * by PHP inside .ps-buzz__centre is preserved.
         */
        var ring = UI.qs(".ps-buzz__ring", els.buzz);

        if (ring && !ring.querySelector("svg")) {
            ring.insertAdjacentHTML("afterbegin", A.buzzRing(data, 92));
        }

        UI.qs("[data-buzz-level]").textContent = data.level || "Local";
        UI.qs("[data-buzz-next]").innerHTML = A.buzzNext(data);

        var percent = Math.max(0, Math.min(100, UI.number(data.progress_percent)));

        UI.qs("[data-buzz-bar]").style.width = percent + "%";

        UI.qs("[data-buzz-progress-label]").textContent = data.next_level
            ? Math.round(percent) +
              "% towards " +
              data.next_level +
              " (" +
              UI.number(data.next_threshold).toLocaleString(UI.LOCALE) +
              " XP)"
            : "Top rank reached.";

        var tier = UI.qs("[data-buzz-tier]");

        if (tier) {
            tier.hidden = false;
            tier.className = "ps-badge ps-badge--warning";
            tier.innerHTML =
                UI.icon("bolt", 12) +
                "<span>" +
                escape(data.level || "Local") +
                "</span>";
        }

        /* Progress bar animation needs a frame to take effect. */
        global.requestAnimationFrame(function () {
            UI.qs("[data-buzz-bar]").style.width = percent + "%";
        });
    }

    function renderRanks() {
        if (!els.ranks || !state.buzz) {
            return;
        }

        var current = state.buzz.level;

        els.ranks.setAttribute("aria-busy", "false");

        setHtml(
            els.ranks,
            RANKS.map(function (rank) {
                var isCurrent = rank.name === current;
                var range =
                    rank.max === null
                        ? UI.number(rank.min).toLocaleString(UI.LOCALE) + "+ XP"
                        : UI.number(rank.min).toLocaleString(UI.LOCALE) +
                          "–" +
                          UI.number(rank.max).toLocaleString(UI.LOCALE) +
                          " XP";

                return (
                    '<div class="ps-rank' +
                    (isCurrent ? " is-current" : "") +
                    '">' +
                    '<span class="ps-rank__badge" aria-hidden="true">' +
                    UI.icon(rank.icon, 17) +
                    "</span>" +
                    "<span>" +
                    '<span class="ps-rank__name">' +
                    escape(rank.name) +
                    "</span>" +
                    '<span class="ps-rank__range">' +
                    escape(range) +
                    "</span>" +
                    "</span>" +
                    (isCurrent
                        ? '<span class="ps-badge ps-badge--solid ps-badge--warning ps-rank__flag">' +
                          "You</span>"
                        : "") +
                    "</div>"
                );
            }).join("")
        );
    }

    function renderSummary() {
        if (!els.summary) {
            return;
        }

        if (!state.buzz) {
            els.summary.textContent = "Buzz XP could not be loaded.";
            return;
        }

        var data = state.buzz;
        var parts = [
            UI.number(data.xp).toLocaleString(UI.LOCALE) + " total XP",
            data.level + " rank"
        ];

        if (data.next_level) {
            parts.push(
                UI.number(data.xp_remaining).toLocaleString(UI.LOCALE) +
                    " XP to " +
                    data.next_level
            );
        }

        els.summary.textContent = parts.join(" · ") + ".";
    }

    function renderBuzzError(error) {
        if (!els.buzz) {
            return;
        }

        els.buzz.setAttribute("aria-busy", "false");

        UI.qs("[data-buzz-xp]").textContent = "—";
        UI.qs("[data-buzz-level]").textContent = "Unavailable";

        setHtml(
            els.buzz,
            UI.stateHtml({
                tone: "error",
                icon: "alert",
                title: "Could not load Buzz XP",
                message: (error && error.message) || "Please try again.",
                actionLabel: "Try again",
                compact: true
            })
        );

        var button = UI.qs("[data-state-action]", els.buzz);

        if (button) {
            button.addEventListener("click", loadBuzz);
        }

        if (els.ranks) {
            els.ranks.setAttribute("aria-busy", "false");
            setHtml(
                els.ranks,
                '<div class="ps-state ps-state--compact" style="grid-column:1/-1">' +
                    '<div class="ps-state__msg">Rank milestones unavailable.</div>' +
                    "</div>"
            );
        }

        renderSummary();
    }

    /* ---------------------------------------------------------------------
       Reviewable bookings
       --------------------------------------------------------------------- */

    function renderReviewable() {
        if (!els.reviewable || !state.bookings) {
            return;
        }

        els.reviewable.setAttribute("aria-busy", "false");

        var reviewed = {};
        (state.reviews || []).forEach(function (review) {
            reviewed[UI.number(review.booking_id)] = true;
        });

        var pending = state.bookings.filter(function (booking) {
            return (
                String(booking.status).toLowerCase() === "completed" &&
                !reviewed[UI.number(booking.id)]
            );
        });

        if (!pending.length) {
            var completedCount = state.bookings.filter(function (booking) {
                return String(booking.status).toLowerCase() === "completed";
            }).length;

            setHtml(
                els.reviewable,
                UI.stateHtml({
                    icon: "star",
                    title: completedCount
                        ? "Every completed session has been reviewed"
                        : "No completed sessions yet",
                    message: completedCount
                        ? "Reviews can be left for any future completed session."
                        : "Mark a session complete from My Bookings, then you can review it here.",
                    compact: true
                })
            );
            return;
        }

        setHtml(
            els.reviewable,
            '<div class="ps-reviewable">' +
                pending
                    .map(function (booking) {
                        return (
                            '<div class="ps-reviewrow">' +
                            '<span class="ps-avatar ps-avatar--sm" aria-hidden="true">' +
                            escape(UI.initials(booking.studio_name)) +
                            "</span>" +
                            '<span class="ps-reviewrow__text">' +
                            '<span class="ps-reviewrow__studio">' +
                            escape(booking.studio_name || "Studio") +
                            "</span>" +
                            '<span class="ps-reviewrow__when">' +
                            escape(UI.date(booking.start_time)) +
                            " · " +
                            escape(UI.time(booking.start_time)) +
                            " · Booking #" +
                            UI.number(booking.id) +
                            "</span>" +
                            "</span>" +
                            '<span class="ps-reviewrow__action">' +
                            '<button type="button" class="ps-btn ps-btn--primary ps-btn--sm" ' +
                            'data-review-booking="' +
                            UI.number(booking.id) +
                            '">Write a review</button>' +
                            "</span>" +
                            "</div>"
                        );
                    })
                    .join("") +
                "</div>"
        );
    }

    function renderReviewableError(error) {
        if (!els.reviewable) {
            return;
        }

        els.reviewable.setAttribute("aria-busy", "false");

        setHtml(
            els.reviewable,
            UI.stateHtml({
                tone: "error",
                icon: "alert",
                title: "Could not load sessions",
                message: (error && error.message) || "Please try again.",
                actionLabel: "Try again",
                compact: true
            })
        );

        var button = UI.qs("[data-state-action]", els.reviewable);

        if (button) {
            button.addEventListener("click", loadBookings);
        }
    }

    /* ---------------------------------------------------------------------
       Existing reviews
       --------------------------------------------------------------------- */

    function renderReviews() {
        if (!els.reviews || !state.reviews) {
            return;
        }

        els.reviews.setAttribute("aria-busy", "false");

        if (!state.reviews.length) {
            setHtml(
                els.reviews,
                UI.stateHtml({
                    icon: "ticket",
                    title: "No reviews yet",
                    message: "Your reviews will be listed here once you write one.",
                    compact: true
                })
            );
            return;
        }

        setHtml(
            els.reviews,
            state.reviews
                .map(function (review) {
                    var rating = UI.number(review.rating);

                    /* Filled vs outlined stars, plus the numeric rating as text,
                       so the value is never colour- or shape-only. */
                    var stars = "";
                    for (var i = 1; i <= 5; i++) {
                        stars +=
                            '<span class="' +
                            (i <= rating ? "" : "is-off") +
                            '">' +
                            UI.icon(i <= rating ? "star" : "star-off", 14) +
                            "</span>";
                    }

                    var comment = String(review.comment || "").trim();

                    return (
                        '<article class="ps-review">' +
                        '<span class="ps-avatar ps-avatar--sm ps-avatar--artist ps-review__avatar" aria-hidden="true">' +
                        escape(UI.initials(review.studio_name)) +
                        "</span>" +
                        '<div class="ps-review__body">' +
                        '<div class="ps-review__head">' +
                        '<span class="ps-review__studio">' +
                        escape(review.studio_name || "Studio") +
                        "</span>" +
                        '<span class="ps-stars-read" aria-hidden="true">' +
                        stars +
                        "</span>" +
                        '<span class="ps-review__rating-text">' +
                        rating +
                        " out of 5</span>" +
                        "</div>" +
                        '<p class="ps-review__comment' +
                        (comment ? "" : " ps-review__comment--empty") +
                        '">' +
                        escape(comment || "No comment left.") +
                        "</p>" +
                        '<p class="ps-review__date">Session ' +
                        escape(UI.date(review.start_time)) +
                        " · reviewed " +
                        escape(UI.date(review.created_at)) +
                        "</p>" +
                        "</div>" +
                        "</article>"
                    );
                })
                .join("")
        );
    }

    function renderReviewsError(error) {
        if (!els.reviews) {
            return;
        }

        els.reviews.setAttribute("aria-busy", "false");

        setHtml(
            els.reviews,
            UI.stateHtml({
                tone: "error",
                icon: "alert",
                title: "Could not load reviews",
                message: (error && error.message) || "Please try again.",
                actionLabel: "Try again",
                compact: true
            })
        );

        var button = UI.qs("[data-state-action]", els.reviews);

        if (button) {
            button.addEventListener("click", loadReviews);
        }
    }

    /* ---------------------------------------------------------------------
       Review submission
       --------------------------------------------------------------------- */

    function buildRating() {
        var host = UI.qs("[data-rating]");

        if (!host) {
            return;
        }

        setHtml(
            host,
            [1, 2, 3, 4, 5]
                .map(function (value) {
                    return (
                        '<span class="ps-rating__item' +
                        (value <= state.rating ? " is-on" : "") +
                        '" data-star="' +
                        value +
                        '">' +
                        '<input type="radio" name="rating" value="' +
                        value +
                        '"' +
                        (state.rating === value ? " checked" : "") +
                        ' aria-label="' +
                        value +
                        ' out of 5">' +
                        UI.icon("star", 18) +
                        "</span>"
                    );
                })
                .join("")
        );

        UI.qsa("input[name=rating]", host).forEach(function (input) {
            input.addEventListener("change", function () {
                state.rating = UI.number(input.value);
                paintRating();

                var errorNode = UI.qs("[data-field-error-rating]");
                if (errorNode) {
                    errorNode.innerHTML = "";
                }
            });
        });
    }

    function paintRating() {
        UI.qsa("[data-star]").forEach(function (item) {
            item.classList.toggle(
                "is-on",
                UI.number(item.getAttribute("data-star")) <= state.rating
            );
        });
    }

    function openReview(bookingId, trigger) {
        var booking = (state.bookings || []).filter(function (row) {
            return UI.number(row.id) === bookingId;
        })[0];

        if (!booking) {
            return;
        }

        state.reviewTarget = booking;
        state.rating = 0;

        UI.clearFormErrors(els.form);
        els.form.reset();

        buildRating();

        UI.qs("[data-review-subtitle]").textContent =
            (booking.studio_name || "Studio") +
            " · " +
            UI.date(booking.start_time);

        reviewModal.open(trigger);
    }

    function submitReview(event) {
        event.preventDefault();

        if (state.submitting || !state.reviewTarget) {
            return;
        }

        UI.clearFormErrors(els.form);

        if (!state.rating) {
            UI.qs("[data-field-error-rating]").innerHTML =
                UI.icon("alert", 13) + "<span>Choose a rating from 1 to 5.</span>";
            return;
        }

        state.submitting = true;
        UI.setButtonLoading(els.submit, true, "Submitting…");

        API.submitReview({
            booking_id: UI.number(state.reviewTarget.id),
            rating: state.rating,
            comment: els.comment.value.trim()
        })
            .then(function (response) {
                var data = response.data || {};

                reviewModal.close();

                UI.toast(
                    "Review submitted for " + (data.studio_name || "your session") + ".",
                    { type: "success", title: "Thank you" }
                );

                /*
                 * Reviews do not change XP, so the Buzz figures are untouched.
                 * Only the review list is refreshed.
                 */
                return Promise.all([loadReviews(), loadBookings()]);
            })
            .catch(function (error) {
                if (error.status === 409) {
                    /* Already reviewed, or the session is not completed. */
                    UI.setFormError(els.form, error.message);
                } else {
                    UI.setFormError(els.form, error.message);
                }

                UI.toast(error.message, { type: "error", title: "Review not saved" });
            })
            .then(function () {
                state.submitting = false;
                UI.setButtonLoading(els.submit, false);
            });
    }

    /* ---------------------------------------------------------------------
       Loading
       --------------------------------------------------------------------- */

    function loadBuzz() {
        return API.buzz()
            .then(function (payload) {
                state.buzz = payload.data || {};
                renderBuzz();
                renderRanks();
                renderSummary();
            })
            .catch(function (error) {
                state.buzz = null;
                renderBuzzError(error);
            });
    }

    function loadBookings() {
        return API.myBookings()
            .then(function (payload) {
                state.bookings = payload.data || [];
                renderReviewable();
            })
            .catch(function (error) {
                state.bookings = null;
                renderReviewableError(error);
            });
    }

    function loadReviews() {
        return API.myReviews()
            .then(function (payload) {
                state.reviews = payload.data || [];
                renderReviews();
                renderReviewable();
            })
            .catch(function (error) {
                state.reviews = null;
                renderReviewsError(error);
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

    function cacheElements() {
        els.summary = UI.qs("[data-buzz-summary]");
        els.buzz = UI.qs("[data-buzz]");
        els.ranks = UI.qs("[data-ranks]");
        els.reviewable = UI.qs("[data-reviewable]");
        els.reviews = UI.qs("[data-reviews]");
        els.form = UI.qs("[data-review-form]");
        els.comment = UI.qs("#review-comment");
        els.submit = UI.qs("[data-review-submit]");
    }

    function init() {
        cacheElements();

        if (!els.form) {
            return;
        }

        reviewModal = UI.modal("#review-modal");

        /* Delegated so it keeps working as the list changes. */
        if (els.reviewable) {
            els.reviewable.addEventListener("click", function (event) {
                var button = event.target.closest("[data-review-booking]");

                if (button) {
                    openReview(
                        UI.number(button.getAttribute("data-review-booking")),
                        button
                    );
                }
            });
        }

        els.form.addEventListener("submit", submitReview);

        loadBuzz();
        loadBookings();
        loadReviews();
    }

    global.ParadiseArtistBuzz = { init: init };

    UI.onReady(init);
})(window);