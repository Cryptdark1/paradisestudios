/**
 * Paradise Studios — Artist profile.
 *
 * Saves name and email through PUT /api/artist-update-profile.php.
 *
 * Only those two fields are sent. Role, XP (buzz_level) and the user ID are
 * never included, and the backend rejects them regardless — so there is no
 * path from this page to changing privileges or inflating XP.
 *
 * The theme row on this page is a status readout only. The single theme toggle
 * lives in the topbar, as it does on every other page.
 */
(function (global) {
    "use strict";

    var API = global.ArtistAPI;
    var UI = global.UI;
    var escape = UI.escape;

    var original = { name: "", email: "" };
    var els = {};

    /* ---------------------------------------------------------------------
       Validation
       --------------------------------------------------------------------- */

    function validate(values) {
        var errors = {};

        var name = String(values.name || "").trim();

        if (!name) {
            errors.name = "Name is required.";
        } else if (name.length > 100) {
            errors.name = "Name cannot exceed 100 characters.";
        }

        var email = String(values.email || "").trim();

        if (!email) {
            errors.email = "Email address is required.";
        } else if (email.length > 100) {
            errors.email = "Email cannot exceed 100 characters.";
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
            errors.email = "Please enter a valid email address.";
        }

        return errors;
    }

    /** Route a server message to the field it belongs to. */
    function fieldForMessage(message) {
        var text = String(message || "").toLowerCase();

        /* "email" is checked first because it also contains "name". */
        if (text.indexOf("email") !== -1) {
            return "email";
        }
        if (text.indexOf("name") !== -1) {
            return "name";
        }

        return null;
    }

    function hasChanges(values) {
        return (
            String(values.name || "").trim() !== original.name ||
            String(values.email || "").trim() !== original.email
        );
    }

    /* ---------------------------------------------------------------------
       Reflect a saved profile everywhere it is displayed
       --------------------------------------------------------------------- */

    function applySavedProfile(user) {
        if (!user) {
            return;
        }

        original.name = String(user.name || "").trim();
        original.email = String(user.email || "").trim();

        [
            "[data-profile-name]",
            "[data-row-name]",
            ".ps-usermenu__meta strong",
            ".ps-menu__header strong"
        ].forEach(function (selector) {
            var node = UI.qs(selector);

            if (node) {
                node.textContent = user.name;
            }
        });

        [
            "[data-profile-email]",
            "[data-row-email]",
            ".ps-menu__header small"
        ].forEach(function (selector) {
            var node = UI.qs(selector);

            if (node) {
                node.textContent = user.email;
            }
        });

        UI.qsa(".ps-avatar").forEach(function (avatar) {
            avatar.textContent = UI.initials(user.name);
        });
    }

    /* ---------------------------------------------------------------------
       Theme status readout
       --------------------------------------------------------------------- */

    function paintThemeState() {
        var badge = UI.qs("[data-theme-state]");
        var hint = UI.qs("[data-theme-hint]");

        if (!badge || !global.ParadiseTheme) {
            return;
        }

        var theme = global.ParadiseTheme.get();
        var next = theme === "dark" ? "light" : "dark";

        badge.className = "ps-badge " + (theme === "dark" ? "ps-badge--violet" : "ps-badge--warning");
        badge.innerHTML =
            UI.icon(theme === "dark" ? "moon" : "sun", 12) +
            "<span>" +
            escape(theme === "dark" ? "Dark mode" : "Light mode") +
            "</span>";

        if (hint) {
            hint.textContent = "Tap " + next + " in the top bar to switch";
        }
    }

    /* ---------------------------------------------------------------------
       Submit
       --------------------------------------------------------------------- */

    function submit(event) {
        event.preventDefault();

        var values = UI.readForm(els.form);
        var errors = validate(values);

        UI.clearFormErrors(els.form);

        if (Object.keys(errors).length) {
            Object.keys(errors).forEach(function (field) {
                UI.setFieldError(els.form, field, errors[field]);
            });

            var first = els.form.elements[Object.keys(errors)[0]];
            if (first) {
                first.focus();
            }

            return;
        }

        if (!hasChanges(values)) {
            UI.setFormError(els.form, "There are no changes to save yet.");
            return;
        }

        var button = UI.qs("[data-profile-submit]", els.form);

        UI.setButtonLoading(button, true, "Saving…");

        API.updateProfile({
            name: String(values.name).trim(),
            email: String(values.email).trim()
        })
            .then(function (response) {
                applySavedProfile(response.data);

                UI.clearFormErrors(els.form);

                UI.toast(
                    (response.message || "Profile updated.") +
                        " Use your new email next time you sign in.",
                    { type: "success", title: "Profile saved" }
                );
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

    function reset() {
        UI.clearFormErrors(els.form);

        els.form.elements.name.value = original.name;
        els.form.elements.email.value = original.email;

        UI.toast("Changes discarded.", { type: "info", title: "", timeout: 2200 });
    }

    /* ---------------------------------------------------------------------
       Wiring
       --------------------------------------------------------------------- */

    function cacheElements() {
        els.form = UI.qs("[data-profile-form]");
    }

    function init() {
        cacheElements();

        paintThemeState();

        /* Keep the readout in step with the single topbar toggle. */
        global.addEventListener("paradise:themechange", paintThemeState);

        if (!els.form) {
            return;
        }

        original.name = String(els.form.elements.name.value || "").trim();
        original.email = String(els.form.elements.email.value || "").trim();

        els.form.addEventListener("submit", submit);

        var resetButton = UI.qs("[data-profile-reset]");

        if (resetButton) {
            resetButton.addEventListener("click", reset);
        }

        UI.qsa("input", els.form).forEach(function (field) {
            field.addEventListener("input", function () {
                var wrapper = field.closest(".ps-field");

                if (wrapper && wrapper.classList.contains("has-error")) {
                    UI.clearFieldError(els.form, field.name);
                }
            });
        });

        /* Warn before navigating away with unsaved edits. */
        global.addEventListener("beforeunload", function (event) {
            if (!hasChanges(UI.readForm(els.form))) {
                return undefined;
            }

            event.preventDefault();
            event.returnValue = "";
            return "";
        });
    }

    global.ParadiseArtistProfile = { init: init };

    UI.onReady(init);
})(window);