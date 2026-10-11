<?php

/**
 * Paradise Studios — Studio details and booking (artist).
 *
 * Reads GET /api/studio-details.php?id=... and creates bookings through
 * POST /api/bookings.php.
 *
 * Important rules honoured here:
 *   - the price shown live in the browser is a *preview*; the backend
 *     recalculates the total from the studio's hourly rate and returns it,
 *     and the confirmation step always displays the server's figure
 *   - availability is re-validated by the backend, which returns 409 for an
 *     overlapping slot
 *   - the submit button is disabled while a request is in flight so a
 *     double-click cannot create two bookings
 */

require_once __DIR__ . '/../../includes/artist-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$artistUser = ps_current_user();

/* The studio ID arrives as a query parameter and is validated server-side. */
$studioId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$artistPage   = 'studios';
$pageHeading  = 'Studio details';
$pageStyles   = ['artist-studio-details.css'];
$pageScripts  = ['js/artist-ui.js', 'js/artist-studio-details.js'];

require __DIR__ . '/../../templates/artist-shell-start.php';

?>

<?php if (!$studioId): ?>

    <section class="ps-card">
        <div class="ps-card__body">
            <?= UI_state_html('No studio was selected.', 'Go back to the marketplace and choose a studio to see its details and book a session.') ?>
        </div>
    </section>

    <div class="ps-card__body" style="display:flex;justify-content:center;padding-top:4px;">
        <a class="ps-btn ps-btn--primary" href="<?= e(PS_BASE_URL . '/pages/artist/studios.php') ?>">
            <?= ps_icon('search', 17) ?>
            <span>Browse studios</span>
        </a>
    </div>

<?php else: ?>

    <!-- ========================= STUDIO OVERVIEW ========================= -->
    <section class="ps-detail-grid">

        <article class="ps-card">
            <!-- Media placeholder: the database has no studio photo column. -->
            <div class="ps-detail__media" aria-hidden="true">
                <?= ps_icon('studios', 34) ?>
            </div>

            <div class="ps-card__body">
                <div data-studio-head aria-busy="true">
                    <div class="ps-skel ps-skel--title" style="width:60%;margin-bottom:10px"></div>
                    <div class="ps-skel ps-skel--line" style="width:40%;margin-bottom:14px"></div>
                    <div class="ps-skel ps-skel--line" style="width:100%;height:52px"></div>
                </div>

                <div data-studio-body hidden>
                    <h1 class="ps-detail__name" data-studio-name></h1>

                    <div class="ps-detail__where">
                        <?= ps_icon('map-pin', 15) ?>
                        <span data-studio-location></span>
                    </div>

                    <p class="ps-detail__desc" data-studio-desc></p>

                    <dl class="ps-dl ps-dl--stacked">
                        <div class="ps-dl__row">
                            <dt>Hourly rate</dt>
                            <dd data-studio-rate></dd>
                        </div>
                        <div class="ps-dl__row">
                            <dt>Owned by</dt>
                            <dd data-studio-owner></dd>
                        </div>
                        <div class="ps-dl__row">
                            <dt>Address</dt>
                            <dd data-studio-address></dd>
                        </div>
                    </dl>

                    <h2 class="ps-detail__subtitle">Equipment</h2>
                    <div class="ps-mcard__gear" data-studio-equipment></div>
                </div>

                <div data-studio-error hidden></div>
            </div>
        </article>

        <!-- ========================== BOOKING FORM ========================== -->
        <article class="ps-card">
            <div class="ps-card__head">
                <h2>Book a session</h2>
            </div>

            <form class="ps-card__body ps-booking-form" data-booking-form novalidate>

                <div class="ps-form-error" data-form-error role="alert"></div>

                <div class="ps-field">
                    <label class="ps-field__label" for="booking-date">
                        Date <span class="ps-req" aria-hidden="true">*</span>
                    </label>
                    <input type="date"
                           id="booking-date"
                           class="ps-input"
                           required
                           data-booking-date
                           aria-describedby="booking-date-hint booking-date-error">
                    <span class="ps-field__hint" id="booking-date-hint">
                        Sessions must start in the future.
                    </span>
                    <span class="ps-field__error" id="booking-date-error" data-field-error></span>
                </div>

                <div class="ps-field">
                    <label class="ps-field__label">Start time <span class="ps-req" aria-hidden="true">*</span></label>
                    <div class="ps-slots" data-slot-picker role="group" aria-label="Available start times">
                        <div class="ps-loading" style="padding:16px">
                            <span class="ps-spinner" aria-hidden="true"></span>
                            <span>Checking availability&hellip;</span>
                        </div>
                    </div>
                    <span class="ps-field__error" data-field-error-slot></span>
                </div>

                <div class="ps-field">
                    <label class="ps-field__label" for="booking-hours">
                        Duration <span class="ps-req" aria-hidden="true">*</span>
                    </label>
                    <select class="ps-select" id="booking-hours" data-booking-hours
                            aria-describedby="booking-hours-hint">
                        <option value="1">1 hour</option>
                        <option value="2">2 hours</option>
                        <option value="3">3 hours</option>
                        <option value="4">4 hours</option>
                        <option value="5">5 hours</option>
                        <option value="6">6 hours</option>
                        <option value="8">8 hours</option>
                    </select>
                    <span class="ps-field__hint" id="booking-hours-hint">
                        Sessions are booked in whole hours.
                    </span>
                </div>

                <!-- Live preview. Always labelled as an estimate. -->
                <dl class="ps-summary" data-booking-summary aria-live="polite">
                    <div class="ps-summary__row">
                        <dt>Studio</dt>
                        <dd data-sum-studio>&mdash;</dd>
                    </div>
                    <div class="ps-summary__row">
                        <dt>When</dt>
                        <dd data-sum-when>&mdash;</dd>
                    </div>
                    <div class="ps-summary__row">
                        <dt>Duration</dt>
                        <dd data-sum-duration>&mdash;</dd>
                    </div>
                    <div class="ps-summary__row">
                        <dt>Hourly rate</dt>
                        <dd data-sum-rate>&mdash;</dd>
                    </div>
                    <div class="ps-summary__row ps-summary__row--total">
                        <dt>Estimated total</dt>
                        <dd data-sum-total>&mdash;</dd>
                    </div>
                </dl>

                <p class="ps-booking-note">
                    <?= ps_icon('info', 13) ?>
                    <span>
                        This total is an estimate. The final amount is calculated and
                        confirmed by the server when the booking is created.
                    </span>
                </p>

                <button type="submit"
                        class="ps-btn ps-btn--primary ps-btn--block"
                        data-booking-submit
                        data-loading-label="Booking&hellip;"
                        disabled>
                    Confirm booking
                </button>
            </form>
        </article>

    </section>

    <!-- ========================= SUCCESS DIALOG ========================= -->
    <div class="ps-modal" id="booking-success-modal" role="dialog" aria-modal="true"
         aria-labelledby="booking-success-title" aria-hidden="true">
        <div class="ps-modal__backdrop"></div>
        <div class="ps-modal__panel ps-modal__panel--sm" data-modal-panel>
            <div class="ps-modal__head">
                <div class="ps-modal__head-text">
                    <h2 id="booking-success-title">Booking confirmed</h2>
                    <p data-success-sub>Your session is reserved.</p>
                </div>
            </div>

            <div class="ps-modal__body">
                <dl class="ps-dl" data-success-list></dl>
            </div>

            <div class="ps-modal__foot">
                <button type="button" class="ps-btn ps-btn--secondary" data-modal-close>
                    Stay here
                </button>
                <a class="ps-btn ps-btn--primary" data-success-link
                   href="<?= e(PS_BASE_URL . '/pages/artist/bookings.php') ?>">
                    View my bookings
                </a>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php
require __DIR__ . '/../../templates/artist-shell-end.php';