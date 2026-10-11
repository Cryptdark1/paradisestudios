/**
 * Paradise Studios — Owner profile.
 *
 * The account is rendered server-side from the session. This module only
 * handles saving changes through PUT /api/owner-update-profile.php, which
 * accepts the signed-in user's name and email and nothing else.
 */
(function (global) {
    "use strict";

    var API = global.API;
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

    /** Attach a server-side message to the field it refers to. */
    function fieldForMessage(message) {
        var text = String(message || "").toLowerCase();

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
       Keep the visible identity in step with a successful save
       --------------------------------------------------------------------- */

    function applySavedProfile(user) {
        if (!user) {
            return;
        }

        original.name = String(user.name || "").trim();
        original.email = String(user.email || "").trim();

        var nameNode = UI.qs(".ps-profile__name");
        var emailNode = UI.qs(".ps-profile__email");

        if (nameNode) {
            nameNode.textContent = user.name;
        }
        if (emailNode) {
            emailNode.textContent = user.email;
        }

        /* Update the definition list entries in place. */
        var rows = UI.qsa(".ps-dl--stacked .ps-dl__row");
        rows.forEach(function (row) {
            var label = row.querySelector("dt");
            var value = row.querySelector("dd");

            if (!label || !value) {
                return;
            }

            var key = label.textContent.trim().toLowerCase();

            if (key === "full name") {
                value.textContent = user.name;
            } else if (key === "email address") {
                value.textContent = user.email;
            }
        });

        /* Topbar + user menu */
        var topbarName = UI.qs(".ps-usermenu__meta strong");
        var menuName = UI.qs(".ps-menu__header strong");
        var menuEmail = UI.qs(".ps-menu__header small");

        if (topbarName) {
            topbarName.textContent = user.name;
        }
        if (menuName) {
            menuName.textContent = user.name;
        }
        if (menuEmail) {
            menuEmail.textContent = user.email;
        }

        var avatarNodes = UI.qsa(".ps-usermenu .ps-avatar");
        avatarNodes.forEach(function (avatar) {
            avatar.textContent = UI.initials(user.name);
        });

        var bigAvatar = UI.qs(".ps-profile__head .ps-avatar--xl");
        if (bigAvatar) {
            bigAvatar.textContent = UI.initials(user.name);
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

        API.owner
            .updateProfile({
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

    function init() {
        els.form = UI.qs("[data-profile-form]");

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

        /* Clear a field error as soon as the owner edits it. */
        UI.qsa("input", els.form).forEach(function (field) {
            field.addEventListener("input", function () {
                var wrapper = field.closest(".ps-field");

                if (wrapper && wrapper.classList.contains("has-error")) {
                    UI.clearFieldError(els.form, field.name);
                }
            });
        });

        /* Warn before leaving with unsaved edits. */
        global.addEventListener("beforeunload", function (event) {
            if (!hasChanges(UI.readForm(els.form))) {
                return undefined;
            }

            event.preventDefault();
            /* Legacy browsers require returnValue to be set. */
            event.returnValue = "";
            return "";
        });

        void escape;
    }

    global.ParadiseProfile = { init: init };

    UI.onReady(init);
})(window);