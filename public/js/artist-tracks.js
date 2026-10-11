/**
 * Paradise Studios — My Tracks.
 *
 * Endpoints:
 *   GET  /api/tracks.php               list own tracks
 *   POST /api/tracks.php               upload (multipart)
 *   PUT  /api/release-track.php?id=    release -> server awards XP
 *
 * The upload constraints below mirror the server's rules so the artist gets
 * immediate guidance, but they are NOT the enforcement point: the backend
 * re-validates MIME type and size and its message is what gets shown.
 */
(function (global) {
    "use strict";

    var API = global.ArtistAPI;
    var UI = global.UI;
    var A = global.ArtistUI;
    var escape = UI.escape;

    /* Mirrors api/tracks.php. Guidance only — the server enforces. */
    var MAX_BYTES = 20 * 1024 * 1024;
    var ACCEPTED = {
        "audio/mpeg": "MP3",
        "audio/wav": "WAV",
        "audio/x-wav": "WAV",
        "audio/mp4": "M4A",
        "audio/x-m4a": "M4A",
        "audio/ogg": "OGG"
    };

    var state = {
        tracks: null,
        bookings: null,
        tab: "all",
        query: "",
        uploading: false,
        releasing: false
    };

    var els = {};
    var uploadModal = null;

    /* ---------------------------------------------------------------------
       List
       --------------------------------------------------------------------- */

    function visibleTracks() {
        var rows = (state.tracks || []).slice();
        var query = state.query.trim().toLowerCase();

        if (state.tab !== "all") {
            rows = rows.filter(function (track) {
                return String(track.status).toLowerCase() === state.tab;
            });
        }

        if (query) {
            rows = rows.filter(function (track) {
                return (
                    String(track.title || "").toLowerCase().indexOf(query) !== -1 ||
                    String(track.studio_name || "").toLowerCase().indexOf(query) !== -1
                );
            });
        }

        return rows;
    }

    function emptyMarkup() {
        var filtered = state.query || state.tab !== "all";

        if (filtered) {
            return UI.stateHtml({
                icon: "search",
                title: "No tracks match",
                message: "Try a different search term or status filter.",
                compact: true
            });
        }

        /*
         * A new artist needs the actual prerequisites spelled out, because the
         * backend only accepts uploads against completed bookings. Written by
         * hand rather than reusing UI.stateHtml so the numbered steps can sit
         * inside the same block.
         */
        return (
            '<div class="ps-state ps-state--compact ps-empty-guidance">' +
            '<div class="ps-state__icon">' +
            UI.icon("music", 24) +
            "</div>" +
            '<div class="ps-state__title">Your library is empty</div>' +
            '<div class="ps-state__msg">' +
            "Tracks are attached to a completed studio session." +
            "</div>" +
            '<div class="ps-empty-steps">' +
            '<span class="ps-empty-step"><span class="ps-empty-step__n">1</span>' +
            "<span>Book a studio session</span></span>" +
            '<span class="ps-empty-step"><span class="ps-empty-step__n">2</span>' +
            "<span>Mark the session complete once it has happened</span></span>" +
            '<span class="ps-empty-step"><span class="ps-empty-step__n">3</span>' +
            "<span>Upload the recording here</span></span>" +
            "</div>" +
            '<a class="ps-btn ps-btn--primary ps-btn--sm" href="' +
            global.location.pathname.replace(/[^/]*$/, "studios.php") +
            '">Find a studio</a>' +
            "</div>"
        );
    }

    function render() {
        if (!els.list || !state.tracks) {
            return;
        }

        els.list.setAttribute("aria-busy", "false");

        var rows = visibleTracks();

        if (!rows.length) {
            setHtml(els.list, emptyMarkup());
        } else {
            setHtml(
                els.list,
                rows
                    .map(function (track) {
                        return A.trackRow(track, { showRelease: true });
                    })
                    .join("")
            );
        }

        renderSummary(rows);
    }

    function renderSummary(rows) {
        if (!els.summary || !state.tracks) {
            return;
        }

        if (!state.tracks.length) {
            els.summary.textContent = "No tracks uploaded yet.";
            return;
        }

        var released = state.tracks.filter(function (track) {
            return String(track.status).toLowerCase() === "released";
        }).length;

        var xp = state.tracks.reduce(function (total, track) {
            return total + UI.number(track.clout_earned);
        }, 0);

        var parts = [
            state.tracks.length + (state.tracks.length === 1 ? " track" : " tracks"),
            released + " released"
        ];

        if (xp > 0) {
            parts.push(UI.number(xp) + " XP earned from tracks");
        }

        els.summary.textContent =
            (rows.length === state.tracks.length
                ? ""
                : "Showing " + rows.length + " of " + state.tracks.length + " · ") +
            parts.join(" · ") +
            ".";

        if (els.foot) {
            els.foot.textContent =
                "Released tracks earn Buzz XP. The amount is decided by the server.";
        }
    }

    function renderError(error) {
        if (!els.list) {
            return;
        }

        els.list.setAttribute("aria-busy", "false");

        setHtml(
            els.list,
            UI.stateHtml({
                tone: "error",
                icon: "alert",
                title: "Could not load your tracks",
                message: (error && error.message) || "Please try again.",
                actionLabel: "Try again"
            })
        );

        var button = UI.qs("[data-state-action]", els.list);

        if (button) {
            button.addEventListener("click", loadTracks);
        }

        if (els.summary) {
            els.summary.textContent = "Tracks could not be loaded.";
        }
    }

    /* ---------------------------------------------------------------------
       Release
       --------------------------------------------------------------------- */

    function releaseTrack(id, trigger) {
        var track = (state.tracks || []).filter(function (row) {
            return UI.number(row.id) === id;
        })[0];

        if (!track || state.releasing) {
            return;
        }

        UI.confirm({
            title: "Release this track?",
            message:
                '"' +
                (track.title || "Untitled track") +
                '" will be marked as released and Buzz XP will be added to your meter.',
            confirmLabel: "Release track"
        }).then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            state.releasing = true;
            UI.setButtonLoading(trigger, true, "Releasing…");

            API.releaseTrack(id)
                .then(function (response) {
                    var data = response.data || {};
                    var xp = UI.number(data.xp_earned);

                    UI.toast(
                        '"' +
                            (data.title || track.title || "Track") +
                            '" released. ' +
                            xp +
                            " Buzz XP awarded.",
                        { type: "success", title: "Track released" }
                    );

                    /*
                     * Refresh from the server so the row reflects the stored
                     * clout_earned and status rather than a local guess.
                     */
                    return loadTracks();
                })
                .catch(function (error) {
                    UI.toast(error.message, { type: "error", title: "Could not release" });
                })
                .then(function () {
                    state.releasing = false;
                    UI.setButtonLoading(trigger, false);
                });
        });
    }

    /* ---------------------------------------------------------------------
       Upload
       --------------------------------------------------------------------- */

    /** Populate the booking select with completed bookings only. */
    function renderBookingOptions() {
        var select = els.booking;

        if (!select) {
            return;
        }

        var completed = (state.bookings || []).filter(function (booking) {
            return String(booking.status).toLowerCase() === "completed";
        });

        if (!completed.length) {
            setHtml(
                select,
                '<option value="">No completed bookings available</option>'
            );
            select.disabled = true;
            els.submit.disabled = true;
            return;
        }

        setHtml(
            select,
            '<option value="">Choose a completed booking</option>' +
                completed
                    .map(function (booking) {
                        return (
                            '<option value="' +
                            UI.number(booking.id) +
                            '">#' +
                            UI.number(booking.id) +
                            " · " +
                            escape(booking.studio_name || "Studio") +
                            " · " +
                            escape(UI.date(booking.start_time)) +
                            "</option>"
                        );
                    })
                    .join("")
        );

        select.disabled = false;
    }

    function validateUpload() {
        var errors = {};

        if (!els.booking.value) {
            /* Must match the input's name so setFieldError() finds the field. */
            errors.booking_id = "Choose the completed booking this track belongs to.";
        }

        var title = els.title.value.trim();

        if (!title) {
            errors.title = "Track title is required.";
        } else if (title.length > 100) {
            errors.title = "Track title cannot exceed 100 characters.";
        }

        var file = els.file.files[0];

        if (!file) {
            errors.audio = "Choose an audio file to upload.";
        } else {
            if (file.size > MAX_BYTES) {
                errors.audio =
                    "That file is " +
                    (file.size / (1024 * 1024)).toFixed(1) +
                    " MB. The maximum is 20 MB.";
            } else if (file.type && !ACCEPTED[file.type]) {
                /*
                 * A friendly pre-check. The server still validates the real
                 * MIME type with finfo, so this is never the only gate.
                 */
                errors.audio =
                    "That looks like a " +
                    (file.type || "unsupported") +
                    " file. Please choose an MP3, WAV, M4A or OGG recording.";
            }
        }

        return errors;
    }

    function submitUpload(event) {
        event.preventDefault();

        if (state.uploading) {
            return;
        }

        UI.clearFormErrors(els.form);

        var errors = validateUpload();

        if (Object.keys(errors).length) {
            Object.keys(errors).forEach(function (key) {
                UI.setFieldError(els.form, key, errors[key]);
            });

            var firstBad = els.form.elements[Object.keys(errors)[0]];
            if (firstBad) {
                firstBad.focus();
            }

            return;
        }

        var file = els.file.files[0];
        var formData = new FormData();

        /* Field names must match what api/tracks.php reads. */
        formData.append("booking_id", els.booking.value);
        formData.append("title", els.title.value.trim());
        formData.append("audio", file, file.name);

        state.uploading = true;
        UI.setButtonLoading(els.submit, true, "Uploading…");

        els.progress.hidden = false;
        setProgress(12, "Sending " + file.name + " to the server…");

        /*
         * fetch() cannot report upload progress, so the bar is driven by an
         * indeterminate timer and completed on the real response. It never
         * claims 100% until the server has confirmed success.
         */
        var tick = global.setInterval(function () {
            var bar = UI.qs("[data-progress-bar]");

            if (bar && parseFloat(bar.style.width || "0") < 88) {
                setProgress(Math.min(88, UI.number(bar.style.width) + 7));
            }
        }, 220);

        API.uploadTrack(formData)
            .then(function (response) {
                global.clearInterval(tick);

                setProgress(100, "Confirmed by the server");

                var title_ = (response.data && response.data.title) || els.title.value.trim();

                UI.toast(
                    '"' +
                        title_ +
                        '" uploaded. Release it from your library to earn Buzz XP.',
                    { type: "success", title: "Track uploaded" }
                );

                uploadModal.close();
                els.form.reset();
                els.fileName.textContent = "Choose an audio file";

                return loadTracks();
            })
            .catch(function (error) {
                global.clearInterval(tick);

                UI.setFormError(els.form, error.message);
                UI.toast(error.message, { type: "error", title: "Upload failed" });

                /* Reset the bar so a retry starts clean. */
                setProgress(0, "Upload failed");
            })
            .then(function () {
                state.uploading = false;
                UI.setButtonLoading(els.submit, false);
            });
    }

    function setProgress(percent, text) {
        var bar = UI.qs("[data-progress-bar]");
        var label = UI.qs("[data-progress-text]");

        if (bar) {
            bar.style.width = percent + "%";
        }
        if (label) {
            label.textContent = text;
        }
    }

    function describeFile() {
        var file = els.file.files[0];

        if (!file) {
            els.fileName.textContent = "Choose an audio file";
            return;
        }

        var size = (file.size / (1024 * 1024)).toFixed(1);

        els.fileName.textContent =
            file.name + " · " + size + (file.size >= 1048576 ? " MB" : "");

        /* Clear a previous error as soon as a new file is chosen. */
        UI.clearFieldError(els.form, "audio");
    }

    function openUpload(trigger) {
        UI.clearFormErrors(els.form);

        /* Progress starts hidden and is only revealed by a real submit. */
        els.progress.hidden = true;
        setProgress(0, "");

        renderBookingOptions();
        uploadModal.open(trigger);
    }

    /* ---------------------------------------------------------------------
       Loading
       --------------------------------------------------------------------- */

    function loadTracks() {
        return API.myTracks()
            .then(function (payload) {
                state.tracks = payload.data || [];
                render();
            })
            .catch(function (error) {
                state.tracks = null;
                renderError(error);
            });
    }

    /** Needed for the booking select in the upload form. */
    function loadBookings() {
        return API.myBookings()
            .then(function (payload) {
                state.bookings = payload.data || [];
                renderBookingOptions();
            })
            .catch(function () {
                state.bookings = [];
                renderBookingOptions();
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
        els.summary = UI.qs("[data-tracks-summary]");
        els.list = UI.qs("[data-tracks-list]");
        els.foot = UI.qs("[data-tracks-foot]");
        els.search = UI.qs("[data-tracks-search]");
        els.form = UI.qs("[data-upload-form]");
        els.booking = UI.qs("[data-upload-booking]");
        els.title = UI.qs("#upload-title");
        els.file = UI.qs("#upload-audio");
        els.fileName = UI.qs("[data-file-name]");
        els.submit = UI.qs("[data-upload-submit]");
        els.progress = UI.qs("[data-upload-progress]");
    }

    function syncTabs() {
        UI.qsa("[data-track-tab]").forEach(function (button) {
            var isActive = button.getAttribute("data-track-tab") === state.tab;

            button.classList.toggle("is-active", isActive);
            button.setAttribute("aria-selected", isActive ? "true" : "false");
        });
    }

    function init() {
        cacheElements();

        if (!els.form) {
            return;
        }

        uploadModal = UI.modal("#upload-modal");

        UI.qsa("[data-open-upload]").forEach(function (button) {
            button.addEventListener("click", function () {
                openUpload(button);
            });
        });

        /* Delegated: works for any number of rows. */
        els.list.addEventListener("click", function (event) {
            var button = event.target.closest('[data-action="release"]');

            if (button) {
                releaseTrack(UI.number(button.getAttribute("data-track-id")), button);
            }
        });

        UI.qsa("[data-track-tab]").forEach(function (button) {
            button.addEventListener("click", function () {
                state.tab = button.getAttribute("data-track-tab");
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

        els.form.addEventListener("submit", submitUpload);

        els.file.addEventListener("change", describeFile);

        UI.qsa("input, select", els.form).forEach(function (field) {
            field.addEventListener("input", function () {
                var wrapper = field.closest(".ps-field");

                if (wrapper && wrapper.classList.contains("has-error")) {
                    UI.clearFieldError(els.form, field.name);
                }
            });
        });

        loadTracks();
        loadBookings();
    }

    global.ParadiseArtistTracks = { init: init };

    UI.onReady(init);
})(window);