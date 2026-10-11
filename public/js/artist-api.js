/**
 * Paradise Studios — Artist API client.
 *
 * Extends the shared API helper with artist-specific endpoints rather than
 * duplicating request logic. The base request(), error handling, session-expiry
 * behaviour and JSON safety all come from public/js/api.js.
 */
(function (global) {
    "use strict";

    var API = global.API;

    var Artist = {
        /* --- Discovery ------------------------------------------------- */

        /**
         * GET /api/studios.php
         *
         * The endpoint supports exactly these filters (verified against the
         * backend): `location`, `max_price`, `equipment`.
         *
         * @param {{location?: string, max_price?: number|string, equipment?: number[]}} filters
         */
        studios: function (filters, options) {
            var query = {};
            var f = filters || {};

            if (f.location) {
                query.location = f.location;
            }

            if (f.max_price) {
                query.max_price = f.max_price;
            }

            /* studios.php expects a comma separated list of equipment IDs. */
            if (f.equipment && f.equipment.length) {
                query.equipment = f.equipment.join(",");
            }

            return API.get("studios.php", query, options);
        },

        /**
         * GET /api/studio-details.php?id=...
         *
         * `withAvailability` asks for the studio's currently booked slots so the
         * UI can grey out times that are already taken. The backend still
         * validates the booking itself.
         */
        studioDetails: function (studioId, withAvailability, options) {
            var query = { id: studioId };

            if (withAvailability) {
                query.include_availability = 1;
            }

            return API.get("studio-details.php", query, options);
        },

        /** GET /api/equipment.php — catalogue used by the equipment filter. */
        equipmentCatalogue: function (options) {
            return API.get("equipment.php", null, options);
        },

        /* --- Booking --------------------------------------------------- */

        /**
         * POST /api/bookings.php
         *
         * Times must be sent in the exact format the backend validates:
         * "YYYY-MM-DD HH:MM:SS". The backend recalculates the price from the
         * studio's hourly rate and rejects overlapping slots, so nothing
         * price-related is trusted from here.
         */
        createBooking: function (payload, options) {
            return API.post("bookings.php", payload, options);
        },

        /** GET /api/my-bookings.php */
        myBookings: function (options) {
            return API.get("my-bookings.php", null, options);
        },

        /** GET /api/booking-details.php?id=... */
        bookingDetails: function (bookingId, options) {
            return API.get("booking-details.php", { id: bookingId }, options);
        },

        /** PUT /api/cancel-booking.php?id=... */
        cancelBooking: function (bookingId, options) {
            return API.put("cancel-booking.php", null, {
                query: { id: bookingId },
                signal: (options || {}).signal
            });
        },

        /** PUT /api/reschedule-booking.php?id=... */
        rescheduleBooking: function (bookingId, times, options) {
            return API.put("reschedule-booking.php", times, {
                query: { id: bookingId },
                signal: (options || {}).signal
            });
        },

        /** PUT /api/complete-booking.php?id=... */
        completeBooking: function (bookingId, options) {
            return API.put("complete-booking.php", null, {
                query: { id: bookingId },
                signal: (options || {}).signal
            });
        },

        /* --- Payments ------------------------------------------------ */

        /**
         * POST /api/payment-initialize.php
         *
         * Starts a payment and returns the provider's authorization URL.
         *
         * Only the booking id is sent. The amount is NEVER taken from the
         * client: the server re-reads the booking and charges that, so a
         * tampered request cannot change what is owed. CSRF is attached
         * automatically by public/js/api.js.
         */
        initializePayment: function (bookingId, options) {
            return API.post("payment-initialize.php", { booking_id: bookingId }, options);
        },

        /**
         * POST /api/payment-verify.php
         *
         * The ONLY call that can mark a booking paid. It re-asks the payment
         * provider server-to-server and credits nothing unless the provider
         * confirms the amount, currency and reference. Idempotent: calling it
         * repeatedly cannot credit a booking twice.
         *
         * `bookingId` is optional and omitted by default. The endpoint resolves
         * ownership from the payment row itself, so leaving it out removes any
         * chance of a booking-id mismatch producing a false 404.
         */
        verifyPayment: function (reference, bookingId, options) {
            var body = { reference: reference };

            if (bookingId !== undefined && bookingId !== null) {
                body.booking_id = bookingId;
            }

            return API.post("payment-verify.php", body, options);
        },

        /**
         * GET /api/payment-status.php
         *
         * Read-only facts about a booking's payment. GET, so no CSRF token is
         * required. Safe to poll and safe to call on any page load.
         */
        paymentStatus: function (bookingId, options) {
            return API.get("payment-status.php", { booking_id: bookingId }, options);
        },

        /* --- Buzz & reviews ------------------------------------------- */

        /** GET /api/buzz.php */
        buzz: function (options) {
            return API.get("buzz.php", null, options);
        },

        /** POST /api/reviews.php */
        submitReview: function (payload, options) {
            return API.post("reviews.php", payload, options);
        },

        /** GET /api/reviews.php (list the signed-in artist's own reviews) */
        myReviews: function (options) {
            return API.get("reviews.php", null, options);
        },

        /* --- Tracks ---------------------------------------------------- */

        /** GET /api/tracks.php */
        myTracks: function (options) {
            return API.get("tracks.php", null, options);
        },

        /**
         * POST /api/tracks.php — multipart upload.
         *
         * The endpoint expects `booking_id`, `title` and an `audio` file, and
         * enforces its own 20 MB limit and MIME-type checks. Those rules are
         * mirrored in the UI purely as guidance; the server remains
         * authoritative.
         */
        uploadTrack: function (formData, options) {
            var opts = options || {};
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var headers = {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest"
            };

            if (csrfMeta && csrfMeta.content) {
                headers["X-CSRF-Token"] = csrfMeta.content;
            }

            return global.fetch(API.ROOT + "tracks.php", {
                method: "POST",
                credentials: "same-origin",
                headers: headers,
                body: formData,
                signal: opts.signal
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        var payload = null;

                        try {
                            payload = JSON.parse(text);
                        } catch (err) {
                            payload = null;
                        }

                        if (!response.ok || !payload || payload.success === false) {
                            var message =
                                (payload && payload.message) ||
                                (response.status === 413
                                    ? "That file is larger than the server accepts (20 MB maximum)."
                                    : "The upload could not be completed.");

                            var error = new API.ApiError(message, {
                                status: response.status,
                                payload: payload
                            });

                            throw error;
                        }

                        return payload;
                    });
                })
                .catch(function (err) {
                    if (err instanceof API.ApiError) {
                        throw err;
                    }
                    if (err && err.name === "AbortError") {
                        throw err;
                    }
                    throw new API.ApiError(
                        "The upload could not be completed. Please try again.",
                        { status: 0 }
                    );
                });
        },

        /** PUT /api/release-track.php?id=... */
        releaseTrack: function (trackId, options) {
            return API.put("release-track.php", null, {
                query: { id: trackId },
                signal: (options || {}).signal
            });
        },

        /* --- Profile ---------------------------------------------------- */

        /** PUT /api/artist-update-profile.php */
        updateProfile: function (profile, options) {
            return API.put("artist-update-profile.php", profile, options);
        }
    };

    global.ArtistAPI = Artist;

    /*
     * A payment-only view of the same three functions.
     *
     * payment-return.php is a standalone page: it is not inside the artist
     * shell and has no use for the rest of ArtistAPI. Exposing a narrow
     * namespace lets it reuse these wrappers - rather than reimplementing
     * request, CSRF and error handling - without pulling in unrelated artist
     * endpoints.
     *
     * The functions are the SAME objects, not copies, so there is one
     * implementation and no chance of the two drifting apart.
     */
    global.PSPayments = {
        initializePayment: Artist.initializePayment,
        verifyPayment: Artist.verifyPayment,
        paymentStatus: Artist.paymentStatus
    };
})(window);