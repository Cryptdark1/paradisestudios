/**
 * Paradise Studios — Owner bookings.
 *
 * Single source of data: GET /api/owner-bookings.php. The same rows feed the
 * list table, the FullCalendar views and the detail dialog, so no view can
 * drift from the others.
 *
 * Clicking a row (or its button) and clicking a calendar event both open the
 * detail dialog for that booking.
 */
(function (global) {
    "use strict";

    var API = global.API;
    var UI = global.UI;
    var escape = UI.escape;

    var state = {
        bookings: null,
        query: "",
        status: "all",
        view: "list"
    };

    var els = {};
    var calendar = null;
    var detailModal = null;

    /* ---------------------------------------------------------------------
       Filtering & sorting
       --------------------------------------------------------------------- */

    function visibleBookings() {
        var query = state.query.trim().toLowerCase();

        return (state.bookings || []).filter(function (booking) {
            if (state.status !== "all" && String(booking.status).toLowerCase() !== state.status) {
                return false;
            }

            if (!query) {
                return true;
            }

            return (
                String(booking.artist_name || "").toLowerCase().indexOf(query) !== -1 ||
                String(booking.artist_email || "").toLowerCase().indexOf(query) !== -1 ||
                String(booking.studio_name || "").toLowerCase().indexOf(query) !== -1 ||
                String(booking.location || "").toLowerCase().indexOf(query) !== -1 ||
                String(booking.id).indexOf(query) !== -1
            );
        });
    }

    function sortedBookings(rows) {
        return rows.slice().sort(function (a, b) {
            var da = UI.parseDate(a.start_time);
            var db = UI.parseDate(b.start_time);

            /* Soonest first: cancelled rows always sink to the bottom. */
            var ca = String(a.status).toLowerCase() === "cancelled" ? 1 : 0;
            var cb = String(b.status).toLowerCase() === "cancelled" ? 1 : 0;

            if (ca !== cb) {
                return ca - cb;
            }

            return (da ? da.getTime() : 0) - (db ? db.getTime() : 0);
        });
    }

    /* ---------------------------------------------------------------------
       Summary
       --------------------------------------------------------------------- */

    function renderSummary() {
        if (!els.summary) {
            return;
        }

        if (!state.bookings) {
            return;
        }

        var rows = state.bookings;
        var now = Date.now();

        var upcoming = rows.filter(function (booking) {
            var started = UI.parseDate(booking.start_time);
            return (
                started &&
                started.getTime() >= now &&
                ["cancelled", "completed", "disputed"].indexOf(
                    String(booking.status).toLowerCase()
                ) === -1
            );
        });

        var completed = rows.filter(function (booking) {
            return String(booking.status).toLowerCase() === "completed";
        });

        var cancelled = rows.filter(function (booking) {
            return String(booking.status).toLowerCase() === "cancelled";
        });

        var parts = [
            rows.length + (rows.length === 1 ? " booking" : " bookings") + " in total"
        ];

        parts.push(upcoming.length + " upcoming");

        if (completed.length) {
            parts.push(UI.money(sumAmount(completed)) + " released");
        }

        if (cancelled.length) {
            parts.push(cancelled.length + " cancelled");
        }

        els.summary.textContent = parts.join(" · ") + ".";
    }

    function sumAmount(rows) {
        return rows.reduce(function (total, booking) {
            return total + UI.number(booking.total_amount);
        }, 0);
    }

    /* ---------------------------------------------------------------------
       List rendering
       --------------------------------------------------------------------- */

    function rowMarkup(booking) {
        var started = UI.parseDate(booking.start_time);
        var ended = UI.parseDate(booking.end_time);

        return (
            "<tr>" +
            '<td data-label="Artist">' +
            '<span class="ps-table__primary">' +
            '<span class="ps-avatar ps-avatar--sm ps-avatar--artist" aria-hidden="true">' +
            escape(UI.initials(booking.artist_name)) +
            "</span>" +
            "<span>" +
            "<strong>" +
            escape(booking.artist_name || "Artist") +
            "</strong>" +
            "<small>" +
            escape(booking.artist_email || "") +
            "</small>" +
            "</span>" +
            "</span>" +
            "</td>" +
            '<td data-label="Studio">' +
            "<span>" +
            escape(booking.studio_name || "Studio") +
            "<small class=\"ps-soft\">" +
            escape(booking.location || "") +
            "</small>" +
            "</span>" +
            "</td>" +
            '<td class="date-cell" data-label="Date">' +
            escape(UI.date(booking.start_time)) +
            "<small>" +
            escape(started ? UI.friendlyDate(booking.start_time) : "") +
            "</small>" +
            "</td>" +
            '<td class="date-cell" data-label="Time">' +
            escape(UI.time(booking.start_time)) +
            (ended ? " – " + escape(UI.time(booking.end_time)) : "") +
            "</td>" +
            '<td class="num" data-label="Amount">' +
            escape(UI.money(booking.total_amount)) +
            "</td>" +
            '<td data-label="Status">' +
            UI.statusBadge(booking.status) +
            "</td>" +
            '<td class="actions" data-label="Actions">' +
            '<button type="button" class="ps-btn ps-btn--secondary ps-btn--sm" ' +
            'data-booking-id="' +
            UI.number(booking.id) +
            '" aria-label="View booking ' +
            UI.number(booking.id) +
            ' details">View</button>' +
            "</td>" +
            "</tr>"
        );
    }

    function renderList() {
        if (!els.rows) {
            return;
        }

        els.rows.setAttribute("aria-busy", "false");

        var rows = sortedBookings(visibleBookings());

        if (!state.bookings) {
            return;
        }

        if (!rows.length) {
            var isFiltered = state.query || state.status !== "all";

            els.rows.innerHTML =
                '<tr><td colspan="7">' +
                UI.stateHtml({
                    icon: isFiltered ? "search" : "calendar",
                    title: isFiltered ? "No bookings match your filters" : "No bookings yet",
                    message: isFiltered
                        ? "Try a different search term or status."
                        : "When an artist books one of your studios, it will appear here.",
                    compact: true,
                    actionLabel: isFiltered ? "Clear filters" : null
                }) +
                "</td></tr>";

            var btn = UI.qs("[data-state-action]", els.rows);

            if (btn) {
                btn.addEventListener("click", function () {
                    state.query = "";
                    state.status = "all";

                    if (els.search) {
                        els.search.value = "";
                    }
                    if (els.statusFilter) {
                        els.statusFilter.value = "all";
                    }

                    render();
                });
            }

            return;
        }

        els.rows.innerHTML = rows.map(rowMarkup).join("");
    }

    function renderError(error) {
        if (!els.rows) {
            return;
        }

        els.rows.setAttribute("aria-busy", "false");
        UI.renderError(els.rows, error, function () {
            load();
        });
    }

    /* ---------------------------------------------------------------------
       Calendar
       --------------------------------------------------------------------- */

    function eventFor(booking) {
        var started = UI.parseDate(booking.start_time);
        var ended = UI.parseDate(booking.end_time);
        var status = String(booking.status).toLowerCase();

        return {
            id: String(booking.id),
            title: (booking.artist_name || "Artist") + " · " + (booking.studio_name || "Studio"),
            start: started,
            end: ended || started,
            classNames: ["ps-ev", "ps-ev--" + status],
            extendedProps: { booking: booking, status: status }
        };
    }

    function renderCalendar() {
        var host = els.calendar;

        if (!host || !state.bookings) {
            return;
        }

        var events = state.bookings.map(eventFor);

        if (!calendar) {
            var options = {
                initialView: "dayGridMonth",
                headerToolbar: false,
                height: "auto",
                firstDay: 1,
                dayMaxEvents: 3,
                /* Always render labelled chips (matching the design) instead of
                   letting FullCalendar fall back to colour dots. */
                eventDisplay: "block",
                nowIndicator: true,
                displayEventTime: true,
                eventTimeFormat: { hour: "numeric", minute: "2-digit", meridiem: "short" },
                slotLabelFormat: { hour: "numeric", minute: "2-digit", meridiem: "short" },
                events: events,
                /*
                 * Two-line chips (time above, artist/studio below) to match the
                 * approved design and avoid clipped text in narrow cells.
                 */
                eventContent: function (arg) {
                    var booking = arg.event.extendedProps.booking;
                    var wrap = document.createElement("div");

                    wrap.className = "ps-evc";

                    var time = document.createElement("span");
                    time.className = "ps-evc__time";
                    time.textContent = UI.time(booking.start_time);

                    var title = document.createElement("span");
                    title.className = "ps-evc__title";
                    title.textContent =
                        (booking.artist_name || "Artist") + " · " + (booking.studio_name || "Studio");

                    wrap.appendChild(time);
                    wrap.appendChild(title);

                    return { domNodes: [wrap] };
                },
                /*
                 * Status is also exposed as text (tooltip + accessible name)
                 * so it never depends on colour alone.
                 */
                eventDidMount: function (arg) {
                    var booking = arg.event.extendedProps.booking;
                    var meta = UI.statusMeta(booking.status);
                    var label = UI.statusBadge(booking.status);

                    var description =
                        (booking.artist_name || "Artist") +
                        " with " +
                        (booking.studio_name || "studio") +
                        " on " +
                        UI.date(booking.start_time) +
                        ", " +
                        UI.time(booking.start_time) +
                        " — " +
                        meta.label +
                        ", " +
                        UI.money(booking.total_amount);

                    arg.el.setAttribute("title", description);
                    arg.el.setAttribute("aria-label", description);
                    arg.el.setAttribute("data-status", booking.status);
                },
                eventClick: function (info) {
                    info.jsEvent.preventDefault();
                    openDetail(info.event.extendedProps.booking, info.jsEvent.currentTarget);
                },
                datesSet: function (info) {
                    updateCalendarTitle(info);
                }
            };

            /*
             * No explicit `plugins` array: the vendored v6 core global bundle
             * already registers the daygrid / timegrid / list / interaction
             * plugins. Supplying them again would log duplicate warnings.
             */
            calendar = new global.FullCalendar.Calendar(host, options);
            calendar.render();
            return;
        }

        /* Replace the event set in place rather than re-rendering the grid. */
        calendar.batchRendering(function () {
            calendar.removeAllEvents();
            calendar.addEventSource(events);
        });
    }

    function updateCalendarTitle(info) {
        if (!els.calendarTitle) {
            return;
        }

        var title = info.view.title || "";

        /* FullCalendar omits the year when the range is in the current one. */
        var start = info.start;

        if (start && !/\d{4}/.test(title)) {
            title += " " + start.getFullYear();
        }

        els.calendarTitle.textContent = title;
    }

    function renderCalendarState() {
        if (!els.calendarState || !state.bookings) {
            return;
        }

        if (state.bookings.length) {
            els.calendarState.hidden = true;
            els.calendarState.innerHTML = "";
            return;
        }

        els.calendarState.hidden = false;
        els.calendarState.innerHTML = UI.stateHtml({
            icon: "calendar",
            title: "Nothing scheduled yet",
            message: "Booked sessions will appear on the calendar.",
            compact: true
        });
    }

    /* ---------------------------------------------------------------------
       Booking detail dialog
       --------------------------------------------------------------------- */

    function bookingById(id) {
        return (state.bookings || []).filter(function (booking) {
            return UI.number(booking.id) === id;
        })[0];
    }

    function detailRow(label, value) {
        return (
            '<div class="ps-dl__row"><dt>' +
            escape(label) +
            "</dt><dd>" +
            value +
            "</dd></div>"
        );
    }

    function openDetail(booking, trigger) {
        if (!booking) {
            return;
        }

        var started = UI.parseDate(booking.start_time);
        var ended = UI.parseDate(booking.end_time);

        UI.qs("[data-detail-subtitle]").textContent =
            "Booking #" + UI.number(booking.id) + " · created " + UI.relativeTime(booking.created_at);

        UI.qs("[data-detail-artist]").innerHTML =
            '<span class="ps-avatar ps-avatar--artist" aria-hidden="true">' +
            escape(UI.initials(booking.artist_name)) +
            "</span>" +
            '<span class="ps-detail__artist-text">' +
            '<span class="ps-detail__artist-name">' +
            escape(booking.artist_name || "Artist") +
            "</span>" +
            '<span class="ps-detail__artist-mail">' +
            UI.icon("mail", 13) +
            "<span>" +
            escape(booking.artist_email || "Email not provided") +
            "</span></span>" +
            "</span>";

        UI.qs("[data-detail-when]").innerHTML =
            '<span class="ps-detail__when-icon">' +
            UI.icon("calendar", 20) +
            "</span>" +
            '<span class="ps-detail__when-text">' +
            "<strong>" +
            escape(UI.friendlyDate(booking.start_time)) +
            "</strong>" +
            "<small>" +
            escape(UI.date(booking.start_time)) +
            " · " +
            escape(UI.time(booking.start_time)) +
            (ended ? " – " + escape(UI.time(booking.end_time)) : "") +
            "</small></span>" +
            '<span class="ps-detail__amount">' +
            "<strong>" +
            escape(UI.money(booking.total_amount)) +
            "</strong>" +
            "<small>" +
            UI.statusBadge(booking.status) +
            "</small></span>";

        UI.qs("[data-detail-list]").innerHTML =
            detailRow("Booking ID", "#" + escape(UI.number(booking.id))) +
            detailRow("Artist", escape(booking.artist_name || "—")) +
            detailRow("Artist email", escape(booking.artist_email || "—")) +
            detailRow("Studio", escape(booking.studio_name || "—")) +
            detailRow("Location", escape(booking.location || "—")) +
            detailRow(
                "Date",
                escape(UI.date(booking.start_time)) +
                    (started ? " (" + escape(UI.friendlyDate(booking.start_time)) + ")" : "")
            ) +
            detailRow("Start time", escape(UI.time(booking.start_time))) +
            detailRow("End time", ended ? escape(UI.time(booking.end_time)) : "—") +
            detailRow("Amount", "<strong>" + escape(UI.money(booking.total_amount)) + "</strong>") +
            detailRow("Status", UI.statusBadge(booking.status)) +
            detailRow("Created", escape(UI.dateTime(booking.created_at)));

        detailModal.open(trigger);
    }

    /* ---------------------------------------------------------------------
       View switching
       --------------------------------------------------------------------- */

    function setView(view) {
        state.view = view;

        UI.qsa("[data-view]").forEach(function (button) {
            var isActive = button.getAttribute("data-view") === view;

            button.classList.toggle("is-active", isActive);
            button.setAttribute("aria-selected", isActive ? "true" : "false");
        });

        var listPanel = UI.qs('[data-panel="list"]');
        var calPanel = UI.qs('[data-panel="calendar"]');

        if (listPanel) {
            listPanel.hidden = view !== "list";
        }
        if (calPanel) {
            calPanel.hidden = view !== "calendar";
        }

        if (view === "calendar") {
            renderCalendarState();
            renderCalendar();
        }
    }

    /* ---------------------------------------------------------------------
       Data
       --------------------------------------------------------------------- */

    function render() {
        renderList();
        renderSummary();

        if (state.view === "calendar") {
            renderCalendarState();

            /* renderCalendar() creates the calendar on first call and updates
               the events afterwards. It must also run when the owner switched
               to the calendar view *before* the bookings request resolved. */
            renderCalendar();
        }
    }

    function load() {
        return API.owner
            .bookings()
            .then(function (payload) {
                state.bookings = payload.data || [];
                render();
            })
            .catch(function (error) {
                renderError(error);

                if (els.summary) {
                    els.summary.textContent = "We could not load your bookings right now.";
                }
            });
    }

    /* ---------------------------------------------------------------------
       Wiring
       --------------------------------------------------------------------- */

    function cacheElements() {
        els.summary = UI.qs("[data-bookings-summary]");
        els.rows = UI.qs("[data-booking-rows]");
        els.search = UI.qs("[data-booking-search]");
        els.statusFilter = UI.qs("[data-status-filter]");
        els.calendar = UI.qs("[data-calendar]");
        els.calendarState = UI.qs("[data-calendar-state]");
        els.calendarTitle = UI.qs("[data-calendar-title]");
        els.calendarRange = UI.qs("[data-cal-range]");
    }

    function init() {
        cacheElements();

        detailModal = UI.modal("#booking-detail-modal");

        /* --- View switch --- */
        UI.qsa("[data-view]").forEach(function (button) {
            button.addEventListener("click", function () {
                setView(button.getAttribute("data-view"));
            });
        });

        /* --- Search & status --- */
        if (els.search) {
            els.search.addEventListener(
                "input",
                UI.debounce(function () {
                    state.query = els.search.value;
                    render();
                }, 180)
            );
        }

        if (els.statusFilter) {
            els.statusFilter.addEventListener("change", function () {
                state.status = els.statusFilter.value;
                render();
            });
        }

        /* --- Delegated row clicks (works for any number of rows) --- */
        if (els.rows) {
            els.rows.addEventListener("click", function (event) {
                var button = event.target.closest("[data-booking-id]");
                var id = button ? UI.number(button.getAttribute("data-booking-id")) : null;

                if (id === null) {
                    return;
                }

                openDetail(bookingById(id), button);
            });
        }

        /* --- Calendar navigation --- */
        UI.qsa("[data-cal-nav]").forEach(function (button) {
            button.addEventListener("click", function () {
                if (!calendar) {
                    return;
                }

                var action = button.getAttribute("data-cal-nav");

                if (action === "prev") {
                    calendar.prev();
                } else if (action === "next") {
                    calendar.next();
                } else {
                    calendar.today();
                }
            });
        });

        if (els.calendarRange) {
            els.calendarRange.addEventListener("change", function () {
                if (calendar) {
                    calendar.changeView(els.calendarRange.value);
                }
            });
        }

        load();
    }

    global.ParadiseBookings = { init: init };

    UI.onReady(init);
})(window);