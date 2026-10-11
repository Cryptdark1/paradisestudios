/**
 * Paradise Studios — API client.
 *
 * Every request the owner frontend makes to the existing PHP backend goes
 * through this module, so loading states, error handling, session expiry and
 * safe JSON parsing are implemented exactly once.
 *
 * Design rules:
 *   - Never fabricate data. Failures surface as errors, never as empty success.
 *   - The backend stays authoritative; no owner/role info is sent by the client.
 *   - Session cookies ride along automatically (same-origin).
 */
(function (global) {
    "use strict";

    /* ---------------------------------------------------------------------
       Base URL resolution
       --------------------------------------------------------------------- */

    var BASE_PATH = (function () {
        var script = document.currentScript;

        if (script && script.src) {
            /* .../paradisestudios/public/js/api.js -> .../paradisestudios/ */
            var marker = script.src.split("/public/")[0];
            if (marker) {
                return marker.replace(/\/+$/, "");
            }
        }

        /* Fallback matches the documented local install path. */
        var path = global.location.pathname;
        var idx = path.indexOf("/public/");
        if (idx !== -1) {
            return path.slice(0, idx);
        }

        return "/paradisestudios";
    })();

    var API_ROOT = BASE_PATH + "/api/";

    var DEFAULT_TIMEOUT = 20000;

    /* ---------------------------------------------------------------------
       Errors
       --------------------------------------------------------------------- */

    /**
     * Error thrown for any failed API interaction.
     * @param {string} message Human readable, safe to show to the user.
     * @param {{status?: number, payload?: object|null, cause?: string}} [info]
     */
    function ApiError(message, info) {
        var details = info || {};

        this.name = "ApiError";
        this.message = message || "Something went wrong. Please try again.";
        this.status = details.status || 0;
        this.payload = details.payload || null;
        this.cause = details.cause || "";

        /* 401/403 from the auth layer means the session can no longer be
           trusted; the shell listens for the event below. */
        this.isAuthError = this.status === 401 || this.status === 403;
        this.isNetworkError = !this.status;

        if (Error.captureStackTrace) {
            Error.captureStackTrace(this, ApiError);
        }
    }

    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.constructor = ApiError;

    /* ---------------------------------------------------------------------
       Internals
       --------------------------------------------------------------------- */

    function buildUrl(endpoint, query) {
        var url = API_ROOT + String(endpoint).replace(/^\/+/, "");

        if (!query) {
            return url;
        }

        var parts = [];

        Object.keys(query).forEach(function (key) {
            var value = query[key];

            if (value === undefined || value === null || value === "") {
                return;
            }

            parts.push(encodeURIComponent(key) + "=" + encodeURIComponent(value));
        });

        return parts.length ? url + "?" + parts.join("&") : url;
    }

    /**
     * Parse a response body as JSON without ever throwing.
     * Returns { ok, data, isJson }.
     */
    function parseJson(text) {
        if (typeof text !== "string" || text.trim() === "") {
            return { ok: false, data: null, isJson: false };
        }

        try {
            return { ok: true, data: JSON.parse(text), isJson: true };
        } catch (err) {
            return { ok: false, data: null, isJson: false };
        }
    }

    /**
     * Turn an HTTP status + payload into a message worth showing.
     * include/auth.php answers some failures with plain text (e.g.
     * "Access denied."), which must not surface as a JSON error.
     */
    function messageFor(status, payload, endpoint) {
        if (payload && typeof payload.message === "string" && payload.message.trim() !== "") {
            return payload.message;
        }

        switch (status) {
            case 400:
                return "The request was rejected. Please check the values you entered.";
            case 401:
                return "Your session has expired. Please sign in again.";
            case 403:
                return "You do not have permission to perform this action.";
            case 404:
                return "The requested item could not be found.";
            case 405:
                return "That action is not supported by the server.";
            case 409:
                return "That change conflicts with the current data. Please refresh and retry.";
            case 500:
                return "The server could not complete the request. Please try again.";
            default:
                return status >= 500
                    ? "The server is unavailable right now. Please try again shortly."
                    : "The request could not be completed.";
        }
    }

    function emitSessionExpired(endpoint) {
        global.dispatchEvent(
            new CustomEvent("paradise:session-expired", {
                detail: { endpoint: endpoint }
            })
        );
    }

    /* ---------------------------------------------------------------------
       Public API
       --------------------------------------------------------------------- */

    /**
     * Perform an API request.
     *
     * @param {string} endpoint  File name inside /api/, e.g. "owner-studios.php"
     * @param {object} [options]
     * @param {"GET"|"POST"|"PUT"|"DELETE"} [options.method]
     * @param {object} [options.query]
     * @param {object} [options.body]      Sent as JSON.
     * @param {AbortSignal} [options.signal]
     * @param {number} [options.timeout]
     * @returns {Promise<object>} the parsed `data` payload
     */
    function request(endpoint, options) {
        var opts = options || {};
        var method = (opts.method || "GET").toUpperCase();
        var url = buildUrl(endpoint, opts.query);

        var controller = null;
        var timer = null;
        var externalSignal = opts.signal || null;
        var timedOut = false;

        if (global.AbortController) {
            controller = new AbortController();

            if (externalSignal) {
                if (externalSignal.aborted) {
                    controller.abort();
                } else {
                    externalSignal.addEventListener("abort", function () {
                        controller.abort();
                    });
                }
            }

            var timeout = opts.timeout || DEFAULT_TIMEOUT;
            timer = global.setTimeout(function () {
                timedOut = true;
                controller.abort();
            }, timeout);
        }

        var init = {
            method: method,
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            cache: "no-store",
            signal: controller ? controller.signal : undefined
        };

        if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta && csrfMeta.content) {
                init.headers["X-CSRF-Token"] = csrfMeta.content;
            }
        }

        if (opts.body !== undefined && opts.body !== null) {
            init.headers["Content-Type"] = "application/json";
            init.body = JSON.stringify(opts.body);
        }

        return global
            .fetch(url, init)
            .then(function (response) {
                return response.text().then(function (text) {
                    var parsed = parseJson(text);
                    var payload = parsed.ok ? parsed.data : null;

                    if (!response.ok) {
                        var message = messageFor(response.status, payload, endpoint);

                        if (response.status === 401) {
                            emitSessionExpired(endpoint);
                        }

                        throw new ApiError(message, {
                            status: response.status,
                            payload: payload,
                            cause: parsed.ok ? "" : text.slice(0, 300)
                        });
                    }

                    /* A 2xx response must still be usable JSON. */
                    if (!parsed.ok) {
                        throw new ApiError(
                            "The server returned an unexpected response. Please try again.",
                            { status: response.status, payload: null, cause: text.slice(0, 300) }
                        );
                    }

                    /* Endpoints in this project answer with { success, ... }. */
                    if (payload && payload.success === false) {
                        throw new ApiError(messageFor(response.status, payload, endpoint), {
                            status: response.status,
                            payload: payload
                        });
                    }

                    return payload;
                });
            })
            .catch(function (err) {
                if (err instanceof ApiError) {
                    throw err;
                }

                if (err && err.name === "AbortError") {
                    if (timedOut) {
                        throw new ApiError("The request timed out. Please check your connection and retry.", {
                            status: 0,
                            cause: "timeout"
                        });
                    }
                    /* Caller-driven cancellation — pass it straight through. */
                    throw err;
                }

                throw new ApiError(
                    "Could not reach the server. Please check your connection and try again.",
                    { status: 0, cause: String(err && err.message ? err.message : err) }
                );
            })
            .then(
                function (data) {
                    if (timer) {
                        global.clearTimeout(timer);
                    }
                    return data;
                },
                function (err) {
                    if (timer) {
                        global.clearTimeout(timer);
                    }
                    throw err;
                }
            );
    }

    /* Convenience wrappers ------------------------------------------------ */

    function get(endpoint, query, options) {
        return request(endpoint, Object.assign({ method: "GET", query: query }, options || {}));
    }

    function post(endpoint, body, options) {
        return request(endpoint, Object.assign({ method: "POST", body: body }, options || {}));
    }

    function put(endpoint, body, options) {
        return request(endpoint, Object.assign({ method: "PUT", body: body }, options || {}));
    }

    /* Owner-specific endpoints, named for readability at the call site. */
    var Owner = {
        /** GET /api/owner-studios.php */
        studios: function (options) {
            return get("owner-studios.php", null, options);
        },
        /** POST /api/owner-create-studio.php */
        createStudio: function (studio, options) {
            return post("owner-create-studio.php", studio, options);
        },
        /** PUT /api/owner-update-studio.php?id={studio_id} */
        updateStudio: function (studioId, studio, options) {
            return put("owner-update-studio.php", studio, { query: { id: studioId }, signal: (options || {}).signal });
        },
        /** PUT /api/owner-studio-equipment.php?id={studio_id} */
        setStudioEquipment: function (studioId, equipmentIds, options) {
            return put(
                "owner-studio-equipment.php",
                { equipment_ids: equipmentIds },
                { query: { id: studioId }, signal: (options || {}).signal }
            );
        },
        /** GET /api/owner-equipment.php (+ optional studio_id / studio_ids) */
        equipment: function (query, options) {
            return get("owner-equipment.php", query, options);
        },
        /** GET /api/owner-bookings.php */
        bookings: function (options) {
            return get("owner-bookings.php", null, options);
        },
        /** GET /api/owner-analytics.php */
        analytics: function (options) {
            return get("owner-analytics.php", null, options);
        },
        /** GET/POST /api/owner-payouts.php */
        payouts: function (action, body, options) {
            if (body) {
                return post("owner-payouts.php", Object.assign({ action: action }, body), options);
            }
            return get("owner-payouts.php", action ? { action: action } : null, options);
        },
        /** PUT /api/owner-update-profile.php */
        updateProfile: function (profile, options) {
            return put("owner-update-profile.php", profile, options);
        }
    };

    global.API = {
        BASE_PATH: BASE_PATH,
        ROOT: API_ROOT,
        ApiError: ApiError,
        request: request,
        get: get,
        post: post,
        put: put,
        owner: Owner
    };
})(window);