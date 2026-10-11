/**
 * Paradise Studios — Studio details and booking.
 *
 * Reads GET /api/studio-details.php?id=...&include_availability=1 and creates
 * bookings with POST /api/bookings.php.
 *
 * Rules this module deliberately enforces:
 *   - the client-side total is labelled and shown as an ESTIMATE. The figure
 *     displayed in the confirmation dialog is the one returned by the server.
 *   - a submit in flight disables the button, so a double click cannot create
 *     two bookings (the backend would reject the second as a conflict anyway,
 *     but we must not rely on that).
 *   - overlap is still validated server-side; a 409 is surfaced verbatim.
 */
(function (global) {
    "use strict";

    var API = global.ArtistAPI;
    var UI = global.UI;
    var escape = UI.escape;

    /* Studio working hours used to build the slot list. */
    var OPEN_HOUR = 8;
    var CLOSE_HOUR = 22;
    var SLOT_STEP_MINUTES = 60;

    var state = {
        studioId: null,
        studio: null,
        bookedSlots: [],
        selectedStart: null,
        submitting: false
    };

    var els = {};
    var successModal = null;

    /* ---------------------------------------------------------------------
       Time helpers
       --------------------------------------------------------------------- */

    /** Format a Date as the exact "Y-m-d H:i:s" the backend requires. */
    function toBackendTime(date) {
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

    function parseSlot(value) {
        return UI.parseDate(value);
    }

    /** True when [start, start+hours) overlaps any active booked slot. */
    function overlaps(start, hours) {
        var end = new Date(start.getTime() + hours * 3600000);

        return state.bookedSlots.some(function (slot) {
            var busyStart = parseSlot(slot.start_time);
            var busyEnd = parseSlot(slot.end_time);

            if (!busyStart || !busyEnd) {
                return false;
            }

            return busyStart < end && busyEnd > start;
        });
    }

    /* ---------------------------------------------------------------------
       Studio details
       --------------------------------------------------------------------- */

    function renderStudio() {
        var studio = state.studio;

        if (!studio) {
            return;
        }

        var equipment = studio.equipment || [];

        setHtml(UI.qs("[data-studio-name]"), escape(studio.name || "Studio"));

        UI.qs("[data-studio-location]").textContent = studio.location || "—";

        var desc = UI.qs("[data-studio-desc]");
        desc.textContent = studio.description || "";
        desc.hidden = !studio.description;

        UI.qs("[data-studio-rate]").textContent = UI.rate(studio.hourly_rate);

        UI.qs("[data-studio-owner]").textContent = studio.owner_name || "Not published";

        var address = UI.qs("[data-studio-address]");
        address.textContent = studio.address || "Not provided";

        setHtml(
            UI.qs("[data-studio-equipment]"),
            equipment.length
                ? equipment
                      .map(function (item) {
                          return (
                              '<span class="ps-chip"><span>' +
                              escape(item.name) +
                              "</span></span>"
                          );
                      })
                      .join("")
                : '<span class="ps-chip ps-chip--none">No equipment listed</span>'
        );

        UI.qs("[data-studio-head]").hidden = true;
        UI.qs("[data-studio-body]").hidden = false;

        /* Feed the summary panel. */
        UI.qs("[data-sum-studio]").textContent = studio.name || "—";
        UI.qs("[data-sum-rate]").textContent = UI.rate(studio.hourly_rate);
    }

    function renderStudioError(error) {
        UI.qs("[data-studio-head]").hidden = true;

        var target = UI.qs("[data-studio-error]");
        target.hidden = false;

        UI.renderError(target, error, function () {
            loadStudio();
        });
    }

    /* ---------------------------------------------------------------------
       Slot picker
       --------------------------------------------------------------------- */

    function buildSlots() {
        var picker = UI.qs("[data-slot-picker]");
        var dateValue = els.date ? els.date.value : "";
        var hours = Number(els.hours.value);

        if (!dateValue) {
            setHtml(
                picker,
                UI.stateHtml({
                    icon: "calendar",
                    title: "Pick a date first",
                    message: "Choose a date and the available start times will appear here.",
                    compact: true
                })
            );
            state.selectedStart = null;
            updateSummary();
            updateSubmit();
            return;
        }

        var day = new Date(dateValue + "T00:00:00");

        if (isNaN(day.getTime())) {
            return;
        }

        var now = new Date();
        var slots = [];

        for (var minutes = OPEN_HOUR * 60; minutes < CLOSE_HOUR * 60; minutes += SLOT_STEP_MINUTES) {
            var start = new Date(
                day.getFullYear(),
                day.getMonth(),
                day.getDate(),
                Math.floor(minutes / 60),
                minutes % 60,
                0
            );

            /* Must fit inside opening hours. */
            var endsAt = new Date(start.getTime() + hours * 3600000);

            if (endsAt.getHours() > CLOSE_HOUR || endsAt.getDate() !== start.getDate()) {
                continue;
            }

            var isPast = start.getTime() <= now.getTime() + 15 * 60000;
            var isTaken = overlaps(start, hours);

            slots.push({ start: start, past: isPast, taken: isTaken });
        }

        if (!slots.length) {
            setHtml(
                picker,
                UI.stateHtml({
                    icon: "clock",
                    title: "No times available",
                    message:
                        "No whole-hour slot of " +
                        hours +
                        (hours === 1 ? " hour" : " hours") +
                        " fits on this date. Try a shorter session or another day.",
                    compact: true
                })
            );
            state.selectedStart = null;
            updateSummary();
            updateSubmit();
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

                    var note = slot.taken ? "Booked" : slot.past ? "Past" : display + (hours === 1 ? " hr" : " hrs");

                    return (
                        '<button type="button" class="ps-slot" data-slot="' +
                        key +
                        '" aria-pressed="' +
                        (state.selectedStart === key ? "true" : "false") +
                        '"' +
                        (disabled ? " disabled" : "") +
                        ">" +
                        display +
                        ":00 " +
                        suffix +
                        "<small>" +
                        escape(note) +
                        "</small>" +
                        "</button>"
                    );
                })
                .join("")
        );

        UI.qsa("[data-slot]", picker).forEach(function (button) {
            button.addEventListener("click", function () {
                state.selectedStart = Number(button.getAttribute("data-slot"));

                UI.qsa("[data-slot]", picker).forEach(function (other) {
                    other.setAttribute(
                        "aria-pressed",
                        Number(other.getAttribute("data-slot")) === state.selectedStart
                            ? "true"
                            : "false"
                    );
                });

                var errorNode = UI.qs("[data-field-error-slot]");
                if (errorNode) {
                    errorNode.innerHTML = "";
                }

                updateSummary();
                updateSubmit();
            });
        });
    }

    /* ---------------------------------------------------------------------
       Summary + submit state
       --------------------------------------------------------------------- */

    function updateSummary() {
        var studio = state.studio;

        if (!studio) {
            return;
        }

        var hours = Number(els.hours.value);

        if (!state.selectedStart || !els.date.value) {
            UI.qs("[data-sum-when]").textContent = "—";
            UI.qs("[data-sum-duration]").textContent = "—";
            UI.qs("[data-sum-total]").textContent = "—";
            return;
        }

        var start = new Date(state.selectedStart);
        var end = new Date(start.getTime() + hours * 3600000);

        UI.qs("[data-sum-when]").textContent =
            UI.date(start) + " · " + UI.time(start) + " – " + UI.time(end);

        UI.qs("[data-sum-duration]").textContent =
            hours + (hours === 1 ? " hour" : " hours");

        /* Preview only. The server returns the authoritative total. */
        UI.qs("[data-sum-total]").textContent = UI.money(
            UI.number(studio.hourly_rate) * hours
        );
    }

    function updateSubmit() {
        var ready =
            Boolean(state.selectedStart) &&
            Boolean(els.date.value) &&
            Boolean(state.studio) &&
            !state.submitting;

        els.submit.disabled = !ready;
    }

    /* ---------------------------------------------------------------------
       Booking submission
       --------------------------------------------------------------------- */

    function submitBooking(event) {
        event.preventDefault();

        /* Guard against a double submission. */
        if (state.submitting) {
            return;
        }

        UI.clearFormErrors(els.form);

        if (!state.selectedStart || !els.date.value) {
            UI.setFormError(els.form, "Choose a date and a start time first.");
            return;
        }

        var hours = Number(els.hours.value);
        var start = new Date(state.selectedStart);
        var end = new Date(start.getTime() + hours * 3600000);

        if (end.getTime() <= Date.now()) {
            UI.setFormError(els.form, "That slot has already passed. Please pick a later time.");
            return;
        }

        state.submitting = true;
        updateSubmit();
        UI.setButtonLoading(els.submit, true, "Booking…");

        API.createBooking({
            studio_id: state.studioId,
            start_time: toBackendTime(start),
            end_time: toBackendTime(end)
        })
            .then(function (response) {
                var data = response.data || {};

                showSuccess(data);

                /* Reflect the new booking so the picker updates. */
                state.bookedSlots.push({
                    start_time: data.start_time,
                    end_time: data.end_time
                });

                state.selectedStart = null;
                buildSlots();
                updateSummary();
            })
            .catch(function (error) {
                /* 409 = the slot was taken between load and submit. */
                if (error.status === 409) {
                    UI.setFormError(els.form, error.message);

                    UI.toast(error.message, {
                        type: "warning",
                        title: "Slot unavailable"
                    });

                    /* Refresh availability so the conflict disappears. */
                    loadStudio(true);
                } else {
                    UI.setFormError(els.form, error.message);
                    UI.toast(error.message, { type: "error", title: "Booking failed" });
                }
            })
            .then(function () {
                state.submitting = false;
                UI.setButtonLoading(els.submit, false);
                updateSubmit();
            });
    }

    function showSuccess(data) {
        var confirmedHours = UI.number(data.duration_hours);

        UI.qs("[data-success-sub]").textContent =
            (data.studio_name || "Your session") + " is reserved.";

        setHtml(
            UI.qs("[data-success-list]"),
            row("Booking reference", "#" + UI.number(data.booking_id)) +
                row("Studio", escape(data.studio_name || "—")) +
                row("Starts", escape(UI.dateTime(data.start_time))) +
                row("Ends", escape(UI.dateTime(data.end_time))) +
                row(
                    "Duration",
                    confirmedHours + (confirmedHours === 1 ? " hour" : " hours")
                ) +
                row("Hourly rate", escape(UI.rate(data.hourly_rate))) +
                /*
                 * The authoritative total. This is the figure the server
                 * calculated from the studio's hourly rate, not the preview
                 * shown before submitting.
                 */
                row(
                    "Total charged",
                    "<strong>" + escape(UI.money(data.total_amount)) + "</strong>"
                ) +
                row("Status", UI.statusBadge(data.status))
        );

        successModal.open();
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

    /* ---------------------------------------------------------------------
       Loading
       --------------------------------------------------------------------- */

    function loadStudio(quiet) {
        return API.studioDetails(state.studioId, true)
            .then(function (payload) {
                var studio = payload.data || {};

                state.studio = studio;
                state.bookedSlots = studio.booked_slots || [];

                renderStudio();
                buildSlots();
                updateSummary();
                updateSubmit();
            })
            .catch(function (error) {
                if (quiet) {
                    /* A background refresh failure should not clear the form. */
                    return;
                }
                renderStudioError(error);
            });
    }

    /* ---------------------------------------------------------------------
       Wiring
       --------------------------------------------------------------------- */

    /** Write HTML into a node, when the node exists. */
    function setHtml(node, html) {
        if (node) {
            node.innerHTML = html;
        }
    }

    function init() {
        var id = Number(
            new URLSearchParams(global.location.search).get("id") || ""
        );

        /* The PHP guard already rejected a non-numeric id, so this only has to
           confirm the value is usable. */
        if (!id || id <= 0) {
            return;
        }

        state.studioId = id;

        els.form = UI.qs("[data-booking-form]");
        els.date = UI.qs("[data-booking-date]");
        els.hours = UI.qs("[data-booking-hours]");
        els.submit = UI.qs("[data-booking-submit]");

        if (!els.form) {
            return;
        }

        successModal = UI.modal("#booking-success-modal");

        /* Today is the earliest bookable date. */
        var today = new Date();
        var iso =
            today.getFullYear() +
            "-" +
            String(today.getMonth() + 1).padStart(2, "0") +
            "-" +
            String(today.getDate()).padStart(2, "0");

        els.date.min = iso;

        els.date.addEventListener("change", function () {
            state.selectedStart = null;
            buildSlots();
            updateSummary();
            updateSubmit();
        });

        els.hours.addEventListener("change", function () {
            /* Duration changes can invalidate the chosen slot. */
            state.selectedStart = null;
            buildSlots();
            updateSummary();
            updateSubmit();
        });

        els.form.addEventListener("submit", submitBooking);

        loadStudio();
    }

    global.ParadiseArtistStudioDetails = { init: init };

    UI.onReady(init);
})(window);