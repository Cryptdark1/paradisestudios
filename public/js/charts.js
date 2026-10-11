/**
 * Paradise Studios — Chart.js helpers.
 *
 * Centralises everything that is common to every chart in the project:
 *   - reading the active theme colours from the CSS custom properties, so
 *     charts re-colour correctly when the user switches light/dark
 *   - shared scale/legend/tooltip defaults that match the design
 *   - correct destroy + resize handling (no leaked canvases or listeners)
 *
 * Pages register a render callback; it is re-run when the theme changes.
 */
(function (global) {
    "use strict";

    var instances = [];
    var renderers = [];

    /** Read a CSS custom property from :root. */
    function token(name, fallback) {
        var value = global
            .getComputedStyle(document.documentElement)
            .getPropertyValue(name);

        value = (value || "").trim();
        return value || fallback || "";
    }

    /** Current palette, resolved from the active theme. */
    function tokens() {
        var text = token("--ps-text", "#191f2e");
        var muted = token("--ps-text-muted", "#616b80");
        var soft = token("--ps-text-soft", "#8a93a6");
        var grid = token("--ps-chart-grid", "rgba(25,31,46,.08)");
        var surface = token("--ps-surface", "#fff");
        var amber = token("--ps-amber", "#d99a2b");
        var indigo = token("--ps-indigo-soft", "#3d4294");

        return {
            text: text,
            muted: muted,
            soft: soft,
            grid: grid,
            surface: surface,
            amber: amber,
            indigo: indigo,
            track: token("--ps-chart-bar-track", "#eef1f6"),
            status: {
                pending: token("--ps-warning", "#a76a08"),
                confirmed: token("--ps-info", "#2357a8"),
                escrow_held: token("--ps-violet", "#6c3bb5"),
                in_session: token("--ps-teal", "#0a6f74"),
                completed: token("--ps-success", "#12784a"),
                cancelled: token("--ps-soft", "#8a93a6"),
                disputed: token("--ps-danger", "#c0342b")
            }
        };
    }

    /** Translucent variant of a hex colour, for gradient fills. */
    function alpha(hex, value) {
        var clean = String(hex || "").trim();

        if (clean.charAt(0) === "#") {
            clean = clean.length === 4
                ? "#" + clean[1] + clean[1] + clean[2] + clean[2] + clean[3] + clean[3]
                : clean;
        }

        var match = clean.match(/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i);

        if (!match) {
            return clean;
        }

        return (
            "rgba(" +
            parseInt(match[1], 16) +
            "," +
            parseInt(match[2], 16) +
            "," +
            parseInt(match[3], 16) +
            "," +
            value +
            ")"
        );
    }

    /**
     * Shared scale styling. Every axis in the project uses this so charts look
     * like one family rather than five unrelated graphs.
     */
    function axis(extra, color, vertical) {
        var base = {
            grid: {
                display: extra && extra.grid !== false,
                color: color.grid,
                drawBorder: false,
                drawTicks: false,
                lineWidth: 1
            },
            border: { display: false },
            ticks: {
                color: color.soft,
                padding: 8,
                font: {
                    family: "Inter, Segoe UI, system-ui, sans-serif",
                    size: 11
                },
                maxRotation: 0,
                autoSkipPadding: 12
            }
        };

        if (vertical) {
            base.beginAtZero = true;
        }

        if (extra) {
            Object.keys(extra).forEach(function (key) {
                if (key === "grid" || key === "border" || key === "ticks") {
                    Object.assign(base[key], extra[key]);
                } else {
                    base[key] = extra[key];
                }
            });
        }

        return base;
    }

    function tooltip(color, formatter) {
        return {
            backgroundColor: color.text,
            titleColor: color.surface,
            bodyColor: color.surface,
            borderColor: "transparent",
            borderWidth: 0,
            padding: 11,
            cornerRadius: 10,
            displayColors: false,
            titleFont: { family: "Inter, Segoe UI, system-ui, sans-serif", size: 12, weight: "600" },
            bodyFont: { family: "Inter, Segoe UI, system-ui, sans-serif", size: 12.5 },
            callbacks: formatter || {}
        };
    }

    function legend(color) {
        return {
            display: false,
            labels: {
                color: color.muted,
                usePointStyle: true,
                pointStyle: "circle",
                boxWidth: 7,
                boxHeight: 7,
                padding: 16,
                font: { family: "Inter, Segoe UI, system-ui, sans-serif", size: 11.5, weight: "500" }
            }
        };
    }

    /**
     * Create a chart, registering it for automatic teardown and theme refresh.
     *
     * @param {HTMLCanvasElement|string} target
     * @param {object} config
     * @returns {Chart|null}
     */
    function create(target, config) {
        if (!global.Chart) {
            return null;
        }

        var canvas = typeof target === "string" ? document.getElementById(target) : target;

        if (!canvas) {
            return null;
        }

        destroy(canvas);

        var chart = new global.Chart(canvas.getContext("2d"), config);
        instances.push(chart);
        return chart;
    }

    /** Destroy the chart bound to a canvas (idempotent). */
    function destroy(target) {
        var canvas = typeof target === "string" ? document.getElementById(target) : target;

        if (!canvas) {
            return;
        }

        instances = instances.filter(function (chart) {
            if (chart.canvas === canvas || chart.canvas === target) {
                chart.destroy();
                return false;
            }
            return true;
        });
    }

    /** Destroy every chart created through this helper. */
    function destroyAll() {
        instances.forEach(function (chart) {
            chart.destroy();
        });
        instances = [];
    }

    /**
     * Register a callback to re-render charts when the theme changes.
     * @param {Function} render
     * @returns {Function} unsubscribe
     */
    function onThemeChange(render) {
        renderers.push(render);

        return function off() {
            renderers = renderers.filter(function (fn) {
                return fn !== render;
            });
        };
    }

    global.addEventListener("paradise:themechange", function () {
        /* Let the new CSS variables settle before reading them. */
        global.requestAnimationFrame(function () {
            renderers.forEach(function (render) {
                try {
                    render();
                } catch (err) {
                    /* A single failing chart must not break the others. */
                    if (global.console) {
                        global.console.warn("Chart re-render failed:", err);
                    }
                }
            });
        });
    });

    /* Chart.js is loaded with `defer`; these files may execute before it in
       rare cases, so keep responsive behaviour self-contained. */
    global.ParadiseCharts = {
        tokens: tokens,
        token: token,
        alpha: alpha,
        axis: axis,
        tooltip: tooltip,
        legend: legend,
        create: create,
        destroy: destroy,
        destroyAll: destroyAll,
        onThemeChange: onThemeChange
    };
})(window);