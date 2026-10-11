/**
 * Paradise Studios — My Studios.
 *
 * Uses the existing owner endpoints only:
 *   GET  /api/owner-studios.php            -> the list
 *   POST /api/owner-create-studio.php      -> create
 *   PUT  /api/owner-update-studio.php?id=  -> update
 *   PUT  /api/owner-studio-equipment.php?id= -> equipment
 *   GET  /api/owner-equipment.php?studio_ids= -> catalogue + every assignment
 *                                               in a single request
 *
 * The equipment catalogue and the assignments for all studios are fetched
 * once, so opening the equipment manager needs no further network calls and
 * the number of studios is unbounded.
 */
(function (global) {
    "use strict";

    var API = global.API;
    var UI = global.UI;
    var escape = UI.escape;

    /* Preferred display order for equipment categories. */
    var CATEGORY_ORDER = ["Mic", "Instrument", "Monitoring", "Software"];

    var state = {
        studios: [],
        catalog: [],
        assignments: {},
        equipmentLoaded: false,
        query: "",
        sort: "newest",
        editingId: null,
        equipmentStudioId: null,
        equipmentDraft: []
    };

    var els = {};
    var formModal = null;
    var equipmentModal = null;

    /* ---------------------------------------------------------------------
       Helpers
       --------------------------------------------------------------------- */

    function setHtml(node, html) {
        if (node) {
            node.innerHTML = html;
        }
    }

    function equipmentNames(studioId) {
        var ids = state.assignments[String(studioId)] || [];
        var names = [];

        ids.forEach(function (id) {
            var found = state.catalog.filter(function (item) {
                return UI.number(item.id) === id;
            })[0];

            if (found) {
                names.push(found.name);
            }
        });

        return names;
    }

    function summaryLine() {
        if (!els.summary) {
            return;
        }

        if (!state.studios.length) {
            els.summary.textContent =
                "You have not listed a studio yet. Add one so artists can book it.";
            return;
        }

        var locations = {};
        var withGear = 0;

        state.studios.forEach(function (studio) {
            if (studio.location) {
                locations[String(studio.location).trim()] = true;
            }

            if (equipmentNames(studio.id).length) {
                withGear += 1;
            }
        });

        var count = Object.keys(locations).length;
        var parts = [
            state.studios.length +
                (state.studios.length === 1 ? " studio" : " studios") +
                " listed in " +
                (count === 1 ? "1 location" : count + " locations")
        ];

        parts.push(
            withGear === state.studios.length
                ? "all with equipment configured"
                : withGear + " with equipment configured"
        );

        els.summary.textContent = parts.join(" · ") + ".";
    }

    /* ---------------------------------------------------------------------
       Rendering the grid
       --------------------------------------------------------------------- */

    function visibleStudios() {
        var query = state.query.trim().toLowerCase();

        var list = state.studios.filter(function (studio) {
            if (!query) {
                return true;
            }

            return (
                String(studio.name || "").toLowerCase().indexOf(query) !== -1 ||
                String(studio.location || "").toLowerCase().indexOf(query) !== -1 ||
                String(studio.address || "").toLowerCase().indexOf(query) !== -1 ||
                String(studio.description || "").toLowerCase().indexOf(query) !== -1
            );
        });

        list.sort(function (a, b) {
            if (state.sort === "name") {
                return String(a.name || "").localeCompare(String(b.name || ""));
            }

            if (state.sort === "rate") {
                return UI.number(b.hourly_rate) - UI.number(a.hourly_rate);
            }

            if (state.sort === "rate-asc") {
                return UI.number(a.hourly_rate) - UI.number(b.hourly_rate);
            }

            /* Newest first — the API already orders by created_at DESC. */
            return UI.number(b.id) - UI.number(a.id);
        });

        return list;
    }

    function chipsMarkup(studioId) {
        var names = equipmentNames(studioId);

        if (!names.length) {
            return (
                '<div class="ps-chips">' +
                '<span class="ps-chip ps-chip--none">No equipment assigned</span>' +
                "</div>"
            );
        }

        var shown = names.slice(0, 4).map(function (name) {
            return '<span class="ps-chip"><span>' + escape(name) + "</span></span>";
        });

        if (names.length > 4) {
            shown.push(
                '<span class="ps-chip ps-chip--more">+' + (names.length - 4) + " more</span>"
            );
        }

        return '<div class="ps-chips">' + shown.join("") + "</div>";
    }

    function cardMarkup(studio) {
        var id = UI.number(studio.id);
        var description = String(studio.description || "").trim();
        var where = [studio.location, studio.address].filter(Boolean).join(" · ");

        return (
            '<article class="ps-studio-card" data-studio-id="' +
            id +
            '">' +
            '<div class="ps-studio-card__head">' +
            '<span class="ps-studio-card__monogram" aria-hidden="true">' +
            escape(UI.initials(studio.name)) +
            "</span>" +
            '<div class="ps-studio-card__headtext">' +
            /* h2 so the outline stays h1 (page title) -> h2 (each studio). */
            '<h2 class="ps-studio-card__name">' +
            escape(studio.name || "Untitled studio") +
            "</h2>" +
            (where
                ? '<span class="ps-studio-card__where">' +
                  UI.icon("map-pin", 13) +
                  "<span>" +
                  escape(where) +
                  "</span></span>"
                : "") +
            "</div>" +
            "</div>" +
            '<div class="ps-studio-card__body">' +
            (description
                ? '<p class="ps-studio-card__desc ps-clamp-3">' +
                  escape(description) +
                  "</p>"
                : '<p class="ps-studio-card__desc ps-studio-card__desc--empty">No description yet.</p>') +
            '<div class="ps-studio-card__gear">' +
            '<div class="ps-studio-card__gear-head">' +
            UI.icon("mic", 13) +
            "<span>Equipment</span>" +
            "</div>" +
            chipsMarkup(id) +
            "</div>" +
            "</div>" +
            '<div class="ps-studio-card__foot">' +
            '<div class="ps-studio-card__price">' +
            escape(UI.money(studio.hourly_rate)) +
            "<span>/hour</span></div>" +
            '<div class="ps-studio-card__actions">' +
            '<button type="button" class="ps-btn ps-btn--secondary ps-btn--sm" ' +
            'data-action="equipment" data-studio-id="' +
            id +
            '" aria-label="Manage equipment for ' +
            escape(studio.name || "this studio") +
            '" title="Manage equipment">' +
            UI.icon("sliders", 15) +
            "<span>Equipment</span></button>" +
            '<button type="button" class="ps-btn ps-btn--primary ps-btn--sm" ' +
            'data-action="edit" data-studio-id="' +
            id +
            '" aria-label="Edit ' +
            escape(studio.name || "this studio") +
            '" title="Edit studio">' +
            UI.icon("edit", 15) +
            "<span>Edit</span></button>" +
            "</div>" +
            "</div>" +
            "</article>"
        );
    }

    function render() {
        if (!els.grid) {
            return;
        }

        els.grid.setAttribute("aria-busy", "false");

        if (!state.studios.length) {
            setHtml(
                els.grid,
                UI.stateHtml({
                    icon: "studios",
                    title: "No studios yet",
                    message:
                        "Add your first room and artists will be able to discover and book it.",
                    actionLabel: "Add new studio"
                })
            );

            var emptyBtn = UI.qs("[data-state-action]", els.grid);

            if (emptyBtn) {
                emptyBtn.addEventListener("click", function (event) {
                    openCreate(event.currentTarget);
                });
            }

            summaryLine();
            return;
        }

        var list = visibleStudios();

        if (!list.length) {
            setHtml(
                els.grid,
                UI.stateHtml({
                    icon: "search",
                    title: "No studios match your search",
                    message:
                        'Nothing matches "' +
                        UI.truncate(state.query, 40) +
                        '". Try a different name or location.',
                    actionLabel: "Clear search"
                })
            );

            var clearBtn = UI.qs("[data-state-action]", els.grid);

            if (clearBtn) {
                clearBtn.addEventListener("click", function () {
                    state.query = "";

                    if (els.search) {
                        els.search.value = "";
                    }

                    render();
                });
            }

            summaryLine();
            return;
        }

        setHtml(els.grid, list.map(cardMarkup).join(""));
        summaryLine();
    }

    function renderError(error) {
        if (!els.grid) {
            return;
        }

        els.grid.setAttribute("aria-busy", "false");
        UI.renderError(els.grid, error, function () {
            loadStudios();
        });
    }

    /* ---------------------------------------------------------------------
       Data loading
       --------------------------------------------------------------------- */

    function loadStudios() {
        return API.owner
            .studios()
            .then(function (payload) {
                state.studios = payload.data || [];
                render();
                return loadEquipmentAssignments();
            })
            .catch(function (error) {
                renderError(error);
            });
    }

    /**
     * Load the equipment catalogue plus every studio's assignment in ONE
     * request, so card chips and the equipment manager need no further calls.
     */
    function loadEquipmentAssignments() {
        if (!state.studios.length) {
            state.equipmentLoaded = true;
            return Promise.resolve();
        }

        var ids = state.studios
            .map(function (studio) {
                return UI.number(studio.id);
            })
            .join(",");

        return API.owner
            .equipment({ studio_ids: ids })
            .then(function (payload) {
                var data = payload.data || {};

                state.catalog = data.catalog || [];
                state.assignments = data.assignments || {};
                state.equipmentLoaded = true;

                render();
            })
            .catch(function () {
                /* Equipment chips are supplementary: the studios themselves
                   already rendered. Leave the catalogue empty and let the
                   equipment manager retry on demand. */
                state.equipmentLoaded = false;
            });
    }

    /* ---------------------------------------------------------------------
       Add / edit studio
       --------------------------------------------------------------------- */

    function studioById(id) {
        return state.studios.filter(function (studio) {
            return UI.number(studio.id) === id;
        })[0];
    }

    function openCreate(trigger) {
        state.editingId = null;

        els.form.reset();
        UI.clearFormErrors(els.form);

        UI.qs("[data-studio-form-title]").textContent = "Add new studio";
        UI.qs("[data-studio-form-subtitle]").textContent =
            "List a room so artists can find and book it.";
        UI.qs("[data-submit-label]").textContent = "Create studio";

        formModal.open(trigger);
    }

    function openEdit(id, trigger) {
        var studio = studioById(id);

        if (!studio) {
            UI.toast("That studio is no longer available. Refresh the list.", {
                type: "warning"
            });
            return;
        }

        state.editingId = id;

        UI.clearFormErrors(els.form);

        UI.setFormValues(els.form, {
            name: studio.name || "",
            location: studio.location || "",
            address: studio.address || "",
            hourly_rate: studio.hourly_rate === null || studio.hourly_rate === undefined
                ? ""
                : studio.hourly_rate,
            description: studio.description || ""
        });

        UI.qs("[data-studio-form-title]").textContent = "Edit studio";
        UI.qs("[data-studio-form-subtitle]").textContent = studio.name || "";
        UI.qs("[data-submit-label]").textContent = "Save changes";

        formModal.open(trigger);
    }

    function validate(values) {
        var errors = {};

        if (!values.name) {
            errors.name = "Studio name is required.";
        } else if (values.name.length > 100) {
            errors.name = "Studio name cannot exceed 100 characters.";
        }

        if (!values.location) {
            errors.location = "Studio location is required.";
        } else if (values.location.length > 100) {
            errors.location = "Studio location cannot exceed 100 characters.";
        }

        var rate = Number(values.hourly_rate);

        if (!values.hourly_rate) {
            errors.hourly_rate = "Hourly rate is required.";
        } else if (!isFinite(rate) || rate <= 0) {
            errors.hourly_rate = "Hourly rate must be greater than zero.";
        }

        if (values.address && values.address.length > 1000) {
            errors.address = "Address cannot exceed 1000 characters.";
        }

        if (values.description && values.description.length > 5000) {
            errors.description = "Description cannot exceed 5000 characters.";
        }

        return errors;
    }

    /** Route a server-side message to the field it belongs to. */
    function fieldForMessage(message) {
        var text = String(message || "").toLowerCase();

        /* Ordered from most specific to least: "name" is checked last because
           it also appears inside unrelated words such as "email". */
        if (text.indexOf("rate") !== -1) {
            return "hourly_rate";
        }
        if (text.indexOf("location") !== -1) {
            return "location";
        }
        if (text.indexOf("address") !== -1) {
            return "address";
        }
        if (text.indexOf("description") !== -1) {
            return "description";
        }
        if (text.indexOf("name") !== -1) {
            return "name";
        }

        return null;
    }

    function submitStudio(event) {
        event.preventDefault();

        var values = UI.readForm(els.form);
        var errors = validate(values);

        UI.clearFormErrors(els.form);

        if (Object.keys(errors).length) {
            Object.keys(errors).forEach(function (field) {
                UI.setFieldError(els.form, field, errors[field]);
            });

            var firstField = els.form.elements[Object.keys(errors)[0]];
            if (firstField) {
                firstField.focus();
            }

            return;
        }

        var payload = {
            name: values.name,
            location: values.location,
            address: values.address,
            hourly_rate: Number(values.hourly_rate),
            description: values.description
        };

        var button = UI.qs("[data-studio-submit]", els.form);
        var editing = state.editingId !== null;

        UI.setButtonLoading(button, true, editing ? "Saving…" : "Creating…");

        var request = editing
            ? API.owner.updateStudio(state.editingId, payload)
            : API.owner.createStudio(payload);

        request
            .then(function (response) {
                formModal.close();

                var name = (response.data && response.data.name) || payload.name;

                UI.toast(
                    editing
                        ? name + " has been updated."
                        : name + " has been added to your studios.",
                    { type: "success", title: editing ? "Studio updated" : "Studio created" }
                );

                /* Re-read the list so the cards reflect server state. */
                return loadStudios();
            })
            .catch(function (error) {
                var field = fieldForMessage(error.message);

                if (field) {
                    UI.setFieldError(els.form, field, error.message);
                } else {
                    UI.setFormError(els.form, error.message);
                }

                UI.toast(error.message, { type: "error", title: "Could not save" });
            })
            .then(function () {
                UI.setButtonLoading(button, false);
            });
    }

    /* ---------------------------------------------------------------------
       Equipment manager
       --------------------------------------------------------------------- */

    function openEquipment(id, trigger) {
        var studio = studioById(id);

        if (!studio) {
            UI.toast("That studio is no longer available. Refresh the list.", {
                type: "warning"
            });
            return;
        }

        state.equipmentStudioId = id;
        state.equipmentDraft = (state.assignments[String(id)] || []).slice();

        UI.qs("[data-equipment-subtitle]").textContent = studio.name || "";
        UI.qs("[data-equipment-error]").innerHTML = "";

        if (!state.equipmentLoaded) {
            setHtml(
                els.equipmentList,
                '<div class="ps-loading"><span class="ps-spinner" aria-hidden="true"></span>' +
                    "<span>Loading equipment catalogue&hellip;</span></div>"
            );
        }

        els.equipmentList.setAttribute("aria-busy", "true");

        equipmentModal.open(trigger);

        if (state.equipmentLoaded || state.catalog.length) {
            /* The catalogue is already in memory, so the manager can be
               populated without touching the network. */
            renderEquipmentList();
        } else {
            ensureCatalogue(id);
        }
    }

    function ensureCatalogue(studioId) {
        return API.owner
            .equipment({ studio_id: studioId })
            .then(function (payload) {
                var data = payload.data || {};

                state.catalog = data.catalog || [];

                var ids = (data.assigned_ids || []).map(function (value) {
                    return UI.number(value);
                });

                state.assignments[String(studioId)] = ids;
                state.equipmentLoaded = true;

                renderEquipmentList();
                render();
            })
            .catch(function (error) {
                els.equipmentList.setAttribute("aria-busy", "false");

                setHtml(
                    els.equipmentList,
                    UI.stateHtml({
                        tone: "error",
                        icon: "alert",
                        title: "Could not load equipment",
                        message: error.message,
                        actionLabel: "Try again",
                        compact: true
                    })
                );

                var retry = UI.qs("[data-state-action]", els.equipmentList);

                if (retry) {
                    retry.addEventListener("click", function () {
                        ensureCatalogue(studioId);
                    });
                }
            });
    }

    function renderEquipmentList() {
        if (!els.equipmentList || state.equipmentStudioId === null) {
            return;
        }

        els.equipmentList.setAttribute("aria-busy", "false");

        var query = String(els.equipmentSearch.value || "")
            .trim()
            .toLowerCase();

        var selected = {};
        state.equipmentDraft.forEach(function (id) {
            selected[id] = true;
        });

        var items = state.catalog.filter(function (item) {
            if (!query) {
                return true;
            }

            return (
                String(item.name || "").toLowerCase().indexOf(query) !== -1 ||
                String(item.category || "").toLowerCase().indexOf(query) !== -1
            );
        });

        updateEquipmentCount(state.equipmentDraft.length);

        if (!state.catalog.length) {
            setHtml(
                els.equipmentList,
                UI.stateHtml({
                    icon: "mic",
                    title: "No equipment available",
                    message:
                        "The equipment catalogue is empty, so there is nothing to assign yet.",
                    compact: true
                })
            );
            return;
        }

        if (!items.length) {
            setHtml(
                els.equipmentList,
                UI.stateHtml({
                    icon: "search",
                    title: "No matches",
                    message: "No equipment matches your search.",
                    compact: true
                })
            );
            return;
        }

        /* Group by category, keeping the catalogue's own ordering stable. */
        var groups = {};
        var order = [];

        items.forEach(function (item) {
            var category = item.category || "Other";

            if (!groups[category]) {
                groups[category] = [];
                order.push(category);
            }

            groups[category].push(item);
        });

        order.sort(function (a, b) {
            var indexA = CATEGORY_ORDER.indexOf(a);
            var indexB = CATEGORY_ORDER.indexOf(b);

            if (indexA === -1 && indexB === -1) {
                return a.localeCompare(b);
            }
            if (indexA === -1) {
                return 1;
            }
            if (indexB === -1) {
                return -1;
            }

            return indexA - indexB;
        });

        var html = order
            .map(function (category) {
                var group = groups[category];

                var assignedCount = group.filter(function (item) {
                    return selected[UI.number(item.id)];
                }).length;

                return (
                    '<section class="ps-equipgroup">' +
                    '<h3 class="ps-equipgroup__title">' +
                    escape(category) +
                    '<span class="ps-equipgroup__count">' +
                    assignedCount +
                    " of " +
                    group.length +
                    "</span></h3>" +
                    '<div class="ps-equipgrid">' +
                    group.map(function (item) {
                        var id = UI.number(item.id);
                        var isOn = Boolean(selected[id]);

                        return (
                            '<label class="ps-equipitem">' +
                            '<input type="checkbox" value="' +
                            id +
                            '" data-equip-id' +
                            (isOn ? " checked" : "") +
                            ">" +
                            '<span class="ps-equipitem__box">' +
                            UI.icon("check-circle", 14) +
                            "</span>" +
                            '<span class="ps-equipitem__text">' +
                            '<span class="ps-equipitem__name">' +
                            escape(item.name) +
                            "</span>" +
                            '<span class="ps-equipitem__assigned">' +
                            (isOn
                                ? UI.icon("check-circle", 11) + "Assigned"
                                : "Available") +
                            "</span>" +
                            "</span>" +
                            "</label>"
                        );
                    }).join("") +
                    "</div></section>"
                );
            })
            .join("");

        setHtml(els.equipmentList, html);

        UI.qsa("[data-equip-id]", els.equipmentList).forEach(function (input) {
            input.addEventListener("change", function () {
                var id = UI.number(input.value);

                if (input.checked) {
                    if (state.equipmentDraft.indexOf(id) === -1) {
                        state.equipmentDraft.push(id);
                    }
                } else {
                    state.equipmentDraft = state.equipmentDraft.filter(function (value) {
                        return value !== id;
                    });
                }

                updateEquipmentCount(state.equipmentDraft.length);
                refreshAssignedLabels();
            });
        });
    }

    /* Update only the small "Assigned / Available" captions in place, which
       keeps the checkboxes from losing focus mid-interaction. */
    function refreshAssignedLabels() {
        var selected = {};
        state.equipmentDraft.forEach(function (id) {
            selected[id] = true;
        });

        UI.qsa("[data-equip-id]", els.equipmentList).forEach(function (input) {
            var label = input.closest(".ps-equipitem");
            var caption = label ? UI.qs(".ps-equipitem__assigned", label) : null;
            var isOn = Boolean(selected[UI.number(input.value)]);

            if (caption) {
                caption.innerHTML = isOn
                    ? UI.icon("check-circle", 11) + "Assigned"
                    : "Available";
            }
        });

        UI.qsa(".ps-equipgroup", els.equipmentList).forEach(function (group) {
            var inputs = UI.qsa("[data-equip-id]", group);
            var count = inputs.filter(function (input) {
                return Boolean(selected[UI.number(input.value)]);
            }).length;

            var badge = UI.qs(".ps-equipgroup__count", group);

            if (badge) {
                badge.textContent = count + " of " + inputs.length;
            }
        });
    }

    function updateEquipmentCount(total) {
        if (els.equipmentCount) {
            els.equipmentCount.textContent =
                total + (total === 1 ? " item selected" : " items selected");
        }

        if (els.equipmentNote) {
            els.equipmentNote.textContent = total
                ? "Saving will replace the current equipment list."
                : "Saving will clear all equipment from this studio.";
        }
    }

    function saveEquipment() {
        var studioId = state.equipmentStudioId;
        var button = els.equipmentSave;

        if (studioId === null) {
            return;
        }

        UI.qs("[data-equipment-error]").innerHTML = "";
        UI.setButtonLoading(button, true, "Saving…");

        API.owner
            .setStudioEquipment(studioId, state.equipmentDraft)
            .then(function (response) {
                var equipment = (response.data && response.data.equipment) || [];

                state.assignments[String(studioId)] = equipment.map(function (item) {
                    return UI.number(item.id);
                });

                state.equipmentDraft = state.assignments[String(studioId)].slice();
                state.equipmentLoaded = true;

                var studio = studioById(studioId);
                UI.toast(
                    "Equipment saved for " + ((studio && studio.name) || "this studio") + ".",
                    { type: "success", title: "Equipment updated" }
                );

                equipmentModal.close();
                render();
            })
            .catch(function (error) {
                UI.qs("[data-equipment-error]").innerHTML =
                    UI.icon("alert", 16) + "<span>" + escape(error.message) + "</span>";

                UI.toast(error.message, { type: "error", title: "Could not save equipment" });
            })
            .then(function () {
                UI.setButtonLoading(button, false);
            });
    }

    /* ---------------------------------------------------------------------
       Wiring
       --------------------------------------------------------------------- */

    function cacheElements() {
        els.summary = UI.qs("[data-studios-summary]");
        els.grid = UI.qs("[data-studio-grid]");
        els.search = UI.qs("[data-studio-search]");
        els.sort = UI.qs("[data-studio-sort]");
        els.form = UI.qs("[data-studio-form]");
        els.equipmentList = UI.qs("[data-equipment-list]");
        els.equipmentSearch = UI.qs("[data-equipment-search]");
        els.equipmentCount = UI.qs("[data-equipment-count]");
        els.equipmentNote = UI.qs("[data-equipment-note]");
        els.equipmentSave = UI.qs("[data-equipment-save]");
    }

    function init() {
        cacheElements();

        formModal = UI.modal("#studio-form-modal");
        equipmentModal = UI.modal("#equipment-modal");

        /* --- Grid delegation: works for any number of cards --- */
        if (els.grid) {
            els.grid.addEventListener("click", function (event) {
                var button = event.target.closest("[data-action]");
                var id = UI.number(button && button.getAttribute("data-studio-id"));

                if (!button || !id) {
                    return;
                }

                if (button.getAttribute("data-action") === "edit") {
                    openEdit(id, button);
                } else if (button.getAttribute("data-action") === "equipment") {
                    openEquipment(id, button);
                }
            });
        }

        /* --- Add studio --- */
        UI.qsa("[data-open-add]").forEach(function (button) {
            button.addEventListener("click", function () {
                openCreate(button);
            });
        });

        /* --- Form --- */
        if (els.form) {
            els.form.addEventListener("submit", submitStudio);

            /* Clear a field's error as soon as the owner edits it. */
            UI.qsa("input, textarea", els.form).forEach(function (field) {
                field.addEventListener("input", function () {
                    var wrapper = field.closest(".ps-field");

                    if (wrapper && wrapper.classList.contains("has-error")) {
                        UI.clearFieldError(els.form, field.name);
                    }
                });
            });
        }

        /* --- Search & sort --- */
        if (els.search) {
            els.search.addEventListener(
                "input",
                UI.debounce(function () {
                    state.query = els.search.value;
                    render();
                }, 180)
            );
        }

        if (els.sort) {
            els.sort.addEventListener("change", function () {
                state.sort = els.sort.value;
                render();
            });
        }

        /* --- Equipment manager --- */
        if (els.equipmentSearch) {
            els.equipmentSearch.addEventListener(
                "input",
                UI.debounce(function () {
                    renderEquipmentList();
                }, 160)
            );
        }

        if (els.equipmentSave) {
            els.equipmentSave.addEventListener("click", saveEquipment);
        }

        loadStudios();

        /* Deep link from the dashboard / quick actions. */
        if (/[?&]action=add(&|$)/.test(global.location.search)) {
            global.setTimeout(function () {
                openCreate(UI.qs("[data-open-add]"));
            }, 220);
        }
    }

    global.ParadiseStudios = { init: init };

    UI.onReady(init);
})(window);