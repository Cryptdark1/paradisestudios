/**
 * Paradise Studios — artist shared render helpers.
 *
 * Small, dependency-free view helpers shared by several artist pages. They sit
 * on top of UI (the shared component kit) and deliberately hold no state, so
 * every artist page renders the same markup for the same data.
 */
(function (global) {
    "use strict";

    var UI = global.UI;
    var escape = UI.escape;

    var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

    /**
     * Renders the Buzz XP donut.
     *
     * @param {object} data  result of GET /api/buzz.php
     * @param {number} size  ring diameter in px
     */
    function buzzRing(data, size) {
        var diameter = size || 92;
        var radius = diameter / 2;
        var stroke = 9;
        var circumference = 2 * Math.PI * (radius - stroke / 2);
        var percent = Math.max(0, Math.min(100, UI.number(data.progress_percent)));

        var offset = circumference * (1 - percent / 100);

        return (
            '<svg viewBox="0 0 ' + diameter + " " + diameter + '" ' +
            'width="100%" height="100%" aria-hidden="true" focusable="false">' +
            '<circle class="ps-buzz__track" cx="' + radius + '" cy="' + radius + '" r="' +
            (radius - stroke / 2) + '"></circle>' +
            '<circle class="ps-buzz__fill" cx="' + radius + '" cy="' + radius + '" r="' +
            (radius - stroke / 2) + '" stroke-dasharray="' +
            circumference.toFixed(2) + '" stroke-dashoffset="' + offset.toFixed(2) + '"></circle>' +
            "</svg>"
        );
    }

    /**
     * Human summary of the rank position, e.g. "350 XP to City Star".
     * Returns "" at the top rank, where there is no next target.
     */
    function buzzNext(data) {
        if (!data.next_level) {
            return "Top rank reached — you are a " + data.level + ".";
        }

        return (
            "<b>" +
            escape(UI.number(data.xp_remaining).toLocaleString(UI.LOCALE)) +
            " XP</b> to reach " +
            escape(data.next_level) +
            "."
        );
    }

    /**
     * Track status -> badge tone/label. Mirrors the backend enum
     * tracks.status: 'recording' | 'released'.
     */
    function trackStatusMeta(status) {
        var key = String(status || "").toLowerCase();

        if (key === "released") {
            return { label: "Released", tone: "success", icon: "check-circle" };
        }

        return { label: "Recording", tone: "warning", icon: "mic" };
    }

    /**
     * Decorative waveform bars.
     *
     * The database stores no waveform data, so the bar heights are derived
     * deterministically from the track id. This is presentation only: it is
     * marked aria-hidden and is never presented as real audio analysis.
     */
    function waveform(seed, bars) {
        var count = bars || 18;
        var value = Math.max(1, UI.number(seed));
        var html = "";

        for (var i = 0; i < count; i++) {
            /* A cheap deterministic pseudo-random sequence. */
            var n = ((value * 9301 + (i + 1) * 49297) % 233280) / 233280;
            var height = Math.round(26 + n * 62);
            var on = i < Math.round(count * 0.42);

            html +=
                '<span' +
                (on ? ' class="is-on"' : "") +
                ' style="height:' + height + '%"></span>';
        }

        return '<span class="ps-wave" aria-hidden="true">' + html + "</span>";
    }

    /**
     * One track row.
     *
     * @param {object} track   row from GET /api/tracks.php
     * @param {object} options  { showRelease: boolean, onReleaseAttribute: string }
     */
    function trackRow(track, options) {
        var opts = options || {};
        var meta = trackStatusMeta(track.status);

        var meta_ =
            (track.studio_name || "Studio") +
            " · uploaded " +
            UI.date(track.created_at);

        var right = UI.statusBadge(track.status);

        /* XP is only ever shown when the backend actually recorded it. */
        if (UI.number(track.clout_earned) > 0) {
            right +=
                '<span class="ps-xp-pill">' +
                UI.icon("bolt", 11) +
                escape(UI.number(track.clout_earned)) +
                " XP</span>";
        }

        /* Released tracks have no further action. */
        if (opts.showRelease && String(track.status).toLowerCase() === "recording") {
            right +=
                '<button type="button" class="ps-btn ps-btn--primary ps-btn--sm" ' +
                'data-action="release" data-track-id="' +
                UI.number(track.id) +
                '" aria-label="Release ' +
                escape(track.title || "this track") +
                '">Release</button>';
        }

        return (
            '<div class="ps-track" data-track-id="' +
            UI.number(track.id) +
            '">' +
            '<span class="ps-track__art" aria-hidden="true">' +
            UI.icon("music", 18) +
            "</span>" +
            '<div class="ps-track__main">' +
            '<span class="ps-track__title">' +
            escape(track.title || "Untitled track") +
            "</span>" +
            '<span class="ps-track__meta">' +
            escape(meta_) +
            "</span>" +
            "</div>" +
            waveform(track.id) +
            '<div class="ps-track__right">' +
            right +
            "</div>" +
            "</div>"
        );
    }

    /**
     * A "bookings" row used by the dashboard's recent table.
     */
    function bookingRow(booking) {
        var started = UI.parseDate(booking.start_time);
        var ended = UI.parseDate(booking.end_time);

        return (
            "<tr>" +
            '<td data-label="Name">' +
            '<span class="ps-stateline__who">' +
            '<span class="ps-avatar ps-avatar--sm ps-avatar--artist" aria-hidden="true">' +
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
            "</td>" +
            '<td class="num" data-label="Time">' +
            escape(started ? UI.time(booking.start_time) : "—") +
            (ended ? " – " + escape(UI.time(booking.end_time)) : "") +
            "</td>" +
            '<td class="num" data-label="Price">' +
            escape(UI.money(booking.total_amount)) +
            "</td>" +
            '<td data-label="Status">' +
            UI.statusBadge(booking.status) +
            "</td>" +
            '<td class="actions" data-label="Actions">' +
            '<a class="ps-btn ps-btn--secondary ps-btn--sm" href="' +
            global.location.pathname.replace(/[^/]*$/, "bookings.php") +
            '?booking=' +
            UI.number(booking.id) +
            '">View</a>' +
            "</td>" +
            "</tr>"
        );
    }

    /** "in 3 days" / "in 5 hours" for an upcoming session. */
    function countdown(value) {
        var target = UI.parseDate(value);

        if (!target) {
            return "";
        }

        var diff = target.getTime() - Date.now();

        if (diff <= 0) {
            return "Started";
        }

        var minutes = Math.floor(diff / 60000);
        var hours = Math.floor(minutes / 60);
        var days = Math.floor(hours / 24);

        if (days >= 1) {
            return "In " + days + (days === 1 ? " day" : " days");
        }
        if (hours >= 1) {
            return "In " + hours + (hours === 1 ? " hour" : " hours");
        }
        return "In " + Math.max(1, minutes) + " min";
    }

    /**
     * Group bookings into the three buckets the brief asks for.
     * Boundaries come from the backend status enum, not from guessing.
     */
    function bucketBookings(bookings, now) {
        var today = now ? new Date(now.getTime()) : new Date();
        today.setHours(0, 0, 0, 0);

        var buckets = { upcoming: [], past: [], cancelled: [] };

        (bookings || []).forEach(function (booking) {
            var status = String(booking.status).toLowerCase();
            var started = UI.parseDate(booking.start_time);

            if (status === "cancelled") {
                buckets.cancelled.push(booking);
                return;
            }

            var isFuture = started ? started.getTime() >= today.getTime() : false;

            if (isFuture && status !== "completed") {
                buckets.upcoming.push(booking);
            } else {
                buckets.past.push(booking);
            }
        });

        var bySoonest = function (a, b) {
            return (UI.parseDate(a.start_time) || 0) - (UI.parseDate(b.start_time) || 0);
        };

        var byLatest = function (a, b) {
            return (UI.parseDate(b.start_time) || 0) - (UI.parseDate(a.start_time) || 0);
        };

        buckets.upcoming.sort(bySoonest);
        buckets.past.sort(byLatest);
        buckets.cancelled.sort(byLatest);

        return buckets;
    }

    global.ArtistUI = {
        MONTHS: MONTHS,
        buzzRing: buzzRing,
        buzzNext: buzzNext,
        trackStatusMeta: trackStatusMeta,
        waveform: waveform,
        trackRow: trackRow,
        bookingRow: bookingRow,
        countdown: countdown,
        bucketBookings: bucketBookings
    };
})(window);