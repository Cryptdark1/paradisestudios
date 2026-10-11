/**
 * Paradise Studios — theme manager.
 *
 * A single, shared theme implementation for the whole application. There is
 * exactly ONE toggle in the interface (rendered once in the topbar) and it
 * always displays the mode the user will switch TO:
 *
 *   light mode active  -> button shows the moon  (click for dark)
 *   dark mode active   -> button shows the sun   (click for light)
 *
 * The chosen value is persisted in localStorage as "paradise-theme" and
 * re-applied before first paint by a tiny inline script in the page <head>,
 * which prevents a flash of the wrong theme.
 */
(function (global) {
    "use strict";

    var STORAGE_KEY = "paradise-theme";
    var VALID = ["light", "dark"];

    /**
     * Read the stored preference. Falls back to the OS preference.
     * @returns {"light"|"dark"}
     */
    function readStored() {
        try {
            var stored = global.localStorage.getItem(STORAGE_KEY);
            if (VALID.indexOf(stored) !== -1) {
                return stored;
            }
        } catch (err) {
            /* localStorage unavailable (private mode) — fall through */
        }

        return prefersSystem();
    }

    function prefersSystem() {
        if (global.matchMedia) {
            return global.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
        }
        return "light";
    }

    /** @returns {"light"|"dark"} the theme currently applied to the document */
    function getTheme() {
        var current = document.documentElement.getAttribute("data-theme");
        return VALID.indexOf(current) !== -1 ? current : readStored();
    }

    /**
     * Apply a theme to the document and persist it.
     * @param {"light"|"dark"} theme
     * @param {{persist?: boolean, animate?: boolean}} [options]
     */
    function setTheme(theme, options) {
        if (VALID.indexOf(theme) === -1) {
            theme = "light";
        }

        var opts = options || {};
        var root = document.documentElement;

        if (opts.animate !== false) {
            root.setAttribute("data-theme-transition", "");
            global.setTimeout(function () {
                root.removeAttribute("data-theme-transition");
            }, 260);
        }

        root.setAttribute("data-theme", theme);

        if (opts.persist !== false) {
            try {
                global.localStorage.setItem(STORAGE_KEY, theme);
            } catch (err) {
                /* Persisting is best-effort only */
            }
        }

        syncToggles(theme);
        notify(theme);

        return theme;
    }

    /** Flip between light and dark. */
    function toggle() {
        return setTheme(getTheme() === "dark" ? "light" : "dark");
    }

    /**
     * Keep every theme control (there is one) showing the correct glyph and
     * accessible label for the mode it switches to.
     * @param {"light"|"dark"} current
     */
    function syncToggles(current) {
        var next = current === "dark" ? "light" : "dark";
        var glyph = next === "dark" ? "\u{1F319}" : "\u{2600}\u{FE0F}";
        var label = next === "dark" ? "Switch to dark mode" : "Switch to light mode";

        var toggles = document.querySelectorAll("[data-theme-toggle]");
        for (var i = 0; i < toggles.length; i++) {
            var btn = toggles[i];
            btn.textContent = glyph;
            btn.setAttribute("aria-label", label);
            btn.setAttribute("title", label);
            btn.setAttribute("data-next-theme", next);
        }
    }

    /* Notify the page that the theme changed so charts can re-render with the
       correct colours instead of the ones captured at first paint. */
    function notify(theme) {
        global.dispatchEvent(new CustomEvent("paradise:themechange", { detail: { theme: theme } }));
    }

    /* Follow the OS setting while the user has not made an explicit choice. */
    function watchSystem() {
        if (!global.matchMedia) {
            return;
        }

        var query = global.matchMedia("(prefers-color-scheme: dark)");

        var handler = function (event) {
            var stored = null;
            try {
                stored = global.localStorage.getItem(STORAGE_KEY);
            } catch (err) {
                /* ignore */
            }

            if (stored !== "light" && stored !== "dark") {
                setTheme(event.matches ? "dark" : "light", { persist: false });
            }
        };

        if (typeof query.addEventListener === "function") {
            query.addEventListener("change", handler);
        } else if (typeof query.addListener === "function") {
            query.addListener(handler);
        }
    }

    function init() {
        /* Re-apply the stored theme in case the inline head script ran before
           the DOM existed, then wire the control. */
        var stored = readStored();
        if (document.documentElement.getAttribute("data-theme") !== stored) {
            setTheme(stored, { animate: false, persist: false });
        } else {
            syncToggles(stored);
        }

        document.addEventListener("click", function (event) {
            var toggleBtn = event.target.closest("[data-theme-toggle]");
            if (!toggleBtn) {
                return;
            }
            event.preventDefault();
            toggle();
        });

        watchSystem();
    }

    global.ParadiseTheme = {
        STORAGE_KEY: STORAGE_KEY,
        get: getTheme,
        set: setTheme,
        toggle: toggle,
        syncToggles: syncToggles,
        init: init
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})(window);