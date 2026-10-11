/**
 * Paradise Studios - email verification screen.
 *
 * Drives /api/email-verify.php and /api/email-verification-resend.php through
 * the shared API client in public/js/api.js, which supplies the CSRF header,
 * same-origin credentials, timeouts and session-expiry handling.
 *
 * THE ONE RULE
 * ------------
 * This file never decides that an account is verified. It renders success only
 * when the server says `data.verified === true`. A redirect to the page, a
 * success-looking URL, or the artist typing six plausible digits changes nothing.
 */

(function (global) {
    "use strict";

    var API = global.API;

    var form = document.querySelector("[data-verify-form]");
    var root = document.querySelector("[data-verify-root]");
    var otp = document.querySelector("[data-otp]");
    var submit = document.querySelector("[data-verify-submit]");
    var alertSlot = document.querySelector("[data-verify-alert]");
    var resend = document.querySelector("[data-resend]");
    var resendText = document.querySelector("[data-resend-text]");

    /* Without a form there is nothing to wire (the "enter your email" state). */
    if (!form || !otp || !root) {
        return;
    }

    var email = (document.querySelector("[data-verify-email]") || {}).textContent || "";
    email = email.trim();

    var boxes = Array.prototype.slice.call(otp.querySelectorAll("[data-otp-box]"));
    var length = boxes.length || 6;

    var cooldownTimer = null;
    var busy = false;

    /* ===================================================================
       Box behaviour
       =================================================================== */

    function indexOfFocusedBox() {
        for (var i = 0; i < boxes.length; i++) {
            if (document.activeElement === boxes[i]) {
                return i;
            }
        }

        return -1;
    }

    function markActiveBox() {
        for (var i = 0; i < boxes.length; i++) {
            var filled = boxes[i].value !== "";
            var focused = document.activeElement === boxes[i];

            boxes[i].classList.toggle("is-active", filled && !focused ? false : focused);

            /*
             * A box holding a digit is never "active" - the caret belongs in
             * the next empty one, or the last box when all are filled.
             */
            if (focused && filled) {
                boxes[i].classList.remove("is-active");
            }
        }

        if (indexOfFocusedBox() === -1) {
            var next = boxes.filter(function (box) { return box.value === ""; })[0];

            if (next) {
                next.classList.add("is-active");
            }
        }
    }

    function focusBox(index) {
        var target = boxes[Math.max(0, Math.min(length - 1, index))];

        if (target) {
            target.focus();
            target.select();
        }

        markActiveBox();
    }

    function clearBoxes() {
        boxes.forEach(function (box) { box.value = ""; });

        otp.classList.remove("is-invalid");
        otp.classList.remove("is-shaking");

        focusBox(0);
    }

    function readCode() {
        return boxes
            .map(function (box) { return box.value; })
            .join("")
            .replace(/\D/g, "");
    }

    boxes.forEach(function (box, index) {
        box.addEventListener("input", function (event) {
            /* A pasted code lands several digits in one box. */
            var value = box.value.replace(/\D/g, "");

            if (value.length > 1) {
                distribute(value, index);
                return;
            }

            box.value = value.slice(-1);

            otp.classList.remove("is-invalid");

            if (box.value && index < length - 1) {
                focusBox(index + 1);
            } else {
                markActiveBox();
            }

            maybeSubmitWhenComplete();
        });

        box.addEventListener("keydown", function (event) {
            if (event.key === "Backspace") {
                event.preventDefault();

                if (box.value !== "") {
                    box.value = "";
                    markActiveBox();
                    return;
                }

                focusBox(index - 1);
                return;
            }

            if (event.key === "ArrowLeft") {
                event.preventDefault();
                focusBox(index - 1);
            } else if (event.key === "ArrowRight") {
                event.preventDefault();
                focusBox(index + 1);
            }
        });

        box.addEventListener("focus", function () { box.select(); });

        box.addEventListener("paste", function (event) {
            event.preventDefault();

            var text = (event.clipboardData || global.clipboardData).getData("text") || "";
            distribute(text.replace(/\D/g, ""), index);
        });
    });

    /** Put digits into the boxes starting at `from`, one per box. */
    function distribute(digits, from) {
        if (!digits) {
            return;
        }

        var start = typeof from === "number" ? from : 0;

        for (var i = 0; i < digits.length && start + i < length; i++) {
            boxes[start + i].value = digits.charAt(i);
        }

        otp.classList.remove("is-invalid");

        var next = start + digits.length;

        focusBox(next < length ? next : length - 1);
        maybeSubmitWhenComplete();
    }

    /* ===================================================================
       Alerts
       =================================================================== */

    function showAlert(message, tone) {
        if (!alertSlot) {
            return;
        }

        if (!message) {
            alertSlot.innerHTML = "";
            return;
        }

        var kind = tone === "error" ? "error" : "info";
        var glyph = kind === "error"
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>';

        alertSlot.innerHTML =
            '<div class="ps-auth__alert ps-auth__alert--' + kind + '" style="margin-bottom:18px;text-align:left">'
            + glyph
            + "<span>" + escapeHtml(message) + "</span>"
            + "</div>";
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    /* ===================================================================
       Submitting
       =================================================================== */

    function setBusy(state, label) {
        busy = state;

        if (!submit) {
            return;
        }

        submit.disabled = state;
        submit.classList.toggle("is-busy", state);
        submit.textContent = state
            ? (label || submit.getAttribute("data-loading-label") || "Verifying…")
            : (submit.getAttribute("data-idle-label") || "Verify email");
    }

    /* Once six digits are present there is nothing else to decide, so submit. */
    function maybeSubmitWhenComplete() {
        if (readCode().length === length && !busy) {
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit", { cancelable: true }));
        }
    }

    form.addEventListener("submit", function (event) {
        event.preventDefault();

        if (busy) {
            return;
        }

        var code = readCode();

        if (code.length !== length) {
            otp.classList.add("is-invalid", "is-shaking");
            showAlert("Enter all " + length + " digits of your verification code.", "error");
            return;
        }

        setBusy(true);
        showAlert("");

        API.request("email-verify.php", {
            method: "POST",
            body: { email: email, code: code }
        })
            .then(function (payload) {
                /* The ONLY path to the success state. */
                if (payload && payload.data && payload.data.verified === true) {
                    renderSuccess();
                    return;
                }

                otp.classList.add("is-invalid", "is-shaking");
                showAlert("That code was not accepted. Please try again.", "error");
                clearBoxes();
            })
            .catch(function (error) {
                handleError(error);
            })
            .then(function () {
                setBusy(false);
            });
    });

    function handleError(error) {
        var status = error && error.status;
        var payload = (error && error.payload) || {};
        var data = payload.data || {};

        if (status === 410 || data.reason === "expired_code") {
            otp.classList.add("is-invalid", "is-shaking");
            showAlert("That code has expired. Request a new one below.", "error");
            clearBoxes();
            return;
        }

        if (status === 429) {
            var wait = Number(data.retry_after_seconds || 0);
            showAlert(
                wait > 0
                    ? "Too many attempts. Try again in " + wait + " seconds."
                    : "Too many attempts. Please wait before trying again.",
                "error"
            );
            if (wait > 0) {
                startCooldown(wait);
            }
            clearBoxes();
            return;
        }

        if (status === 403) {
            showAlert("This form has expired. Please reload the page.", "error");
            return;
        }

        if (status === 0) {
            showAlert("We could not reach the server. Check your connection.", "error");
            return;
        }

        /*
         * 400 here is the deliberately generic "not valid" from the server,
         * used identically for a wrong code and an unknown address. Passing it
         * through unchanged keeps the two indistinguishable to a prober.
         */
        showAlert(
            (error && error.message) || "Something went wrong. Please try again.",
            "error"
        );

        otp.classList.add("is-invalid", "is-shaking");
        clearBoxes();
    }

    /* ===================================================================
       Success
       =================================================================== */

    function renderSuccess() {
        otp.classList.add("is-accepted");

        var title = document.querySelector("[data-verify-title]");
        var lead = document.querySelector("[data-verify-lead]");

        if (title) { title.textContent = "Email verified successfully!"; }
        if (lead) { lead.textContent = "You're all set. Continue to login to book your session."; }

        root.innerHTML =
            '<div class="ps-verify-medallion" aria-hidden="true">'
            + '<div class="ps-verify-medallion__ring"></div>'
            + '<div class="ps-verify-medallion__ring"></div>'
            + '<div class="ps-verify-medallion__ring"></div>'
            + '<svg class="ps-verify-medallion__tick" viewBox="0 0 24 24" fill="none" '
            + 'stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="m4.5 12.5 5 5 10-11"/></svg>'
            + "</div>"
            + '<h1 class="ps-auth__title">Email verified successfully!</h1>'
            + '<p class="ps-auth__lead">You&rsquo;re all set. Continue to login to book your session.</p>'
            + '<a class="ps-btn ps-btn--primary ps-btn--lg ps-btn--block" '
            + 'href="' + basePath("/login.php") + '">Continue to login</a>';
    }

    function basePath(path) {
        return (API.BASE_PATH || "") + path;
    }

    /* ===================================================================
       Resend
       =================================================================== */

    function formatCountdown(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;

        return (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
    }

    function startCooldown(seconds) {
        if (!resend) {
            return;
        }

        var remaining = Math.max(0, Number(seconds) || 0);

        if (cooldownTimer) {
            global.clearInterval(cooldownTimer);
            cooldownTimer = null;
        }

        function paint() {
            resend.classList.toggle("is-waiting", remaining > 0);
            resend.classList.toggle("is-disabled", remaining > 0);

            if (remaining > 0) {
                resendText.innerHTML = 'Resend code in <span class="ps-verify-foot__count">'
                    + formatCountdown(remaining) + "</span>";
            } else {
                resendText.textContent = "Resend code";
            }

            if (remaining <= 0 && cooldownTimer) {
                global.clearInterval(cooldownTimer);
                cooldownTimer = null;
            }
        }

        paint();

        if (remaining <= 0) {
            return;
        }

        cooldownTimer = global.setInterval(function () {
            remaining -= 1;
            paint();
        }, 1000);
    }

    if (resend) {
        resend.addEventListener("click", function () {
            if (resend.classList.contains("is-waiting") || busy) {
                return;
            }

            busy = true;
            resend.classList.add("is-waiting");
            resendText.textContent = "Sending…";

            API.request("email-verification-resend.php", {
                method: "POST",
                body: { email: email }
            })
                .then(function (payload) {
                    /*
                     * The endpoint answers identically whether or not the
                     * address exists, so there is nothing to reveal here and
                     * nothing to branch on beyond the cooldown.
                     */
                    var data = (payload && payload.data) || {};

                    showAlert(
                        "If that address still needs verifying, a new code is on its way.",
                        "info"
                    );

                    startCooldown(Number(data.retry_after_seconds || 60));
                })
                .catch(function (error) {
                    var status = error && error.status;

                    if (status === 503) {
                        showAlert(
                            "Email is not available on this server yet, so a new code could not be sent.",
                            "error"
                        );
                    } else if (status === 429) {
                        var wait = Number(
                            ((error.payload || {}).data || {}).retry_after_seconds || 0
                        );
                        showAlert("Too many requests. Please wait before trying again.", "error");

                        if (wait > 0) {
                            startCooldown(wait);
                        }
                    } else {
                        showAlert(
                            "We could not send a new code just now. Please try again.",
                            "error"
                        );
                    }
                })
                .then(function () {
                    busy = false;

                    if (!cooldownTimer) {
                        resend.classList.remove("is-waiting");
                        resendText.textContent = "Resend code";
                    }
                });
        });
    }

    /* ===================================================================
       Start
       =================================================================== */

    var initialCooldown = resend ? Number(resend.getAttribute("data-cooldown") || 0) : 0;

    if (initialCooldown > 0) {
        startCooldown(initialCooldown);
    }

    markActiveBox();

    /* Only autofocus on a real device: stealing focus on load hurts a keyboard
       or screen-reader user more than it helps. */
    if (window.matchMedia && window.matchMedia("(min-width: 768px)").matches) {
        focusBox(0);
    }
})(window);