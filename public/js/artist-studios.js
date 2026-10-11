/**
 * Paradise Studios — Discover Studios (artist marketplace).
 *
 * Filtering is delegated to the backend: GET /api/studios.php accepts
 * `location`, `max_price` and `equipment` (comma separated IDs). Requests are
 * debounced and the previous request is aborted so a slow response can never
 * overwrite a newer one.
 *
 * Sorting is applied locally to the rows already returned, because the endpoint
 * has no sort parameter. That only changes presentation order, never results.
 */
(function (global) {
    "use strict";

    var API = global.ArtistAPI;
    var UI = global.UI;
    var escape = UI.escape;

    var state = {
        studios: null,
        catalogue: null,
        filters: { location: "", max_price: "", equipment: [] },
        sort: "relevance",
        inFlight: null
    };

    var els = {};

    /* ---------------------------------------------------------------------
       Equipment catalogue (loaded once)
       --------------------------------------------------------------------- */

    function renderEquipmentMenu() {
        if (!els.equipMenu) {
            return;
        }

        var data = state.catalogue;

        if (!data) {
            setHtml(
                els.equipMenu,
                '<div class="ps-loading" style="padding:20px">' +
                    '<span class="ps-spinner" aria-hidden="true"></span>' +
                    "<span>Loading&hellip;</span></div>"
            );
            return;
        }

        var groups = data.grouped || {};
        var names = Object.keys(groups);

        if (!names.length) {
            setHtml(
                els.equipMenu,
                UI.stateHtml({
                    icon: "mic",
                    title: "No equipment listed",
                    message: "The equipment catalogue is empty.",
                    compact: true
                })
            );
            return;
        }

        setHtml(
            els.equipMenu,
            names
                .map(function (category) {
                    return (
                        '<div class="ps-equip-pop__group">' +
                        '<p class="ps-equip-pop__title">' +
                        escape(category) +
                        "</p>" +
                        groups[category]
                            .map(function (item) {
                                return (
                                    '<label class="ps-equip-pop__row">' +
                                    '<input type="checkbox" value="' +
                                    UI.number(item.id) +
                                    '" data-equip-id' +
                                    (state.filters.equipment.indexOf(UI.number(item.id)) !== -1
                                        ? " checked"
                                        : "") +
                                    ">" +
                                    "<span>" +
                                    escape(item.name) +
                                    "</span>" +
                                    "</label>"
                                );
                            })
                            .join("") +
                        "</div>"
                    );
                })
                .join("")
        );

        UI.qsa("[data-equip-id]", els.equipMenu).forEach(function (input) {
            input.addEventListener("change", function () {
                var id = UI.number(input.value);
                var index = state.filters.equipment.indexOf(id);

                if (input.checked && index === -1) {
                    state.filters.equipment.push(id);
                } else if (!input.checked && index !== -1) {
                    state.filters.equipment.splice(index, 1);
                }

                updateEquipCount();
                scheduleLoad();
            });
        });

        updateEquipCount();
    }

    function updateEquipCount() {
        if (!els.equipCount) {
            return;
        }

        var total = state.filters.equipment.length;

        els.equipCount.hidden = total === 0;
        els.equipCount.textContent = String(total);
    }

    function loadCatalogue() {
        return API.equipmentCatalogue()
            .then(function (payload) {
                state.catalogue = payload;
                renderEquipmentMenu();
            })
            .catch(function () {
                /* The equipment filter is optional; the grid still works. */
                state.catalogue = { grouped: {}, data: [] };
                setHtml(
                    els.equipMenu,
                    UI.stateHtml({
                        title: "Unavailable",
                        message: "The equipment list could not be loaded.",
                        compact: true
                    })
                );
            });
    }

    /* ---------------------------------------------------------------------
       Studio grid
       --------------------------------------------------------------------- */

    function sortStudios(list) {
        var rows = list.slice();

        if (state.sort === "price-asc") {
            rows.sort(function (a, b) {
                return UI.number(a.hourly_rate) - UI.number(b.hourly_rate);
            });
        } else if (state.sort === "price-desc") {
            rows.sort(function (a, b) {
                return UI.number(b.hourly_rate) - UI.number(a.hourly_rate);
            });
        } else if (state.sort === "name") {
            rows.sort(function (a, b) {
                return String(a.name || "").localeCompare(String(b.name || ""));
            });
        } else if (state.sort === "equipment") {
            rows.sort(function (a, b) {
                return (b.equipment || []).length - (a.equipment || []).length;
            });
        }

        /* "Relevance" keeps the backend's own id DESC order untouched. */
        return rows;
    }

    function cardMarkup(studio) {
        var id = UI.number(studio.id);
        var equipment = studio.equipment || [];

        /* studios.php already returns equipment as an array. */
        var chips = equipment
            .slice(0, 3)
            .map(function (name) {
                return '<span class="ps-chip"><span>' + escape(name) + "</span></span>";
            })
            .join("");

        if (equipment.length > 3) {
            chips +=
                '<span class="ps-chip ps-chip--more">+' +
                (equipment.length - 3) +
                " more</span>";
        }

        if (!equipment.length) {
            chips = '<span class="ps-chip ps-chip--none">No equipment listed</span>';
        }

        var description = String(studio.description || "").trim();

        return (
            '<article class="ps-mcard" data-studio-id="' +
            id +
            '">' +
            '<div class="ps-mcard__media">' +
            UI.icon("studios", 30) +
            "</div>" +
            '<div class="ps-mcard__body">' +
            '<h2 class="ps-mcard__name">' +
            escape(studio.name || "Studio") +
            "</h2>" +
            '<span class="ps-mcard__where">' +
            UI.icon("map-pin", 13) +
            "<span>" +
            escape(studio.location || "—") +
            "</span></span>" +
            (description
                ? '<p class="ps-mcard__desc ps-clamp-2">' +
                  escape(description) +
                  "</p>"
                : "") +
            '<div class="ps-mcard__gear">' +
            chips +
            "</div>" +
            '<div class="ps-mcard__foot">' +
            '<span class="ps-mcard__price">' +
            escape(UI.money(studio.hourly_rate)) +
            "<span>/hr</span></span>" +
            '<a class="ps-btn ps-btn--primary ps-btn--sm" href="' +
            global.location.pathname.replace(/[^/]*$/, "studio-details.php") +
            "?id=" +
            id +
            '">View studio</a>' +
            "</div>" +
            "</div>" +
            "</article>"
        );
    }

    function renderSummary(rows) {
        if (!els.summary) {
            return;
        }

        if (!state.studios) {
            els.summary.textContent = "Loading available studios…";
            return;
        }

        if (!rows.length) {
            els.summary.textContent = "No studios match your filters.";
            return;
        }

        var locations = {};
        rows.forEach(function (studio) {
            if (studio.location) {
                locations[String(studio.location).trim()] = true;
            }
        });

        var count = Object.keys(locations).length;

        els.summary.textContent =
            rows.length +
            (rows.length === 1 ? " studio available" : " studios available") +
            " in " +
            (count === 1 ? "1 location" : count + " locations") +
            ".";
    }

    function render() {
        if (!els.grid || !state.studios) {
            return;
        }

        els.grid.setAttribute("aria-busy", "false");

        var rows = sortStudios(state.studios);

        if (!rows.length) {
            els.grid.setAttribute("data-empty", "true");
            setHtml(
                els.grid,
                '<div class="ps-noresults">' +
                    UI.stateHtml({
                        icon: "search",
                        title: "No studios match those filters",
                        message:
                            "Try a different location, a higher price limit, or fewer equipment requirements.",
                        actionLabel: "Clear filters"
                    }) +
                    "</div>"
            );

            var clear = UI.qs("[data-state-action]", els.grid);

            if (clear) {
                clear.addEventListener("click", resetFilters);
            }

            renderSummary(rows);
            return;
        }

        els.grid.removeAttribute("data-empty");
        setHtml(els.grid, rows.map(cardMarkup).join(""));
        renderSummary(rows);
    }

    function renderError(error) {
        if (!els.grid) {
            return;
        }

        els.grid.setAttribute("aria-busy", "false");

        UI.renderError(els.grid, error, function () {
            loadStudios();
        });

        if (els.summary) {
            els.summary.textContent = "Studios could not be loaded.";
        }
    }

    /* ---------------------------------------------------------------------
       Loading
       --------------------------------------------------------------------- */

    function loadStudios() {
        /* Abort any in-flight request so a slow response cannot win. */
        if (state.inFlight) {
            state.inFlight.abort();
        }

        var controller = global.AbortController ? new global.AbortController() : null;
        state.inFlight = controller;

        els.grid.setAttribute("aria-busy", "true");

        API.studios(
            {
                location: state.filters.location,
                max_price: state.filters.max_price,
                equipment: state.filters.equipment
            },
            { signal: controller ? controller.signal : undefined }
        )
            .then(function (payload) {
                state.inFlight = null;
                state.studios = payload.data || [];
                render();
            })
            .catch(function (error) {
                state.inFlight = null;

                /* An abort is an intentional supersede, not a failure. */
                if (error && error.name === "AbortError") {
                    return;
                }

                renderError(error);
            });
    }

    var scheduleLoad = UI.debounce(loadStudios, 300);

    /* ---------------------------------------------------------------------
       Filters
       --------------------------------------------------------------------- */

    function resetFilters() {
        state.filters = { location: "", max_price: "", equipment: [] };

        if (els.location) {
            els.location.value = "";
        }
        if (els.price) {
            els.price.value = "";
        }
        if (els.sort) {
            els.sort.value = "relevance";
        }
        state.sort = "relevance";

        UI.qsa("[data-equip-id]", els.equipMenu || document).forEach(function (input) {
            input.checked = false;
        });

        updateEquipCount();
        loadStudios();
    }

    function closeEquipMenu() {
        if (!els.equipMenu || !els.equipToggle) {
            return;
        }

        els.equipMenu.hidden = true;
        els.equipToggle.setAttribute("aria-expanded", "false");
    }

    function initEquipPopover() {
        if (!els.equipToggle || !els.equipMenu) {
            return;
        }

        els.equipToggle.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();

            var willOpen = els.equipMenu.hidden;
            els.equipMenu.hidden = !willOpen;
            els.equipToggle.setAttribute("aria-expanded", willOpen ? "true" : "false");
        });

        document.addEventListener("click", function (event) {
            if (els.equipMenu.hidden) {
                return;
            }

            if (!event.target.closest(".ps-equip-pop")) {
                closeEquipMenu();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && !els.equipMenu.hidden) {
                closeEquipMenu();
                els.equipToggle.focus();
            }
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
        els.summary = UI.qs("[data-mplace-summary]");
        els.grid = UI.qs("[data-mplace-grid]");
        els.location = UI.qs("[data-filter-location]");
        els.price = UI.qs("[data-filter-price]");
        els.sort = UI.qs("[data-filter-sort]");
        els.equipToggle = UI.qs("[data-equip-toggle]");
        els.equipMenu = UI.qs("[data-equip-menu]");
        els.equipCount = UI.qs("[data-equip-count]");
    }

    function init() {
        cacheElements();

        initEquipPopover();

        if (els.location) {
            els.location.addEventListener(
                "input",
                UI.debounce(function () {
                    state.filters.location = els.location.value.trim();
                    scheduleLoad();
                }, 320)
            );
        }

        if (els.price) {
            els.price.addEventListener("change", function () {
                state.filters.max_price = els.price.value;
                loadStudios();
            });
        }

        if (els.sort) {
            els.sort.addEventListener("change", function () {
                state.sort = els.sort.value;
                /* Re-order what we already have; no refetch needed. */
                if (state.studios) {
                    render();
                }
            });
        }

        var reset = UI.qs("[data-filter-reset]");

        if (reset) {
            reset.addEventListener("click", resetFilters);
        }

        loadCatalogue();
        loadStudios();
    }

    global.ParadiseArtistStudios = { init: init };

    UI.onReady(init);
})(window);