/**
 * Paradise Studios — Owner dashboard.
 *
 * Loads the three existing owner APIs once, then renders every widget from
 * that real data. Nothing on this page is hardcoded:
 *
 *   GET /api/owner-analytics.php  -> totals, peak hours
 *   GET /api/owner-bookings.php   -> upcoming sessions, revenue series
 *   GET /api/owner-studios.php    -> studio count and locations
 *
 * Sections fail independently, so one unavailable endpoint never blanks the
 * whole page.
 */
(function (global) {
    "use strict";

    var API = global.API;
    var UI = global.UI;
    var Charts = global.ParadiseCharts;

    var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

    /* Cached API responses. A dashboard should not poll the server. */
    var state = {
        analytics: null,
        bookings: null,
        studios: null,
        range: "all"
    };

    var els = {};

    /* ---------------------------------------------------------------------
       Utilities
       --------------------------------------------------------------------- */

    function setHtml(node, html) {
        if (node) {
            node.innerHTML = html;
        }
    }

    /* Local aliases keep the template literals below readable. */
    var escape = UI.escape;

    function startOfDay(date) {
        var copy = new Date(date.getTime());
        copy.setHours(0, 0, 0, 0);
        return copy;
    }

    function dayKey(date) {
        return (
            date.getFullYear() +
            "-" +
            String(date.getMonth() + 1).padStart(2, "0") +
            "-" +
            String(date.getDate()).padStart(2, "0")
        );
    }

    function monthKey(date) {
        return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0");
    }

    function formatHour(hour) {
        var normalised = ((hour % 24) + 24) % 24;
        var suffix = normalised >= 12 ? "PM" : "AM";
        var display = normalised % 12 === 0 ? 12 : normalised % 12;
        return display + ":00 " + suffix;
    }

    function pluralise(count, singular, plural) {
        var value = UI.number(count);

        return value + " " + (value === 1 ? singular : plural || singular + "s");
    }

    /* ---------------------------------------------------------------------
       Data loading
       --------------------------------------------------------------------- */

    function loadAnalytics() {
        return API.owner
            .analytics()
            .then(function (payload) {
                state.analytics = payload.data || {};
                renderStats();
                renderPeakChart();
                renderSummary();
            })
            .catch(function (error) {
                state.analytics = null;
                failStats(error, "bookings");
                renderPeakError(error);
                setHtml(els.summary, "We could not load your analytics right now.");
            });
    }

    function loadBookings() {
        return API.owner
            .bookings()
            .then(function (payload) {
                state.bookings = payload.data || [];
                renderStats();
                renderRevenueChart();
                renderUpcoming();
                renderSummary();
            })
            .catch(function (error) {
                state.bookings = null;
                renderUpcomingError(error);
                renderChartError(els.revenue, error, function () {
                    loadBookings();
                });
                renderSummary();
            });
    }

    function loadStudios() {
        return API.owner
            .studios()
            .then(function (payload) {
                state.studios = payload.data || [];
                renderStats();
                renderSummary();
            })
            .catch(function (error) {
                state.studios = null;
                renderStatError("studios", error);
                renderSummary();
            });
    }

    function loadOwnerPayouts() {
        return API.owner.payouts("summary").then(function (payload) {
            var data = payload.data || {};
            var summary = data.summary || {};
            var account = data.account;
            var available = UI.qs("[data-payout-available]");
            var accountStatus = UI.qs("[data-payout-account-status]");
            var eligibleNode = UI.qs("[data-payout-eligible]");
            var historyNode = UI.qs("[data-payout-history]");
            var bankSelect = UI.qs("[data-payout-bank]");
            if (available) {
                available.textContent = "Available: " + (summary.available_display || "—");
            }
            if (accountStatus) {
                accountStatus.textContent = account
                    ? "Verified account: " + account.account_name + " · " + account.bank_name + " · •••• " + account.account_last4
                    : "No verified payout bank account is registered.";
            }
            if (eligibleNode) {
                eligibleNode.innerHTML = (data.eligible || []).length
                    ? data.eligible.map(function (payout) {
                        return '<div class="ps-row ps-row--between"><span>Booking #' +
                            UI.escape(String(payout.booking_id)) + " · " +
                            UI.escape(payout.amount_display || "") +
                            '</span><button class="ps-btn ps-btn--primary ps-btn--sm" data-request-payout="' +
                            UI.escape(String(payout.payout_id)) + '"' + (account ? "" : " disabled") +
                            '>Request payout</button></div>';
                    }).join("")
                    : "<p>No earnings are currently eligible for payout.</p>";
            }
            if (historyNode) {
                historyNode.innerHTML = (data.attempts || []).length
                    ? data.attempts.map(function (attempt) {
                        var canReconcile = ["pending", "unknown", "submitting"].indexOf(attempt.status) !== -1;
                        return '<div class="ps-row ps-row--between"><span>' +
                            UI.escape(attempt.reference) + " · " +
                            UI.escape(attempt.status) +
                            (attempt.provider_status ? " (" + UI.escape(attempt.provider_status) + ")" : "") +
                            '</span>' + (canReconcile
                                ? '<button class="ps-btn ps-btn--secondary ps-btn--sm" data-reconcile-payout="' +
                                    UI.escape(String(attempt.id)) + '">Check status</button>'
                                : "") + "</div>";
                    }).join("")
                    : "<p>No transfers yet.</p>";
            }
            return API.owner.payouts("banks").then(function (bankPayload) {
                if (bankSelect) {
                    bankSelect.innerHTML = '<option value="">Choose a bank</option>' +
                        (bankPayload.data || []).map(function (bank) {
                            return '<option value="' + UI.escape(bank.code) + '" data-bank-name="' +
                                UI.escape(bank.name) + '">' + UI.escape(bank.name) + "</option>";
                        }).join("");
                }
            });
        }).catch(function (error) {
            var available = UI.qs("[data-payout-available]");
            if (available) {
                available.textContent = (error && error.message) || "Payout information is unavailable.";
            }
        });
    }

    function wireOwnerPayouts() {
        var form = UI.qs("[data-payout-account-form]");
        var bankSelect = UI.qs("[data-payout-bank]");
        var accountInput = UI.qs("[data-payout-account-number]");
        var status = UI.qs("[data-payout-account-status]");
        if (form) {
            form.addEventListener("submit", function (event) {
                event.preventDefault();
                var option = bankSelect.options[bankSelect.selectedIndex];
                API.owner.payouts("register_account", {
                    bank_code: bankSelect.value,
                    bank_name: option ? option.getAttribute("data-bank-name") : "",
                    account_number: accountInput.value
                }).then(function (result) {
                    if (status) {
                        status.textContent = "Bank account verified: " + result.data.account_name +
                            " · " + result.data.bank_name + " · •••• " + result.data.account_last4;
                    }
                    accountInput.value = "";
                    return loadOwnerPayouts();
                }).catch(function (error) {
                    if (status) status.textContent = (error && error.message) || "Could not verify bank account.";
                });
            });
        }
        document.addEventListener("click", function (event) {
            var payoutButton = event.target.closest("[data-request-payout]");
            var reconcileButton = event.target.closest("[data-reconcile-payout]");
            var action;
            var body;
            if (payoutButton) {
                action = "request_payout";
                body = { payout_id: Number(payoutButton.getAttribute("data-request-payout")) };
            } else if (reconcileButton) {
                action = "reconcile";
                body = { attempt_id: Number(reconcileButton.getAttribute("data-reconcile-payout")) };
            } else {
                return;
            }
            payoutButton && (payoutButton.disabled = true);
            reconcileButton && (reconcileButton.disabled = true);
            API.owner.payouts(action, body).then(loadOwnerPayouts).catch(function (error) {
                if (status) status.textContent = (error && error.message) || "Payout request could not be completed.";
                loadOwnerPayouts();
            });
        });
    }

    /* ---------------------------------------------------------------------
       Statistics
       --------------------------------------------------------------------- */

    function renderStats() {
        var analytics = state.analytics;
        var bookings = state.bookings;
        var studios = state.studios;

        /* --- Total bookings --- */
        if (analytics) {
            setHtml(els.statBookings, UI.number(analytics.booking_count).toLocaleString(UI.LOCALE));

            var foot = els.statFootBookings;

            if (bookings && bookings.length) {
                var comparison = compareMonths(bookings);

                foot.innerHTML =
                    deltaMarkup(comparison) + "<span>vs last month</span>";

                foot.title =
                    pluralise(comparison.current, "booking") +
                    " this month, " +
                    pluralise(comparison.previous, "booking") +
                    " last month";
            } else if (bookings) {
                foot.innerHTML = "<span>No bookings yet</span>";
            } else {
                foot.innerHTML = '<span class="ps-muted">Across all studios</span>';
            }
        }

        /* --- Gross revenue --- */
        if (analytics) {
            setHtml(els.statRevenue, analytics.captured_gross || "—");

            var revenueFoot = els.statFootRevenue;

            if (bookings) {
                var revenue = compareMonths(
                    bookings.filter(function (booking) {
                        return String(booking.status).toLowerCase() === "completed";
                    }),
                    function (booking) {
                        return booking.total_amount;
                    }
                );

                revenueFoot.innerHTML = deltaMarkup(revenue) + "<span>vs last month</span>";

                revenueFoot.title =
                    UI.money(revenue.current) +
                    " completed booking value this month, " +
                    UI.money(revenue.previous) +
                    " last month";
            } else {
                revenueFoot.innerHTML = '<span class="ps-muted">Verified payment captures</span>';
            }
        }

        /* --- Pending owner earnings --- */
        if (analytics) {
            setHtml(els.statEscrow, analytics.pending_earnings || "—");
            els.statFootEscrow.innerHTML =
                '<span class="ps-muted">Not provider-held funds</span>';
        }

        /* --- My studios --- */
        if (studios) {
            setHtml(els.statStudios, String(studios.length));

            var locations = {};
            studios.forEach(function (studio) {
                if (studio.location) {
                    locations[String(studio.location).trim()] = true;
                }
            });

            var count = Object.keys(locations).length;

            els.statFootStudios.innerHTML = studios.length
                ? "<span>" +
                  escape(count === 1 ? "1 location listed" : count + " locations listed") +
                  "</span>"
                : '<span class="ps-muted">No studios listed yet</span>';
        }
    }

    /**
     * Aggregate the current calendar month versus the previous one, computed
     * from real booking rows.
     *
     * @param {Array} rows        booking rows from the API
     * @param {Function} [amountFn] maps a row to the value to aggregate;
     *                           omit to count rows
     * @returns {{current:number, previous:number}}
     */
    function compareMonths(rows, amountFn) {
        var now = new Date();
        var thisMonth = monthKey(now);

        var lastMonthDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        var lastMonth = monthKey(lastMonthDate);

        var current = 0;
        var previous = 0;

        rows.forEach(function (booking) {
            var started = UI.parseDate(booking.start_time);

            if (!started) {
                return;
            }

            var key = monthKey(started);

            if (key !== thisMonth && key !== lastMonth) {
                return;
            }

            var value = amountFn ? UI.number(amountFn(booking)) : 1;

            if (key === thisMonth) {
                current += value;
            } else {
                previous += value;
            }
        });

        return { current: current, previous: previous };
    }

    /** Render a percentage pill plus the "vs last month" caption. */
    function deltaMarkup(result) {
        var info = UI.delta(result.current, result.previous);

        if (info.percent === null) {
            return (
                '<span class="ps-delta ps-delta--flat">' +
                UI.icon("minus", 11) +
                "No prior month</span>"
            );
        }

        var glyph =
            info.direction === "down" ? "arrow-down" : info.direction === "up" ? "arrow-up" : "minus";

        return (
            '<span class="ps-delta ps-delta--' +
            info.direction +
            '">' +
            UI.icon(glyph, 11) +
            escape(info.text) +
            "</span>"
        );
    }

    function renderStatError(key, error) {
        var node = els["stat" + key.charAt(0).toUpperCase() + key.slice(1)];

        if (node) {
            node.innerHTML = '<span class="ps-stat__value-error">—</span>';
            node.title = (error && error.message) || "Could not load";
        }
    }

    function failStats(error, key) {
        renderStatError(key, error);
    }

    /* ---------------------------------------------------------------------
       Summary line
       --------------------------------------------------------------------- */

    function renderSummary() {
        if (!els.summary) {
            return;
        }

        var parts = [];

        if (state.studios) {
            parts.push(
                state.studios.length
                    ? pluralise(state.studios.length, "studio") + " listed"
                    : "No studios listed yet"
            );
        }

        if (state.bookings) {
            var now = Date.now();
            var upcoming = state.bookings.filter(function (booking) {
                var started = UI.parseDate(booking.start_time);
                return (
                    started &&
                    started.getTime() >= now &&
                    ["cancelled", "completed", "disputed"].indexOf(
                        String(booking.status).toLowerCase()
                    ) === -1
                );
            });

            parts.push(pluralise(upcoming.length, "upcoming session"));
        }

        if (state.analytics && state.analytics.pending_earnings) {
            parts.push(state.analytics.pending_earnings + " in pending owner earnings");
        }

        els.summary.textContent = parts.length
            ? "Here is where things stand — " + parts.join(" · ") + "."
            : "Here is where things stand.";
    }

    /* ---------------------------------------------------------------------
       Revenue chart
       --------------------------------------------------------------------- */

    function bucketise(bookings, range) {
        var now = new Date();
        var buckets = [];
        var index = {};

        function push(label, key) {
            buckets.push({ label: label, key: key, completed: 0, confirmed: 0 });
            index[key] = buckets.length - 1;
        }

        function daily(count, offset) {
            var first = startOfDay(now);
            first.setDate(first.getDate() + (offset || 0));

            for (var i = 0; i < count; i++) {
                var cursor = new Date(first.getTime());
                cursor.setDate(cursor.getDate() + i);
                push(MONTHS[cursor.getMonth()] + " " + cursor.getDate(), dayKey(cursor));
            }
        }

        var dailyRange = range === "7" || range === "30" || range === "forward";

        if (range === "7") {
            daily(7, -6);
        } else if (range === "30") {
            daily(30, -29);
        } else if (range === "forward") {
            /* A forward-looking window shows booked value, not money held. */
            daily(30, 0);
        } else {
            var months = 0;
            var anchor;

            if (range === "365") {
                anchor = new Date(now.getFullYear(), now.getMonth() - 11, 1);
                months = 12;
            } else {
                /* All time: begin with the earliest booking on record. */
                var earliest = null;

                bookings.forEach(function (booking) {
                    var started = UI.parseDate(booking.start_time);

                    if (started && (!earliest || started < earliest)) {
                        earliest = started;
                    }
                });

                var start = earliest || now;

                var span =
                    (start.getFullYear() - now.getFullYear()) * 12 +
                    (start.getMonth() - now.getMonth()) +
                    1;

                if (!isFinite(span) || span < 1) {
                    span = 1;
                }

                /* Cap the window so a very old booking cannot render
                   thousands of empty columns. */
                if (span > 60) {
                    start = new Date(now.getFullYear(), now.getMonth() - 23, 1);
                    span = 24;
                }

                anchor = new Date(start.getFullYear(), start.getMonth(), 1);
                months = span;
            }

            for (var m = 0; m < months; m++) {
                var monthCursor = new Date(anchor.getFullYear(), anchor.getMonth() + m, 1);
                push(
                    MONTHS[monthCursor.getMonth()] +
                        (monthCursor.getMonth() === 0 ? " " + monthCursor.getFullYear() : ""),
                    monthKey(monthCursor)
                );
            }
        }

        bookings.forEach(function (booking) {
            var started = UI.parseDate(booking.start_time);

            if (!started) {
                return;
            }

            var key = dailyRange ? dayKey(started) : monthKey(started);

            if (!(key in index)) {
                return;
            }

            var amount = UI.number(booking.total_amount);
            var status = String(booking.status).toLowerCase();

            if (status === "completed") {
                buckets[index[key]].completed += amount;
            }
            if (["paid", "partially_refunded", "refunded"].indexOf(String(booking.payment_state).toLowerCase()) !== -1) {
                buckets[index[key]].confirmed += amount;
            }
        });

        return buckets;
    }

    function renderRevenueChart() {
        if (!els.revenue || !state.bookings) {
            return;
        }

        var buckets = bucketise(state.bookings, state.range);
        var hasData = buckets.some(function (bucket) {
            return bucket.completed > 0 || bucket.confirmed > 0;
        });

        if (!hasData) {
            Charts.destroy(els.revenue);
            setHtml(
                els.revenue,
                UI.stateHtml({
                    icon: "chart-line",
                    title: "No booking values in this period",
                    message:
                        state.range === "all"
                            ? "Booking values will appear here once sessions or payments are recorded."
                            : "No completed or payment-confirmed bookings fall inside the selected range.",
                    compact: true
                })
            );
            return;
        }

        var color = Charts.tokens();
        var canvas = document.createElement("canvas");
        canvas.setAttribute("role", "img");

        /* A line chart needs at least two points before it draws a line. When
           the selected window contains a single bucket, render the point
           itself so real data is never invisible. */
        var singlePoint = buckets.length === 1;
        var pointRadius = singlePoint ? 5 : 0;

        var first = buckets[0].label;
        var last = buckets[buckets.length - 1].label;

        canvas.setAttribute(
            "aria-label",
            "Booking values between " + first + " and " + last + ". Completed: " +
                UI.money(
                    buckets.reduce(function (sum, bucket) {
                        return sum + bucket.completed;
                    }, 0)
                ) +
                ". Payment-confirmed booking value (not escrow): " +
                UI.money(
                    buckets.reduce(function (sum, bucket) {
                        return sum + bucket.confirmed;
                    }, 0)
                ) + "."
        );

        setHtml(els.revenue, "");
        els.revenue.appendChild(canvas);

        var context = canvas.getContext("2d");
        var fill = context.createLinearGradient(0, 0, 0, 260);
        fill.addColorStop(0, Charts.alpha(color.amber, 0.34));
        fill.addColorStop(1, Charts.alpha(color.amber, 0));

        Charts.create(canvas, {
            type: "line",
            data: {
                labels: buckets.map(function (bucket) {
                    return bucket.label;
                }),
                datasets: [
                    {
                        label: "Completed booking value",
                        data: buckets.map(function (bucket) {
                            return bucket.completed;
                        }),
                        borderColor: color.amber,
                        backgroundColor: fill,
                        borderWidth: 2.2,
                        fill: true,
                        tension: 0.38,
                        pointRadius: pointRadius,
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: color.amber,
                        pointHoverBorderColor: color.surface,
                        pointHoverBorderWidth: 2
                    },
                    {
                        label: "Confirmed booking value (not escrow)",
                        data: buckets.map(function (bucket) {
                            return bucket.confirmed;
                        }),
                        borderColor: color.indigo,
                        backgroundColor: Charts.alpha(color.indigo, 0.14),
                        borderWidth: 2,
                        borderDash: [5, 4],
                        fill: false,
                        tension: 0.38,
                        pointRadius: pointRadius,
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: color.indigo,
                        pointHoverBorderColor: color.surface,
                        pointHoverBorderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 420 },
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: Charts.legend(color),
                    tooltip: Charts.tooltip(color, {
                        label: function (item) {
                            return item.dataset.label + ": " + UI.money(item.parsed.y);
                        }
                    })
                },
                scales: {
                    x: Charts.axis({ grid: { display: false } }, color),
                    y: Charts.axis(
                        {
                            ticks: {
                                callback: function (value) {
                                    return UI.compactMoney(value);
                                }
                            }
                        },
                        color,
                        true
                    )
                }
            }
        });
    }

    /* ---------------------------------------------------------------------
       Peak hours chart
       --------------------------------------------------------------------- */

    function renderPeakChart() {
        if (!els.peak) {
            return;
        }

        var analytics = state.analytics;

        if (!analytics) {
            return;
        }

        var peaks = analytics.peak_hours || [];

        if (!peaks.length) {
            Charts.destroy(els.peak);
            setHtml(
                els.peak,
                UI.stateHtml({
                    icon: "clock",
                    title: "No booking data yet",
                    message: "Peak hours appear once artists start booking sessions.",
                    compact: true
                })
            );

            if (els.topPeak) {
                els.topPeak.hidden = true;
            }

            return;
        }

        var color = Charts.tokens();
        var canvas = document.createElement("canvas");
        canvas.setAttribute("role", "img");

        var summary = peaks
            .map(function (row) {
                return formatHour(row.booking_hour) + ": " + row.booking_count;
            })
            .join(", ");

        canvas.setAttribute("aria-label", "Bookings by start hour. " + summary);

        setHtml(els.peak, "");
        els.peak.appendChild(canvas);

        var max = peaks.reduce(function (highest, row) {
            return Math.max(highest, UI.number(row.booking_count));
        }, 0);

        Charts.create(canvas, {
            type: "bar",
            data: {
                labels: peaks.map(function (row) {
                    return formatHour(row.booking_hour);
                }),
                datasets: [
                    {
                        label: "Sessions",
                        data: peaks.map(function (row) {
                            return UI.number(row.booking_count);
                        }),
                        backgroundColor: peaks.map(function (row, index) {
                            return index === 0 ? color.amber : Charts.alpha(color.amber, 0.34);
                        }),
                        hoverBackgroundColor: color.amber,
                        borderRadius: 7,
                        borderSkipped: false,
                        maxBarThickness: 46,
                        categoryPercentage: 0.62,
                        barPercentage: 0.7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 420 },
                plugins: {
                    legend: Charts.legend(color),
                    tooltip: Charts.tooltip(color, {
                        label: function (item) {
                            return pluralise(item.parsed.y, "session");
                        }
                    })
                },
                scales: {
                    x: Charts.axis({ grid: { display: false } }, color),
                    y: Charts.axis(
                        {
                            ticks: {
                                precision: 0,
                                stepSize: Math.max(1, Math.ceil(max / 4))
                            }
                        },
                        color,
                        true
                    )
                }
            }
        });

        if (els.topPeak && analytics.top_peak_hour) {
            els.topPeak.hidden = false;
            els.topPeak.innerHTML =
                UI.icon("bolt", 12) +
                "<span>Top: " +
                escape(formatHour(analytics.top_peak_hour.hour)) +
                " · " +
                escape(pluralise(UI.number(analytics.top_peak_hour.booking_count), "session")) +
                "</span>";
        }
    }

    /* ---------------------------------------------------------------------
       Upcoming sessions
       --------------------------------------------------------------------- */

    function renderUpcoming() {
        if (!els.upcoming || !state.bookings) {
            return;
        }

        var now = Date.now();

        var upcoming = state.bookings
            .filter(function (booking) {
                var started = UI.parseDate(booking.start_time);

                if (!started || started.getTime() < now) {
                    return false;
                }

                return ["cancelled", "completed", "disputed"].indexOf(
                    String(booking.status).toLowerCase()
                ) === -1;
            })
            .sort(function (a, b) {
                return UI.parseDate(a.start_time) - UI.parseDate(b.start_time);
            })
            .slice(0, 6);

        els.upcoming.setAttribute("aria-busy", "false");

        if (!upcoming.length) {
            setHtml(
                els.upcoming,
                UI.stateHtml({
                    icon: "calendar",
                    title: "No upcoming sessions",
                    message:
                        "Sessions artists book through your listings will appear here.",
                    compact: true
                })
            );
            return;
        }

        var html = upcoming
            .map(function (booking) {
                var started = UI.parseDate(booking.start_time);
                var ended = UI.parseDate(booking.end_time);

                return (
                    '<li class="ps-sessions__item">' +
                    '<div class="ps-sessions__date">' +
                    '<span class="ps-sessions__day">' +
                    escape(String(started.getDate())) +
                    "</span>" +
                    '<span class="ps-sessions__mon">' +
                    escape(MONTHS[started.getMonth()]) +
                    "</span>" +
                    "</div>" +
                    '<div class="ps-sessions__main">' +
                    '<span class="ps-sessions__artist">' +
                    escape(booking.artist_name || "Artist") +
                    "</span>" +
                    '<span class="ps-sessions__meta">' +
                    escape(booking.studio_name || "Studio") +
                    " · " +
                    escape(UI.time(booking.start_time)) +
                    (ended ? " – " + escape(UI.time(booking.end_time)) : "") +
                    "</span>" +
                    '<span class="ps-sessions__status">' +
                    UI.statusBadge(booking.status) +
                    "</span>" +
                    "</div>" +
                    '<div class="ps-sessions__amount">' +
                    escape(UI.money(booking.total_amount)) +
                    "</div>" +
                    "</li>"
                );
            })
            .join("");

        setHtml(els.upcoming, html);
    }

    /* ---------------------------------------------------------------------
       Error rendering
       --------------------------------------------------------------------- */

    function renderChartError(host, error, retry) {
        if (!host) {
            return;
        }

        Charts.destroy(host);
        UI.renderError(host, error, retry);
    }

    function renderPeakError(error) {
        renderChartError(
            els.peak,
            error,
            function () {
                loadAnalytics();
            }
        );
    }

    function renderUpcomingError(error) {
        if (!els.upcoming) {
            return;
        }

        els.upcoming.setAttribute("aria-busy", "false");
        setHtml(
            els.upcoming,
            UI.stateHtml({
                tone: "error",
                icon: "alert",
                title: "Could not load sessions",
                message: (error && error.message) || "Please try again.",
                actionLabel: "Try again",
                compact: true
            })
        );

        var button = UI.qs("[data-state-action]", els.upcoming);

        if (button) {
            button.addEventListener("click", function () {
                loadBookings();
            });
        }
    }

    /* ---------------------------------------------------------------------
       Wiring
       --------------------------------------------------------------------- */

    function cacheElements() {
        els.summary = UI.qs("[data-dashboard-summary]");
        els.statBookings = UI.qs('[data-stat="bookings"]');
        els.statRevenue = UI.qs('[data-stat="revenue"]');
        els.statEscrow = UI.qs('[data-stat="escrow"]');
        els.statStudios = UI.qs('[data-stat="studios"]');
        els.statFootBookings = UI.qs('[data-stat-foot="bookings"]');
        els.statFootRevenue = UI.qs('[data-stat-foot="revenue"]');
        els.statFootEscrow = UI.qs('[data-stat-foot="escrow"]');
        els.statFootStudios = UI.qs('[data-stat-foot="studios"]');
        els.revenue = UI.qs("[data-chart-revenue]");
        els.peak = UI.qs("[data-chart-peak]");
        els.upcoming = UI.qs("[data-upcoming-list]");
        els.topPeak = UI.qs("[data-top-peak]");
        els.range = UI.qs("[data-range-select]");
    }

    function init() {
        cacheElements();
        wireOwnerPayouts();
        loadOwnerPayouts();

        if (els.range) {
            els.range.value = state.range;

            els.range.addEventListener("change", function () {
                state.range = els.range.value;

                if (!state.bookings) {
                    loadBookings();
                    return;
                }

                renderRevenueChart();
            });
        }

        /* Charts must be rebuilt with the new palette after a theme change. */
        Charts.onThemeChange(function () {
            renderRevenueChart();
            renderPeakChart();
        });

        Promise.all([loadAnalytics(), loadBookings(), loadStudios()]);
    }

    global.ParadiseDashboard = {
        init: init,
        bucketise: bucketise,
        compareMonths: compareMonths
    };

    UI.onReady(init);
})(window);