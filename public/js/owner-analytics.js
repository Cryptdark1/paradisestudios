/**
 * Paradise Studios — Owner analytics.
 *
 * Data sources:
 *   GET /api/owner-analytics.php  -> totals + peak hours
 *   GET /api/owner-bookings.php   -> status mix + revenue series
 *
 * Nothing is hardcoded: if an endpoint fails, the affected panel shows an
 * error state with a working retry rather than invented numbers.
 */
(function (global) {
    "use strict";

    var API = global.API;
    var UI = global.UI;
    var Charts = global.ParadiseCharts;
    var escape = UI.escape;

    var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

    /* Order in which booking statuses are presented. */
    var STATUS_ORDER = [
        "pending",
        "confirmed",
        "escrow_held",
        "in_session",
        "completed",
        "cancelled",
        "disputed"
    ];

    var state = {
        analytics: null,
        bookings: null,
        range: "all"
    };

    var els = {};

    /* ---------------------------------------------------------------------
       Helpers
       --------------------------------------------------------------------- */

    function setHtml(node, html) {
        if (node) {
            node.innerHTML = html;
        }
    }

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
        var normalised = ((UI.number(hour) % 24) + 24) % 24;
        var suffix = normalised >= 12 ? "PM" : "AM";
        var display = normalised % 12 === 0 ? 12 : normalised % 12;
        return display + ":00 " + suffix;
    }

    function pluralise(count, singular, plural) {
        var value = UI.number(count);
        return value + " " + (value === 1 ? singular : plural || singular + "s");
    }

    function moneyTotal(rows) {
        return rows.reduce(function (total, booking) {
            return total + UI.number(booking.total_amount);
        }, 0);
    }

    /* ---------------------------------------------------------------------
       Headline figures
       --------------------------------------------------------------------- */

    function renderStats() {
        var analytics = state.analytics;

        if (!analytics) {
            return;
        }

        setHtml(els.statBookings, UI.number(analytics.booking_count).toLocaleString(UI.LOCALE));

        var nonCancelled = state.bookings
            ? state.bookings.filter(function (booking) {
                  return String(booking.status).toLowerCase() !== "cancelled";
              })
            : null;

        els.footBookings.innerHTML = nonCancelled
            ? "<span>" +
              escape(
                  pluralise(nonCancelled.length, "active booking") +
                      " · " +
                      /* "cancelled" is already a plural/adjective form. */
                      pluralise(
                          UI.number(analytics.booking_count) - nonCancelled.length,
                          "cancelled",
                          "cancelled"
                      )
              ) +
              "</span>"
            : '<span class="ps-muted">Across all studios</span>';

        setHtml(els.statRevenue, UI.compactMoney(analytics.gross_revenue));

        var completed = state.bookings
            ? state.bookings.filter(function (booking) {
                  return String(booking.status).toLowerCase() === "completed";
              })
            : null;

        els.footRevenue.innerHTML = completed
            ? "<span>" +
              escape(pluralise(completed.length, "completed session") + " released") +
              "</span>"
            : '<span class="ps-muted">Released from completed sessions</span>';

        setHtml(els.statEscrow, UI.compactMoney(analytics.pending_escrow));

        var held = state.bookings
            ? state.bookings.filter(function (booking) {
                  return String(booking.status).toLowerCase() === "escrow_held";
              })
            : null;

        els.footEscrow.innerHTML = held
            ? "<span>" + escape(pluralise(held.length, "session") + " in escrow") + "</span>"
            : '<span class="ps-muted">Nothing held right now</span>';

        if (analytics.top_peak_hour) {
            setHtml(els.statTopHour, escape(formatHour(analytics.top_peak_hour.hour)));

            els.footTopHour.innerHTML =
                "<span>" +
                escape(
                    "Busiest slot · " +
                        pluralise(analytics.top_peak_hour.booking_count, "session")
                ) +
                "</span>";
        } else {
            setHtml(els.statTopHour, "—");
            els.footTopHour.innerHTML = '<span class="ps-muted">No booking data yet</span>';
        }
    }

    function renderSummary() {
        if (!els.summary) {
            return;
        }

        var parts = [];

        if (state.analytics) {
            parts.push(pluralise(state.analytics.booking_count, "booking") + " all time");
        }

        if (state.analytics && state.analytics.top_peak_hour) {
            parts.push("busiest at " + formatHour(state.analytics.top_peak_hour.hour));
        }

        els.summary.textContent = parts.length
            ? parts.join(" · ") + "."
            : "Here is how your studios are performing.";
    }

    /* ---------------------------------------------------------------------
       Peak hours
       --------------------------------------------------------------------- */

    function renderPeakChart() {
        if (!els.peak || !state.analytics) {
            return;
        }

        var peaks = state.analytics.peak_hours || [];

        if (!peaks.length) {
            Charts.destroy(els.peak);
            setHtml(
                els.peak,
                UI.stateHtml({
                    icon: "clock",
                    title: "No peak hours yet",
                    message: "This chart fills in once sessions have been booked.",
                    compact: true
                })
            );
            return;
        }

        var color = Charts.tokens();
        var canvas = document.createElement("canvas");
        canvas.setAttribute("role", "img");
        canvas.setAttribute(
            "aria-label",
            "Sessions by start hour. " +
                peaks
                    .map(function (row) {
                        return formatHour(row.booking_hour) + ": " + UI.number(row.booking_count);
                    })
                    .join(", ")
        );

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
                            return index === 0 ? color.amber : Charts.alpha(color.amber, 0.38);
                        }),
                        hoverBackgroundColor: color.amber,
                        borderRadius: 8,
                        borderSkipped: false,
                        /* Keep the bars slender even when only a few hours
                           have data across a very wide chart. */
                        maxBarThickness: 44,
                        categoryPercentage: 0.62,
                        barPercentage: 0.7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 450 },
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
    }

    function renderPeakTable() {
        if (!els.peakRows) {
            return;
        }

        els.peakRows.setAttribute("aria-busy", "false");

        if (!state.analytics) {
            return;
        }

        var peaks = state.analytics.peak_hours || [];

        if (!peaks.length) {
            setHtml(
                els.peakRows,
                '<tr><td colspan="3">' +
                    UI.stateHtml({
                        icon: "clock",
                        title: "No demand data yet",
                        message: "Hours will be listed once sessions have been booked.",
                        compact: true
                    }) +
                    "</td></tr>"
            );

            setHtml(els.peakTotal, "");
            return;
        }

        var total = peaks.reduce(function (sum, row) {
            return sum + UI.number(row.booking_count);
        }, 0);

        var topHour = state.analytics.top_peak_hour
            ? UI.number(state.analytics.top_peak_hour.hour)
            : null;

        setHtml(
            els.peakTotal,
            total + (total === 1 ? " session scheduled" : " sessions scheduled")
        );

        setHtml(
            els.peakRows,
            peaks
                .map(function (row) {
                    var hour = UI.number(row.booking_hour);
                    var count = UI.number(row.booking_count);
                    var share = total ? (count / total) * 100 : 0;
                    var isTop = hour === topHour;

                    return (
                        "<tr>" +
                        '<td><span class="hour-cell' +
                        (isTop ? " is-top" : "") +
                        '">' +
                        UI.icon(isTop ? "bolt" : "clock", 15) +
                        "<strong>" +
                        escape(formatHour(hour)) +
                        "</strong>" +
                        (isTop
                            ? '<span class="ps-badge ps-badge--warning">Top</span>'
                            : "") +
                        "</span></td>" +
                        '<td class="num">' +
                        escape(String(count)) +
                        "</td>" +
                        "<td>" +
                        '<span class="ps-share">' +
                        '<span class="ps-share__track">' +
                        '<span class="ps-share__fill' +
                        (isTop ? "" : " ps-share__fill--muted") +
                        '" style="width:' +
                        share.toFixed(1) +
                        '%"></span>' +
                        "</span>" +
                        '<span class="ps-share__value">' +
                        share.toFixed(0) +
                        "%</span>" +
                        "</span></td>" +
                        "</tr>"
                    );
                })
                .join("")
        );
    }

    /* ---------------------------------------------------------------------
       Status mix
       --------------------------------------------------------------------- */

    function statusBuckets() {
        var rows = state.bookings || [];
        var counts = {};

        rows.forEach(function (booking) {
            var status = String(booking.status).toLowerCase();
            counts[status] = (counts[status] || 0) + 1;
        });

        return STATUS_ORDER.filter(function (status) {
            return counts[status];
        }).map(function (status) {
            var meta = UI.statusMeta(status);
            var amount = moneyTotal(
                rows.filter(function (booking) {
                    return String(booking.status).toLowerCase() === status;
                })
            );

            return { status: status, label: meta.label, count: counts[status], amount: amount };
        });
    }

    function renderStatusChart() {
        if (!els.status || !state.bookings) {
            return;
        }

        var buckets = statusBuckets();

        if (!buckets.length) {
            Charts.destroy(els.status);
            setHtml(
                els.status,
                UI.stateHtml({
                    icon: "ticket",
                    title: "No bookings yet",
                    message: "The status mix appears once sessions are booked.",
                    compact: true
                })
            );
            setHtml(els.statusLegend, "");
            return;
        }

        var color = Charts.tokens();
        var total = buckets.reduce(function (sum, bucket) {
            return sum + bucket.count;
        }, 0);

        var canvas = document.createElement("canvas");
        canvas.setAttribute("role", "img");
        canvas.setAttribute(
            "aria-label",
            "Booking status mix. " +
                buckets
                    .map(function (bucket) {
                        return bucket.label + ": " + bucket.count;
                    })
                    .join(", ")
        );

        setHtml(els.status, "");
        els.status.appendChild(canvas);

        Charts.create(canvas, {
            type: "doughnut",
            data: {
                labels: buckets.map(function (bucket) {
                    return bucket.label;
                }),
                datasets: [
                    {
                        data: buckets.map(function (bucket) {
                            return bucket.count;
                        }),
                        backgroundColor: buckets.map(function (bucket) {
                            return color.status[bucket.status] || color.soft;
                        }),
                        borderColor: color.surface,
                        borderWidth: 3,
                        hoverOffset: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 450 },
                cutout: "62%",
                plugins: {
                    legend: Charts.legend(color),
                    tooltip: Charts.tooltip(color, {
                        label: function (item) {
                            var bucket = buckets[item.dataIndex];
                            return (
                                item.label + ": " + item.parsed + " (" +
                                Math.round((item.parsed / total) * 100) + "%)"
                            );
                        }
                    })
                }
            }
        });

        setHtml(
            els.statusLegend,
            buckets
                .map(function (bucket) {
                    var share = total ? Math.round((bucket.count / total) * 100) : 0;

                    return (
                        '<li class="ps-legend__item">' +
                        '<span class="ps-legend__swatch" style="background:' +
                        escape(color.status[bucket.status] || color.soft) +
                        '"></span>' +
                        '<span class="ps-legend__text">' +
                        '<span class="ps-legend__label">' +
                        escape(bucket.label) +
                        "</span>" +
                        '<span class="ps-legend__value">' +
                        escape(UI.money(bucket.amount)) +
                        " · " +
                        share +
                        "%</span>" +
                        "</span>" +
                        '<span class="ps-legend__count">' +
                        bucket.count +
                        "</span>" +
                        "</li>"
                    );
                })
                .join("")
        );
    }

    /* ---------------------------------------------------------------------
       Revenue over time
       --------------------------------------------------------------------- */

    function bucketise(bookings, range) {
        var now = new Date();
        var buckets = [];
        var index = {};

        function push(label, key) {
            buckets.push({ label: label, released: 0, escrow: 0 });
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
            daily(30, 0);
        } else {
            var months = 0;
            var anchor;

            if (range === "365") {
                anchor = new Date(now.getFullYear(), now.getMonth() - 11, 1);
                months = 12;
            } else {
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
                if (span > 60) {
                    start = new Date(now.getFullYear(), now.getMonth() - 23, 1);
                    span = 24;
                }

                anchor = new Date(start.getFullYear(), start.getMonth(), 1);
                months = span;
            }

            for (var m = 0; m < months; m++) {
                var cursorMonth = new Date(anchor.getFullYear(), anchor.getMonth() + m, 1);
                push(
                    MONTHS[cursorMonth.getMonth()] +
                        (cursorMonth.getMonth() === 0 ? " " + cursorMonth.getFullYear() : ""),
                    monthKey(cursorMonth)
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
                buckets[index[key]].released += amount;
            } else if (status === "escrow_held") {
                buckets[index[key]].escrow += amount;
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
            return bucket.released > 0 || bucket.escrow > 0;
        });

        if (!hasData) {
            Charts.destroy(els.revenue);
            setHtml(
                els.revenue,
                UI.stateHtml({
                    icon: "chart-line",
                    title: "No revenue in this period",
                    message:
                        "Nothing completed or held in escrow falls inside the selected range.",
                    compact: true
                })
            );
            return;
        }

        var color = Charts.tokens();
        var canvas = document.createElement("canvas");
        canvas.setAttribute("role", "img");

        var singlePoint = buckets.length === 1;

        canvas.setAttribute(
            "aria-label",
            "Revenue from " +
                buckets[0].label +
                " to " +
                buckets[buckets.length - 1].label +
                ". Released: " +
                UI.money(
                    buckets.reduce(function (sum, bucket) {
                        return sum + bucket.released;
                    }, 0)
                ) +
                ". Held in escrow: " +
                UI.money(
                    buckets.reduce(function (sum, bucket) {
                        return sum + bucket.escrow;
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
                        label: "Released",
                        data: buckets.map(function (bucket) {
                            return bucket.released;
                        }),
                        borderColor: color.amber,
                        backgroundColor: fill,
                        borderWidth: 2.2,
                        fill: true,
                        tension: 0.38,
                        pointRadius: singlePoint ? 5 : 0,
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: color.amber,
                        pointHoverBorderColor: color.surface,
                        pointHoverBorderWidth: 2
                    },
                    {
                        label: "Held in escrow",
                        data: buckets.map(function (bucket) {
                            return bucket.escrow;
                        }),
                        borderColor: color.indigo,
                        backgroundColor: Charts.alpha(color.indigo, 0.14),
                        borderWidth: 2,
                        borderDash: [5, 4],
                        fill: false,
                        tension: 0.38,
                        pointRadius: singlePoint ? 5 : 0,
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
                animation: { duration: 450 },
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
       Loading & errors
       --------------------------------------------------------------------- */

    function renderAnalyticsError(error) {
        [
            [els.statBookings, "bookings"],
            [els.statRevenue, "revenue"],
            [els.statEscrow, "escrow"],
            [els.statTopHour, "tophour"]
        ].forEach(function (pair) {
            if (pair[0]) {
                pair[0].innerHTML = '<span class="ps-stat__value-error">—</span>';
                pair[0].title = (error && error.message) || "Could not load";
            }
        });

        if (els.peak) {
            UI.renderError(els.peak, error, function () {
                loadAnalytics();
            });
        }
    }

    function renderBookingsError(error) {
        if (els.revenue) {
            UI.renderError(els.revenue, error, function () {
                loadBookings();
            });
        }

        if (els.status) {
            UI.renderError(els.status, error, function () {
                loadBookings();
            });
        }

        if (els.peakTotal) {
            setHtml(els.peakTotal, "");
        }
    }

    function loadAnalytics() {
        return API.owner
            .analytics()
            .then(function (payload) {
                state.analytics = payload.data || {};
                renderStats();
                renderPeakChart();
                renderPeakTable();
                renderSummary();
            })
            .catch(function (error) {
                state.analytics = null;
                renderAnalyticsError(error);
                renderSummary();
            });
    }

    function loadBookings() {
        return API.owner
            .bookings()
            .then(function (payload) {
                state.bookings = payload.data || [];
                renderStats();
                renderStatusChart();
                renderRevenueChart();
                renderSummary();
            })
            .catch(function (error) {
                state.bookings = null;
                renderBookingsError(error);
                renderSummary();
            });
    }

    function cacheElements() {
        els.summary = UI.qs("[data-analytics-summary]");
        els.statBookings = UI.qs('[data-stat="bookings"]');
        els.statRevenue = UI.qs('[data-stat="revenue"]');
        els.statEscrow = UI.qs('[data-stat="escrow"]');
        els.statTopHour = UI.qs('[data-stat="tophour"]');
        els.footBookings = UI.qs('[data-stat-foot="bookings"]');
        els.footRevenue = UI.qs('[data-stat-foot="revenue"]');
        els.footEscrow = UI.qs('[data-stat-foot="escrow"]');
        els.footTopHour = UI.qs('[data-stat-foot="tophour"]');
        els.peak = UI.qs("[data-chart-peak]");
        els.peakRows = UI.qs("[data-peak-rows]");
        els.peakTotal = UI.qs("[data-peak-total]");
        els.status = UI.qs("[data-chart-status]");
        els.statusLegend = UI.qs("[data-status-legend]");
        els.revenue = UI.qs("[data-chart-revenue]");
        els.range = UI.qs("[data-range-select]");
    }

    function init() {
        cacheElements();

        if (els.range) {
            els.range.value = state.range;

            els.range.addEventListener("change", function () {
                state.range = els.range.value;

                if (state.bookings) {
                    renderRevenueChart();
                }
            });
        }

        var reload = UI.qs("[data-reload]");

        if (reload) {
            reload.addEventListener("click", function () {
                UI.setButtonLoading(reload, true, "Refreshing…");

                Promise.all([loadAnalytics(), loadBookings()]).then(function () {
                    UI.setButtonLoading(reload, false);
                    UI.toast("Analytics refreshed with the latest data.", {
                        type: "success",
                        title: "Up to date"
                    });
                });
            });
        }

        /* Rebuild charts with the correct palette after a theme change. */
        Charts.onThemeChange(function () {
            renderPeakChart();
            renderStatusChart();
            renderRevenueChart();
        });

        Promise.all([loadAnalytics(), loadBookings()]);
    }

    global.ParadiseAnalytics = {
        init: init,
        statusBuckets: statusBuckets,
        bucketise: bucketise
    };

    UI.onReady(init);
})(window);