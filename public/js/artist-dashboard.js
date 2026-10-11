/**
 * Paradise Studios — Artist dashboard.
 *
 * Loads three existing endpoints once and renders the page from real data:
 *   GET /api/buzz.php        XP, rank, next-rank target
 *   GET /api/my-bookings.php upcoming + recent activity
 *   GET /api/tracks.php      recent / released tracks
 *
 * Each section degrades independently: if one endpoint fails, that card shows a
 * retry state instead of collapsing the page.
 */
(function (global) {
    "use strict";

    var API = global.ArtistAPI;
    var UI = global.UI;
    var A = global.ArtistUI;
    var escape = UI.escape;

    var state = {
        buzz: null,
        bookings: null,
        tracks: null
    };

    var els = {};

    /* ---------------------------------------------------------------------
       Buzz XP
       --------------------------------------------------------------------- */

    function renderBuzz() {
        if (!els.buzz || !state.buzz) {
            return;
        }

        var data = state.buzz;

        UI.qs("[data-buzz-xp]").textContent = UI.number(data.xp).toLocaleString(UI.LOCALE);

        /*
         * Inject the ring on first render.
         *
         * The ring's centre holds the XP total, which is rendered by PHP and
         * must survive the SVG injection. The SVG is therefore prepended and the
         * existing centre markup is left untouched.
         */
        var ring = UI.qs(".ps-buzz__ring", els.buzz);

        if (ring && !ring.querySelector("svg")) {
            ring.insertAdjacentHTML("afterbegin", A.buzzRing(data, 92));
        }

        UI.qs("[data-buzz-level]").textContent = data.level || "Local";

        var badge = UI.qs("[data-buzz-level-badge]");

        if (badge) {
            badge.hidden = false;
            badge.className = "ps-badge ps-badge--warning";
            badge.innerHTML =
                UI.icon("bolt", 12) +
                "<span>" +
                escape(UI.number(Math.round(data.progress_percent))) +
                "% of the way</span>";
        }

        UI.qs("[data-buzz-next]").innerHTML = A.buzzNext(data);

        /* Reflect the rank in the hero line. */
        if (els.heroSub) {
            els.heroSub.textContent =
                "You are a " +
                (data.level || "Local") +
                " with " +
                UI.number(data.xp).toLocaleString(UI.LOCALE) +
                " Buzz XP" +
                (data.next_level ? ", " + UI.number(data.xp_remaining).toLocaleString(UI.LOCALE) + " XP to " + data.next_level + "." : ".");
        }
    }

    function renderBuzzError(error) {
        if (!els.buzz) {
            return;
        }

        UI.qs("[data-buzz-xp]").textContent = "—";
        UI.qs("[data-buzz-level]").textContent = "Unavailable";
        UI.qs("[data-buzz-next]").textContent =
            (error && error.message) || "Buzz XP could not be loaded.";

        var retry = UI.qs("[data-buzz-next]");
        if (retry) {
            var button = document.createElement("button");
            button.type = "button";
            button.className = "ps-btn ps-btn--ghost ps-btn--sm";
            button.style.marginTop = "8px";
            button.textContent = "Try again";
            button.addEventListener("click", loadBuzz);
            retry.parentNode.appendChild(button);
        }
    }

    /* ---------------------------------------------------------------------
       Recent bookings
       --------------------------------------------------------------------- */

    function renderRecent() {
        if (!els.recentRows || !state.bookings) {
            return;
        }

        els.recentRows.setAttribute("aria-busy", "false");

        var buckets = A.bucketBookings(state.bookings);

        /* Prefer sessions that are still to come, then the most recent past. */
        var rows = buckets.upcoming.slice(0, 4);

        if (rows.length < 4) {
            rows = rows.concat(buckets.past.slice(0, 4 - rows.length));
        }

        if (!rows.length) {
            els.recentRows.innerHTML =
                '<tr><td colspan="6">' +
                UI.stateHtml({
                    icon: "calendar",
                    title: "No bookings yet",
                    message: "Book a studio session and it will show up here.",
                    compact: true,
                    actionHref: global.location.pathname.replace(/[^/]*$/, "studios.php"),
                    actionLabel: "Discover studios"
                }) +
                "</td></tr>";
            return;
        }

        els.recentRows.innerHTML = rows.map(A.bookingRow).join("");
    }

    function renderRecentError(error) {
        if (!els.recentRows) {
            return;
        }

        els.recentRows.setAttribute("aria-busy", "false");

        UI.renderError(els.recentRows, error, function () {
            /* renderError writes into a <tbody>, so render a full row. */
            loadBookings();
        });
    }

    /* ---------------------------------------------------------------------
       Upcoming session
       --------------------------------------------------------------------- */

    function renderUpcoming() {
        if (!els.upcoming || !state.bookings) {
            return;
        }

        els.upcoming.setAttribute("aria-busy", "false");

        var buckets = A.bucketBookings(state.bookings);
        var next = buckets.upcoming[0];

        if (!next) {
            els.upcoming.innerHTML = UI.stateHtml({
                icon: "inbox",
                title: "Nothing scheduled",
                message: "Your next confirmed session will appear here.",
                compact: true,
                actionHref: global.location.pathname.replace(/[^/]*$/, "studios.php"),
                actionLabel: "Discover studios"
            });
            return;
        }

        var started = UI.parseDate(next.start_time);
        var ended = UI.parseDate(next.end_time);

        els.upcoming.innerHTML =
            '<div class="ps-upcoming__item">' +
            '<span class="ps-upcoming__studio">' +
            escape(next.studio_name || "Studio") +
            "</span>" +
            '<span class="ps-upcoming__when">' +
            UI.icon("calendar", 14) +
            "<span>" +
            escape(UI.friendlyDate(next.start_time)) +
            " · " +
            escape(UI.date(next.start_time)) +
            " · " +
            escape(UI.time(next.start_time)) +
            (ended ? " – " + escape(UI.time(next.end_time)) : "") +
            "</span></span>" +
            (next.studio_location
                ? '<span class="ps-upcoming__when">' +
                  UI.icon("map-pin", 14) +
                  "<span>" +
                  escape(next.studio_location) +
                  "</span></span>"
                : "") +
            '<span class="ps-upcoming__countdown">' +
            UI.icon("clock", 11) +
            "<span>" +
            escape(A.countdown(next.start_time)) +
            "</span></span>" +
            '<div class="ps-upcoming__foot">' +
            '<span class="ps-upcoming__price">' +
            escape(UI.money(next.total_amount)) +
            "</span>" +
            '<span class="ps-upcoming__actions">' +
            UI.statusBadge(next.status) +
            '<a class="ps-btn ps-btn--secondary ps-btn--sm" href="' +
            global.location.pathname.replace(/[^/]*$/, "bookings.php") +
            '?booking=' +
            UI.number(next.id) +
            '">Manage</a>' +
            "</span>" +
            "</div>" +
            "</div>";

        void started;
    }

    function renderUpcomingError(error) {
        if (!els.upcoming) {
            return;
        }

        els.upcoming.setAttribute("aria-busy", "false");

        els.upcoming.innerHTML = UI.stateHtml({
            tone: "error",
            icon: "alert",
            title: "Could not load sessions",
            message: (error && error.message) || "Please try again.",
            actionLabel: "Try again",
            compact: true
        });

        var button = UI.qs("[data-state-action]", els.upcoming);

        if (button) {
            button.addEventListener("click", loadBookings);
        }
    }

    /* ---------------------------------------------------------------------
       Tracks
       --------------------------------------------------------------------- */

    function renderTracks() {
        if (!els.tracks || !state.tracks) {
            return;
        }

        els.tracks.setAttribute("aria-busy", "false");

        if (!state.tracks.length) {
            els.tracks.innerHTML = UI.stateHtml({
                icon: "music",
                title: "No tracks yet",
                message:
                    "Complete a studio session, then upload the recording from a completed booking.",
                compact: true,
                actionHref: global.location.pathname.replace(/[^/]*$/, "tracks.php") + "?action=upload",
                actionLabel: "Upload a track"
            });
            return;
        }

        /* Dashboard shows the three most recent; the library shows all. */
        els.tracks.innerHTML = state.tracks
            .slice(0, 3)
            .map(function (track) {
                return A.trackRow(track, { showRelease: false });
            })
            .join("");
    }

    function renderTracksError(error) {
        if (!els.tracks) {
            return;
        }

        els.tracks.setAttribute("aria-busy", "false");

        els.tracks.innerHTML = UI.stateHtml({
            tone: "error",
            icon: "alert",
            title: "Could not load tracks",
            message: (error && error.message) || "Please try again.",
            actionLabel: "Try again",
            compact: true
        });

        var button = UI.qs("[data-state-action]", els.tracks);

        if (button) {
            button.addEventListener("click", loadTracks);
        }
    }

    /* ---------------------------------------------------------------------
       Loading
       --------------------------------------------------------------------- */

    function loadBuzz() {
        return API.buzz()
            .then(function (payload) {
                state.buzz = payload.data || {};
                renderBuzz();
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
                renderRecent();
                renderUpcoming();
            })
            .catch(function (error) {
                state.bookings = null;
                renderRecentError(error);
                renderUpcomingError(error);
            });
    }

    function loadTracks() {
        return API.myTracks()
            .then(function (payload) {
                state.tracks = payload.data || [];
                renderTracks();
            })
            .catch(function (error) {
                state.tracks = null;
                renderTracksError(error);
            });
    }

    /* ---------------------------------------------------------------------
       Wiring
       --------------------------------------------------------------------- */

    function cacheElements() {
        els.buzz = UI.qs("[data-buzz]");
        els.heroSub = UI.qs("[data-hero-sub]");
        els.recentRows = UI.qs("[data-recent-rows]");
        els.upcoming = UI.qs("[data-upcoming]");
        els.tracks = UI.qs("[data-tracks]");
    }

    function init() {
        cacheElements();

        /*
         * Three requests, issued together and each handled on its own. There is
         * no polling: the page only fetches when it is opened.
         */
        Promise.all([loadBuzz(), loadBookings(), loadTracks()]);
    }

    global.ParadiseArtistDashboard = { init: init };

    UI.onReady(init);
})(window);