/**
 * Paradise Studios — shared UI kit.
 *
 * Reusable, framework-free components used by every owner page:
 * escaping, formatters, status badges, toasts, an accessible modal manager,
 * button loading states and empty / error / skeleton states.
 *
 * Keeping these here avoids duplicated CSS classes and duplicated JS across
 * pages, and guarantees the pages behave identically.
 */
(function (global) {
    "use strict";

    /* ---------------------------------------------------------------------
       Constants
       --------------------------------------------------------------------- */

    var LOCALE = "en-NG";
    var CURRENCY = "NGN"; /* Studios are in Lagos; the design shows ₦ */

    /* Booking status -> presentation. Every badge carries an icon AND a text
       label so meaning is never conveyed by colour alone. */
    var STATUS_MAP = {
        pending: { label: "Pending", tone: "warning", icon: "clock" },
        confirmed: { label: "Confirmed", tone: "info", icon: "check-circle" },
        escrow_held: { label: "Escrow held", tone: "violet", icon: "lock" },
        in_session: { label: "In session", tone: "teal", icon: "music" },
        completed: { label: "Completed", tone: "success", icon: "check-circle" },
        cancelled: { label: "Cancelled", tone: "neutral", icon: "x-circle" },
        disputed: { label: "Disputed", tone: "danger", icon: "alert" }
    };

    var FOCUSABLE =
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
        "select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\"-1\"])";

    /* ---------------------------------------------------------------------
       Small helpers
       --------------------------------------------------------------------- */

    function qs(selector, scope) {
        return (scope || document).querySelector(selector);
    }

    function qsa(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    }

    /** Escape untrusted text before it is placed in innerHTML. */
    function escape(value) {
        if (value === null || value === undefined) {
            return "";
        }

        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function debounce(fn, wait) {
        var timer = null;

        return function () {
            var context = this;
            var args = arguments;

            global.clearTimeout(timer);
            timer = global.setTimeout(function () {
                fn.apply(context, args);
            }, wait || 200);
        };
    }

    function onReady(fn) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", fn);
        } else {
            fn();
        }
    }

    /* ---------------------------------------------------------------------
       Formatting
       --------------------------------------------------------------------- */

    function toNumber(value) {
        var num = typeof value === "number" ? value : parseFloat(value);
        return isFinite(num) ? num : 0;
    }

    /** ₦45,000 — full precision, used for amounts owed to the owner. */
    function money(value, options) {
        var opts = options || {};

        try {
            return new Intl.NumberFormat(LOCALE, {
                style: "currency",
                currency: CURRENCY,
                minimumFractionDigits: opts.decimals === false ? 0 : 0,
                maximumFractionDigits: opts.decimals || 0
            }).format(toNumber(value));
        } catch (err) {
            return "₦" + Math.round(toNumber(value)).toLocaleString("en-US");
        }
    }

    /** ₦2.4M — compact form for headline figures, matching the design. */
    function compactMoney(value) {
        var num = toNumber(value);
        var abs = Math.abs(num);

        if (abs >= 1000000000) {
            return "₦" + trimZeros((num / 1000000000).toFixed(2)) + "B";
        }
        if (abs >= 1000000) {
            return "₦" + trimZeros((num / 1000000).toFixed(2)) + "M";
        }
        if (abs >= 100000) {
            return "₦" + trimZeros((num / 1000).toFixed(1)) + "K";
        }
        return money(num);
    }

    function trimZeros(value) {
        return value.replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1");
    }

    /** Hourly rate, e.g. ₦15,000/hour */
    function rate(value) {
        return money(value) + "/hour";
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }

        /* MySQL DATETIME ("2026-10-10 10:00:00") is parsed consistently by
           constructing an explicit local date. */
        if (typeof value === "string") {
            var match = value.match(
                /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/
            );

            if (match) {
                return new Date(
                    Number(match[1]),
                    Number(match[2]) - 1,
                    Number(match[3]),
                    Number(match[4] || 0),
                    Number(match[5] || 0),
                    Number(match[6] || 0)
                );
            }
        }

        var parsed = new Date(value);
        return isNaN(parsed.getTime()) ? null : parsed;
    }

    function date(value, options) {
        var opts = options || {};
        var parsed = parseDate(value);

        if (!parsed) {
            return "—";
        }

        try {
            return parsed.toLocaleDateString(LOCALE, {
                day: opts.day || "numeric",
                month: opts.month || "short",
                year: opts.year === false ? undefined : "numeric"
            });
        } catch (err) {
            return parsed.toDateString();
        }
    }

    function time(value) {
        var parsed = parseDate(value);
        return parsed ? parsed.toLocaleTimeString(LOCALE, { hour: "numeric", minute: "2-digit" }) : "—";
    }

    function dateTime(value) {
        var parsed = parseDate(value);
        if (!parsed) {
            return "—";
        }
        return date(value) + " · " + time(value);
    }

    /** "Today", "Tomorrow", or a date — used in upcoming lists. */
    function friendlyDate(value) {
        var parsed = parseDate(value);

        if (!parsed) {
            return "—";
        }

        var today = new Date();
        today.setHours(0, 0, 0, 0);

        var target = new Date(parsed.getTime());
        target.setHours(0, 0, 0, 0);

        var diffDays = Math.round((target - today) / 86400000);

        if (diffDays === 0) {
            return "Today";
        }
        if (diffDays === 1) {
            return "Tomorrow";
        }
        if (diffDays === -1) {
            return "Yesterday";
        }

        return date(value);
    }

    function relativeTime(value) {
        var parsed = parseDate(value);
        if (!parsed) {
            return "—";
        }

        var seconds = Math.round((Date.now() - parsed.getTime()) / 1000);
        var abs = Math.abs(seconds);

        if (abs < 60) {
            return "just now";
        }
        if (abs < 3600) {
            var mins = Math.round(abs / 60);
            return mins + (mins === 1 ? " minute ago" : " minutes ago");
        }
        if (abs < 86400) {
            var hours = Math.round(abs / 3600);
            return hours + (hours === 1 ? " hour ago" : " hours ago");
        }

        return date(value);
    }

    /** Percentage change between two real periods. */
    function delta(current, previous) {
        var now = toNumber(current);
        var before = toNumber(previous);

        if (before === 0) {
            return { percent: null, direction: "flat", text: "no prior data" };
        }

        var change = ((now - before) / before) * 100;
        var direction = change > 0.05 ? "up" : change < -0.05 ? "down" : "flat";

        return {
            percent: change,
            direction: direction,
            text: (change > 0 ? "+" : "") + change.toFixed(1) + "%"
        };
    }

    function initials(name) {
        var parts = String(name || "")
            .trim()
            .split(/\s+/)
            .filter(Boolean);

        if (!parts.length) {
            return "?";
        }

        if (parts.length === 1) {
            return parts[0].slice(0, 2).toUpperCase();
        }

        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    function truncate(text, max) {
        var value = String(text === null || text === undefined ? "" : text);
        return value.length > max ? value.slice(0, Math.max(0, max - 1)) + "…" : value;
    }

    /* ---------------------------------------------------------------------
       Inline SVG (mirrors includes/icons.php so JS-rendered markup matches)
       --------------------------------------------------------------------- */

    var ICON_BODIES = {
        "check-circle": '<circle cx="12" cy="12" r="9"/><path d="m8.2 12.3 2.6 2.6 5-5.4"/>',
        "x-circle": '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
        alert: '<path d="M10.3 4 2.3 18.2A2 2 0 0 0 4 21.2h16a2 2 0 0 0 1.7-3L13.7 4a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17h.01"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.3l3.2 1.9"/>',
        lock: '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        music: '<path d="M9 17.5V4.8l11-2v12.4"/><ellipse cx="6.2" cy="17.6" rx="2.7" ry="2.4"/><ellipse cx="17.2" cy="15.6" rx="2.7" ry="2.4"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11.2v5M12 7.8h.01"/>',
        inbox: '<path d="M3 12.5h4.5l1.6 2.8h5.8l1.6-2.8H21"/><path d="M5.6 4.5h12.8l2.6 8v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',
        ticket: '<path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2.2 2.2 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2.2 2.2 0 0 0 0-6z"/><path d="M12 7.5v9"/>',
        mic: '<rect x="9" y="2.5" width="6" height="11" rx="3"/><path d="M5 11v1a7 7 0 0 0 14 0v-1"/><path d="M12 19v2.5M9 21.5h6"/>',
        studios: '<path d="M3 21h18"/><path d="M5 21V5.5A2.5 2.5 0 0 1 7.5 3H13a2 2 0 0 1 2 2.5V21"/><path d="M15 9.5h1.5A2.5 2.5 0 0 1 19 12v9"/><path d="M9 7h2M9 11h2M9 15h2"/>',
        bookings: '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        calendar: '<rect x="3" y="4.5" width="18" height="16.5" rx="2.5"/><path d="M16 2.5v4M8 2.5v4M3 10h18M8 14h2M14 14h2M8 17.5h2"/>',
        star: '<path d="m12 3 2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8-5.4 2.8 1-6L3.3 9.4l6-.9z"/>',
        "star-off": '<path d="m12 3 2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8-5.4 2.8 1-6L3.3 9.4l6-.9z"/>',
        upload: '<path d="M12 15.5V3.5"/><path d="m7.5 8 4.5-4.5L16.5 8"/><path d="M4 15v3.5A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5V15"/>',
        "volume-2": '<path d="M11 4.5 6.5 8.5H3v7h3.5L11 19.5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/>',
        play: '<path d="M7 4.5v15l12-7.5z"/>',
        sliders2: '<path d="M4 20.5V14M4 10V3.5M12 20.5V12M12 8V3.5M20 20.5V16M20 12V3.5M1.5 14h5M9.5 8h5M17.5 16h5"/>',
        download: '<path d="M12 3.5v12"/><path d="m7.5 11 4.5 4.5 4.5-4.5"/><path d="M4 17v1.5A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5V17"/>',
        bolt: '<path d="M13.2 2.5 4.5 13.4h6.1l-.8 8.1 8.7-10.9h-6.1z"/>',
        analytics: '<path d="M3 3v15.5A2.5 2.5 0 0 0 5.5 21H21"/><path d="m7 15 3.6-4.2 3 2.6L18 7"/><circle cx="18" cy="7" r="1.4"/>',
        "chart-line": '<path d="M3 3v15.5A2.5 2.5 0 0 0 5.5 21H21"/><path d="m6 16 4-5 3.5 3L20 6"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.2-4.2"/>',
        plus: '<path d="M12 5.25v13.5M5.25 12h13.5"/>',
        edit: '<path d="M17.5 3.2a2.6 2.6 0 0 1 3.7 3.7L7.6 20.5l-5 1.3 1.3-5z"/>',
        refresh: '<path d="M20.5 12a8.5 8.5 0 1 1-2.9-6.4"/><path d="M20.5 4.5V10H15"/>',
        "arrow-up": '<path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/>',
        "arrow-down": '<path d="M12 5v14M18.5 12.5 12 19l-6.5-6.5"/>',
        minus: '<path d="M5.25 12h13.5"/>',
        warning: '<path d="M10.3 4 2.3 18.2A2 2 0 0 0 4 21.2h16a2 2 0 0 0 1.7-3L13.7 4a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17h.01"/>',
        "external": '<path d="M14 3.5h6.5V10"/><path d="M10.5 13.5 20 4"/><path d="M20 14.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.5"/>'
    };

    /** Build an inline SVG icon string (same visual language as PHP icons). */
    function icon(name, size) {
        var body = ICON_BODIES[name];

        if (!body) {
            return "";
        }

        var px = size || 16;

        return (
            '<svg class="ps-icon" width="' +
            px +
            '" height="' +
            px +
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
            body +
            "</svg>"
        );
    }

    /* ---------------------------------------------------------------------
       Status badges
       --------------------------------------------------------------------- */

    function statusMeta(status) {
        var key = String(status || "").toLowerCase();

        return (
            STATUS_MAP[key] || {
                label: key ? key.replace(/_/g, " ") : "Unknown",
                tone: "neutral",
                icon: "info"
            }
        );
    }

    /** Accessible status badge markup: coloured dot + icon + text label. */
    function statusBadge(status) {
        var meta = statusMeta(status);
        var label = meta.label.charAt(0).toUpperCase() + meta.label.slice(1);

        return (
            '<span class="ps-badge ps-badge--' +
            meta.tone +
            '" data-status="' +
            escape(String(status || "unknown")) +
            '">' +
            icon(meta.icon, 12) +
            '<span class="ps-badge__label">' +
            escape(label) +
            "</span>" +
            "</span>"
        );
    }

    /* ---------------------------------------------------------------------
       Toasts
       --------------------------------------------------------------------- */

    var toastHost = null;

    function ensureToastHost() {
        if (!toastHost || !document.body.contains(toastHost)) {
            toastHost = qs("[data-ps-toasts]") || document.createElement("div");

            if (!toastHost.hasAttribute("data-ps-toasts")) {
                toastHost.className = "ps-toasts";
                toastHost.setAttribute("data-ps-toasts", "");
                toastHost.setAttribute("role", "status");
                toastHost.setAttribute("aria-live", "polite");
                toastHost.setAttribute("aria-atomic", "false");
            }

            if (!document.body.contains(toastHost)) {
                document.body.appendChild(toastHost);
            }
        }

        return toastHost;
    }

    /**
     * Show a transient notification.
     * @param {string} message
     * @param {{type?: "success"|"error"|"warning"|"info", title?: string, timeout?: number}} [options]
     */
    function toast(message, options) {
        var opts = options || {};
        var type = opts.type || "info";

        var icons = {
            success: "check-circle",
            error: "x-circle",
            warning: "warning",
            info: "info"
        };

        var host = ensureToastHost();
        var node = document.createElement("div");

        node.className = "ps-toast ps-toast--" + type;
        node.innerHTML =
            '<span class="ps-toast__icon">' +
            icon(icons[type] || "info", 18) +
            "</span>" +
            '<div class="ps-toast__body">' +
            (opts.title ? '<div class="ps-toast__title">' + escape(opts.title) + "</div>" : "") +
            '<div class="ps-toast__msg">' + escape(message) + "</div>" +
            "</div>" +
            '<button type="button" class="ps-toast__close" aria-label="Dismiss notification">' +
            icon("close", 15) +
            "</button>";

        host.appendChild(node);

        var timer = null;

        function dismiss() {
            if (!node.parentNode) {
                return;
            }
            node.classList.add("is-leaving");
            global.setTimeout(function () {
                if (node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            }, 200);
        }

        node.querySelector(".ps-toast__close").addEventListener("click", dismiss);
        timer = global.setTimeout(dismiss, opts.timeout || 5000);

        return { dismiss: dismiss, node: node };
    }

    /* ---------------------------------------------------------------------
       Modals
       --------------------------------------------------------------------- */

    var openModals = [];

    /**
     * Wrap a modal element with accessible open/close behaviour:
     * focus is moved in and trapped, Escape and the backdrop close it, and
     * the previously focused element is restored on close.
     *
     * @param {HTMLElement|string} target
     */
    function modal(target) {
        var node = typeof target === "string" ? qs(target) : target;

        if (!node) {
            return { open: function () {}, close: function () {} };
        }

        var panel = qs("[data-modal-panel]", node) || node;
        var lastFocused = null;

        function focusable() {
            return qsa(FOCUSABLE, panel).filter(function (el) {
                return el.offsetParent !== null || el === document.activeElement;
            });
        }

        function onKeydown(event) {
            if (!node.classList.contains("is-open")) {
                return;
            }

            if (event.key === "Escape") {
                event.preventDefault();
                close();
                return;
            }

            if (event.key !== "Tab") {
                return;
            }

            var items = focusable();

            if (!items.length) {
                event.preventDefault();
                return;
            }

            var first = items[0];
            var last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        function open(trigger) {
            if (node.classList.contains("is-open")) {
                return;
            }

            lastFocused = trigger || document.activeElement;

            node.classList.add("is-open");
            node.setAttribute("aria-hidden", "false");
            document.body.classList.add("ps-modal-open");
            openModals.push(node);

            var items = focusable();
            var target = qs("[data-autofocus]", panel) || items[0];

            if (target) {
                global.setTimeout(function () {
                    target.focus();
                }, 40);
            }
        }

        function close() {
            if (!node.classList.contains("is-open")) {
                return;
            }

            node.classList.remove("is-open");
            node.setAttribute("aria-hidden", "true");
            openModals = openModals.filter(function (m) {
                return m !== node;
            });

            if (!openModals.length) {
                document.body.classList.remove("ps-modal-open");
            }

            if (lastFocused && document.contains(lastFocused)) {
                lastFocused.focus();
            }

            lastFocused = null;

            /* Any listener (e.g. the confirm dialog) can react to a dismissal
               regardless of how the modal was closed. */
            node.dispatchEvent(new CustomEvent("modal:dismissed", { bubbles: false }));
        }

        /* Backdrop / close-button wiring, marked with data-modal-close. */
        qsa("[data-modal-close]", node).forEach(function (btn) {
            btn.addEventListener("click", function (event) {
                event.preventDefault();
                close();
            });
        });

        var backdrop = qs(".ps-modal__backdrop", node);
        if (backdrop) {
            backdrop.addEventListener("click", close);
        }

        document.addEventListener("keydown", onKeydown);

        return { node: node, panel: panel, open: open, close: close, isOpen: function () { return node.classList.contains("is-open"); } };
    }

    /* Confirmation dialog built on the shared modal shell. */
    function confirmDialog(options) {
        var opts = options || {};

        var node = document.createElement("div");
        node.className = "ps-modal";
        node.setAttribute("role", "dialog");
        node.setAttribute("aria-modal", "true");
        node.setAttribute("aria-hidden", "true");
        node.setAttribute("aria-labelledby", "ps-confirm-title");

        var danger = opts.tone === "danger";
        var confirmLabel = opts.confirmLabel || (danger ? "Delete" : "Confirm");
        var cancelId = "ps-confirm-cancel-" + Date.now();

        node.innerHTML =
            '<div class="ps-modal__backdrop"></div>' +
            '<div class="ps-modal__panel ps-modal__panel--sm" data-modal-panel>' +
            '<div class="ps-modal__head">' +
            '<div class="ps-modal__head-text">' +
            '<h2 id="ps-confirm-title">' +
            escape(opts.title || "Are you sure?") +
            "</h2>" +
            (opts.message ? "<p>" + escape(opts.message) + "</p>" : "") +
            "</div>" +
            "</div>" +
            '<div class="ps-modal__foot">' +
            '<button type="button" class="ps-btn ps-btn--secondary" id="' +
            cancelId +
            '" data-modal-close data-autofocus>Cancel</button>' +
            '<button type="button" class="ps-btn ' +
            (danger ? "ps-btn--danger" : "ps-btn--primary") +
            '" data-confirm-yes>' +
            escape(confirmLabel) +
            "</button>" +
            "</div>" +
            "</div>";

        document.body.appendChild(node);

        var controller = modal(node);

        return new Promise(function (resolve) {
            var settled = false;

            function finish(value) {
                if (settled) {
                    return;
                }
                settled = true;
                resolve(value);

                if (node.parentNode) {
                    document.body.removeChild(node);
                }
            }

            node.querySelector("[data-confirm-yes]").addEventListener("click", function () {
                finish(true);
                controller.close();
            });

            /* Cancel button, backdrop click or Escape all land here. */
            node.addEventListener("modal:dismissed", function () {
                finish(false);
            });

            controller.open();
        });
    }

    /* ---------------------------------------------------------------------
       Buttons & forms
       --------------------------------------------------------------------- */

    /**
     * Toggle a real loading state on a button: spinner, disabled and
     * aria-busy while the request is in flight.
     */
    function setButtonLoading(button, isLoading, loadingLabel) {
        if (!button) {
            return;
        }

        if (isLoading) {
            if (button.dataset.psOriginalHtml === undefined) {
                button.dataset.psOriginalHtml = button.innerHTML;
            }

            button.setAttribute("data-loading", "true");
            button.setAttribute("aria-busy", "true");
            button.disabled = true;
            button.innerHTML =
                '<span class="ps-btn__spinner" aria-hidden="true"></span>' +
                '<span>' +
                escape(loadingLabel || button.dataset.loadingLabel || "Working…") +
                "</span>";
            return;
        }

        button.removeAttribute("data-loading");
        button.removeAttribute("aria-busy");
        button.disabled = false;

        if (button.dataset.psOriginalHtml !== undefined) {
            button.innerHTML = button.dataset.psOriginalHtml;
            delete button.dataset.psOriginalHtml;
        }
    }

    /** Read a form into a plain object (trimmed strings). */
    function readForm(form) {
        var data = {};

        qsa("input, select, textarea", form).forEach(function (field) {
            if (!field.name) {
                return;
            }

            data[field.name] = field.type === "checkbox" ? field.checked : field.value.trim();
        });

        return data;
    }

    function setFormValues(form, values) {
        Object.keys(values || {}).forEach(function (key) {
            var field = form.elements[key];
            if (field) {
                field.value = values[key] === null || values[key] === undefined ? "" : values[key];
            }
        });
    }

    function clearFormErrors(form) {
        qsa("[data-field-error]", form).forEach(function (node) {
            node.textContent = "";
        });

        qsa(".ps-field", form).forEach(function (node) {
            node.classList.remove("has-error");
        });

        qsa("input, select, textarea", form).forEach(function (field) {
            field.removeAttribute("aria-invalid");
        });

        var banner = qs("[data-form-error]", form);
        if (banner) {
            banner.innerHTML = "";
        }
    }

    /** Mark a single field invalid with an inline message. */
    function setFieldError(form, fieldName, message) {
        var field = form.elements[fieldName];
        var wrapper = field ? field.closest(".ps-field") : null;
        var errorNode = wrapper ? qs("[data-field-error]", wrapper) : null;

        if (field) {
            field.setAttribute("aria-invalid", "true");
        }

        if (wrapper) {
            wrapper.classList.add("has-error");
        }

        if (errorNode) {
            errorNode.innerHTML = icon("alert", 13) + "<span>" + escape(message) + "</span>";
        }
    }

    /** Clear a single field's inline error. */
    function clearFieldError(form, fieldName) {
        var field = form.elements[fieldName];

        if (!field) {
            return;
        }

        var wrapper = field.closest(".ps-field");
        var errorNode = wrapper ? qs("[data-field-error]", wrapper) : null;

        field.removeAttribute("aria-invalid");

        if (wrapper) {
            wrapper.classList.remove("has-error");
        }

        if (errorNode) {
            errorNode.innerHTML = "";
        }
    }

    function setFormError(form, message) {
        var banner = qs("[data-form-error]", form);

        if (banner) {
            banner.innerHTML = icon("alert", 16) + "<span>" + escape(message) + "</span>";
        }
    }

    /* ---------------------------------------------------------------------
       States
       --------------------------------------------------------------------- */

    function stateHtml(options) {
        var opts = options || {};

        var classes = ["ps-state"];

        if (opts.tone === "error") {
            classes.push("ps-state--error");
        }
        if (opts.compact) {
            classes.push("ps-state--compact");
        }

        var action = "";

        if (opts.actionHref) {
            action =
                '<a class="ps-btn ps-btn--primary ps-btn--sm" href="' +
                escape(opts.actionHref) +
                '">' +
                escape(opts.actionLabel || "Continue") +
                "</a>";
        } else if (opts.actionLabel) {
            action =
                '<button type="button" class="ps-btn ps-btn--primary ps-btn--sm" data-state-action>' +
                escape(opts.actionLabel) +
                "</button>";
        }

        return (
            '<div class="' +
            classes.join(" ") +
            '">' +
            '<div class="ps-state__icon">' +
            icon(opts.icon || (opts.tone === "error" ? "alert" : "inbox"), 24) +
            "</div>" +
            '<div class="ps-state__title">' +
            escape(opts.title || "Nothing here yet") +
            "</div>" +
            (opts.message ? '<div class="ps-state__msg">' + escape(opts.message) + "</div>" : "") +
            action +
            "</div>"
        );
    }

    /** Skeleton placeholders shown while a real request is in flight. */
    function skeletonRows(rows, columns) {
        var count = rows || 4;
        var cols = columns || 5;
        var html = "";

        for (var r = 0; r < count; r++) {
            html += "<tr>";

            for (var c = 0; c < cols; c++) {
                var width = c === 0 ? 70 : c === cols - 1 ? 45 : 80;
                html +=
                    '<td><div class="ps-skel ps-skel--line" style="width:' +
                    width +
                    '%"></div></td>';
            }

            html += "</tr>";
        }

        return html;
    }

    function skeletonCards(count) {
        var html = "";

        for (var i = 0; i < (count || 3); i++) {
            html +=
                '<div class="ps-card" aria-hidden="true">' +
                '<div class="ps-card__body">' +
                '<div class="ps-skel ps-skel--block" style="height:150px;margin-bottom:12px"></div>' +
                '<div class="ps-skel ps-skel--title" style="margin-bottom:8px"></div>' +
                '<div class="ps-skel ps-skel--line" style="width:80%;margin-bottom:6px"></div>' +
                '<div class="ps-skel ps-skel--line" style="width:50%"></div>' +
                "</div>" +
                "</div>";
        }

        return html;
    }

    /** Standard full-region error state with a working retry control. */
    function renderError(container, error, onRetry) {
        var message =
            error && error.message
                ? error.message
                : "We could not load this information just now.";

        container.innerHTML = stateHtml({
            tone: "error",
            icon: "alert",
            title: "Something went wrong",
            message: message,
            actionLabel: onRetry ? "Try again" : null
        });

        if (onRetry) {
            var btn = qs("[data-state-action]", container);
            if (btn) {
                btn.addEventListener("click", onRetry);
            }
        }
    }

    /* ---------------------------------------------------------------------
       Session handling
       --------------------------------------------------------------------- */

    /**
     * React to an expired/invalid session once, for the whole page.
     * Shows a toast and returns the user to the login screen.
     */
    function handleSessionExpiry() {
        if (global.ParadiseSessionHandled) {
            return;
        }

        global.ParadiseSessionHandled = true;

        toast("Your session has ended. Taking you back to the sign-in page…", {
            type: "warning",
            title: "Signed out",
            timeout: 4000
        });

        global.setTimeout(function () {
            global.location.href = API.BASE_PATH + "/login.php?expired=1";
        }, 1800);
    }

    /* ---------------------------------------------------------------------
       Exports
       --------------------------------------------------------------------- */

    global.UI = {
        LOCALE: LOCALE,
        CURRENCY: CURRENCY,
        STATUS_MAP: STATUS_MAP,

        qs: qs,
        qsa: qsa,
        escape: escape,
        debounce: debounce,
        onReady: onReady,
        icon: icon,

        money: money,
        compactMoney: compactMoney,
        rate: rate,
        number: toNumber,
        date: date,
        time: time,
        dateTime: dateTime,
        friendlyDate: friendlyDate,
        relativeTime: relativeTime,
        parseDate: parseDate,
        delta: delta,
        initials: initials,
        truncate: truncate,

        statusMeta: statusMeta,
        statusBadge: statusBadge,

        toast: toast,
        modal: modal,
        confirm: confirmDialog,

        setButtonLoading: setButtonLoading,
        readForm: readForm,
        setFormValues: setFormValues,
        clearFormErrors: clearFormErrors,
        setFieldError: setFieldError,
        clearFieldError: clearFieldError,
        setFormError: setFormError,

        stateHtml: stateHtml,
        skeletonRows: skeletonRows,
        skeletonCards: skeletonCards,
        renderError: renderError,
        handleSessionExpiry: handleSessionExpiry
    };
})(window);