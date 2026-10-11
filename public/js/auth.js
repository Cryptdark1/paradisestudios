/**
 * Paradise Studios — auth screen behaviour.
 *
 * Deliberately standalone: it does not load api.js or ui.js because the auth
 * screen must work without a session, and those modules add nothing here.
 *
 * Provides:
 *   - show/hide password toggle (with the correct accessible state)
 *   - client-side validation before submitting
 *   - a submit button loading state, so a slow request cannot be double-sent
 *   - optional password strength meter (register screen)
 */
(function (global) {
    "use strict";

    var doc = global.document;

    function qs(selector, scope) {
        return (scope || doc).querySelector(selector);
    }

    function qsa(selector, scope) {
        return Array.prototype.slice.call((scope || doc).querySelectorAll(selector));
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }

    /* ---------------------------------------------------------------------
       Show / hide password
       --------------------------------------------------------------------- */

    function initPasswordToggles() {
        qsa("[data-password-toggle]").forEach(function (button) {
            var field = doc.getElementById(button.getAttribute("data-password-target")) ||
                button.parentNode.querySelector('input[type="password"], input[type="text"]');

            if (!field) {
                return;
            }

            button.addEventListener("click", function () {
                var willShow = field.type === "password";

                field.type = willShow ? "text" : "password";

                button.setAttribute("aria-pressed", willShow ? "true" : "false");
                button.setAttribute(
                    "aria-label",
                    willShow ? "Hide password" : "Show password"
                );
                button.setAttribute("title", willShow ? "Hide password" : "Show password");

                /* Keep the caret where the user left it. */
                field.focus();
            });
        });
    }

    /* ---------------------------------------------------------------------
       Password strength (register)
       --------------------------------------------------------------------- */

    function scorePassword(value) {
        var score = 0;

        if (value.length >= 8) {
            score++;
        }
        if (value.length >= 12) {
            score++;
        }
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) {
            score++;
        }
        if (/\d/.test(value)) {
            score++;
        }
        if (/[^\w\s]/.test(value)) {
            score++;
        }

        return Math.min(4, score);
    }

    function initStrength() {
        var field = qs("[data-strength-source]");
        var host = qs("[data-strength]");

        if (!field || !host) {
            return;
        }

        var label = qs("[data-strength-label]", host);
        var bars = qsa(".ps-strength__bar", host);

        function paint() {
            var value = field.value;

            if (!value) {
                host.className = "ps-strength";
                if (label) {
                    label.textContent = "";
                }
                return;
            }

            var score = scorePassword(value);
            var names = ["", "Weak", "Fair", "Good", "Strong"];

            host.className =
                "ps-strength " +
                (["", "is-weak", "is-fair", "is-good", "is-strong"][score] || "");

            if (label) {
                label.textContent = names[score];
            }

            /* Screen-reader text describing the strength, not just the colour. */
            host.setAttribute(
                "aria-label",
                value ? "Password strength: " + names[score] : ""
            );

            void bars;
        }

        field.addEventListener("input", paint);
        paint();
    }

    /* ---------------------------------------------------------------------
       Validation + submit
       --------------------------------------------------------------------- */

    function setFieldError(form, name, message) {
        var field = form.elements[name];

        if (!field) {
            return;
        }

        var wrapper = field.closest(".ps-field");
        var node = wrapper ? qs("[data-field-error]", wrapper) : null;

        field.setAttribute("aria-invalid", "true");

        if (wrapper) {
            wrapper.classList.add("has-error");
        }

        if (node) {
            node.innerHTML = message
                ? '<span class="ps-alert-inline">' +
                  escapeHtml(message) +
                  "</span>"
                : "";
        }
    }

    function clearErrors(form) {
        qsa("[data-field-error]", form).forEach(function (node) {
            node.innerHTML = "";
        });

        qsa(".ps-field", form).forEach(function (node) {
            node.classList.remove("has-error");
        });

        qsa("input", form).forEach(function (field) {
            field.removeAttribute("aria-invalid");
        });
    }

    function initForm(form, rules) {
        var button = qs("[data-auth-submit]", form);

        form.addEventListener("submit", function (event) {
            clearErrors(form);

            var firstInvalid = null;

            Object.keys(rules).forEach(function (fieldName) {
                var message = rules[fieldName](form);
                var field = form.elements[fieldName];

                /* Radios are a group: only flag when none is selected. */
                if (field && field.type === "radio" && field.checked) {
                    return;
                }

                if (message) {
                    setFieldError(form, fieldName, message);

                    if (!firstInvalid) {
                        firstInvalid = field;
                    }
                }
            });

            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
                return;
            }

            /*
             * Let the form submit, but disable the button first so a double
             * click cannot send the request twice.
             */
            if (button) {
                button.disabled = true;
                button.setAttribute("aria-busy", "true");
                button.dataset.originalLabel = button.textContent;
                button.textContent = "Please wait…";
            }
        });

        /* Clear a field's error as soon as the user edits it. */
        qsa("input", form).forEach(function (field) {
            field.addEventListener("input", function () {
                var wrapper = field.closest(".ps-field");

                if (wrapper && wrapper.classList.contains("has-error")) {
                    wrapper.classList.remove("has-error");
                    field.removeAttribute("aria-invalid");

                    var node = qs("[data-field-error]", wrapper);
                    if (node) {
                        node.innerHTML = "";
                    }
                }
            });
        });
    }

    function init() {
        initPasswordToggles();
        initStrength();

        var login = qs("[data-auth-form]");

        if (login && login.elements.password) {
            initForm(login, {
                email: function (form) {
                    var value = String(form.elements.email.value || "").trim();

                    if (!value) {
                        return "Please enter your email address.";
                    }
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
                        return "Please enter a valid email address.";
                    }
                    return "";
                },
                password: function (form) {
                    return form.elements.password.value
                        ? ""
                        : "Please enter your password.";
                }
            });
        }

        var register = qs("[data-register-form]");

        if (register) {
            initForm(register, {
                name: function (form) {
                    var value = String(form.elements.name.value || "").trim();
                    return value ? "" : "Please enter your name.";
                },
                email: function (form) {
                    var value = String(form.elements.email.value || "").trim();

                    if (!value) {
                        return "Please enter your email address.";
                    }
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
                        return "Please enter a valid email address.";
                    }
                    return "";
                },
                password: function (form) {
                    var value = String(form.elements.password.value || "");

                    if (!value) {
                        return "Please choose a password.";
                    }
                    /* Mirrors the backend's minimum of 8 characters. */
                    if (value.length < 8) {
                        return "Password must be at least 8 characters.";
                    }
                    return "";
                },
                confirm_password: function (form) {
                    if (
                        String(form.elements.password.value || "") !==
                        String(form.elements.confirm_password.value || "")
                    ) {
                        return "Passwords do not match.";
                    }
                    return "";
                },
                role: function (form) {
                    var checked = form.querySelector('input[name="role"]:checked');

                    if (!checked) {
                        return "Choose whether you are an artist or a studio owner.";
                    }
                    return "";
                }
            });
        }
    }

    if (doc.readyState === "loading") {
        doc.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})(window);