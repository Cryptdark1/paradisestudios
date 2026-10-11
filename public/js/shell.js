/**
 * Paradise Studios — page shell behaviour.
 *
 * Loaded on every owner page. Wires the navigation drawer, the user menu and
 * the global session-expiry handling. Keeping this in one place means the
 * sidebar, mobile navigation and profile menu behave identically on all five
 * owner pages.
 */
(function (global) {
    "use strict";

    var UI = global.UI;

    function initDrawer() {
        var shell = document.querySelector("[data-shell]");
        var burger = document.querySelector("[data-nav-toggle]");
        var scrim = document.querySelector("[data-scrim]");
        var sidebar = document.querySelector("[data-sidebar]");

        if (!shell || !sidebar) {
            return;
        }

        function isOpen() {
            return shell.classList.contains("is-nav-open");
        }

        function open() {
            shell.classList.add("is-nav-open");

            if (scrim) {
                scrim.classList.add("is-open");
                scrim.setAttribute("aria-hidden", "false");
            }

            if (burger) {
                burger.setAttribute("aria-expanded", "true");
                burger.setAttribute("aria-label", "Close navigation menu");
            }

            sidebar.removeAttribute("inert");
            document.body.classList.add("ps-nav-open");
        }

        function close() {
            shell.classList.remove("is-nav-open");

            if (scrim) {
                scrim.classList.remove("is-open");
                scrim.setAttribute("aria-hidden", "true");
            }

            if (burger) {
                burger.setAttribute("aria-expanded", "false");
                burger.setAttribute("aria-label", "Open navigation menu");
            }

            document.body.classList.remove("ps-nav-open");
        }

        function toggle() {
            if (isOpen()) {
                close();
            } else {
                open();
            }
        }

        if (burger) {
            burger.setAttribute("aria-expanded", "false");
            burger.addEventListener("click", function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggle();
            });
        }

        if (scrim) {
            scrim.addEventListener("click", close);
        }

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && isOpen()) {
                close();
            }
        });

        /* Following a link inside the drawer should close it, otherwise the
           next page loads with the overlay still covering the content. */
        sidebar.addEventListener("click", function (event) {
            var link = event.target.closest("a");

            if (link && global.innerWidth <= 1023) {
                close();
            }
        });

        /* Collapse automatically when the viewport grows past the breakpoint
           so the desktop layout is never left with a stale overlay. */
        var desktop = global.matchMedia("(min-width: 1024px)");
        var onChange = function (event) {
            if (event.matches) {
                close();
            }
        };

        if (desktop.addEventListener) {
            desktop.addEventListener("change", onChange);
        } else if (desktop.addListener) {
            desktop.addListener(onChange);
        }

        close();
    }

    function initUserMenu() {
        var root = document.querySelector("[data-usermenu]");

        if (!root) {
            return;
        }

        var trigger = root.querySelector("[data-usermenu-trigger]");
        var menu = root.querySelector("[data-usermenu-menu]");

        if (!trigger || !menu) {
            return;
        }

        function isOpen() {
            return root.classList.contains("is-open");
        }

        function open() {
            root.classList.add("is-open");
            trigger.setAttribute("aria-expanded", "true");
            menu.removeAttribute("hidden");
        }

        function close() {
            root.classList.remove("is-open");
            trigger.setAttribute("aria-expanded", "false");
            menu.setAttribute("hidden", "");
        }

        function toggle() {
            if (isOpen()) {
                close();
            } else {
                open();
            }
        }

        trigger.setAttribute("aria-expanded", "false");
        trigger.setAttribute("aria-haspopup", "true");
        menu.setAttribute("hidden", "");
        close();

        trigger.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            toggle();
        });

        document.addEventListener("click", function (event) {
            if (isOpen() && !root.contains(event.target)) {
                close();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && isOpen()) {
                close();
                trigger.focus();
            }
        });
    }

    function initSessionGuard() {
        global.addEventListener("paradise:session-expired", function () {
            UI.handleSessionExpiry();
        });
    }

    function init() {
        initDrawer();
        initUserMenu();
        initSessionGuard();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})(window);