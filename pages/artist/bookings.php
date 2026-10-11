<?php

/**
 * Paradise Studios — My Bookings (artist).
 *
 * Reads GET /api/my-bookings.php and exposes the actions the backend actually
 * supports for each status, verified against the source:
 *
 *   status          cancel   reschedule   complete   reviewable
 *   ---------------------------------------------------------------
 *   escrow_held     yes      yes          yes        no
 *   confirmed       no       no           no         no
 *   in_session      no       no           no         no
 *   completed       no       no           no         yes
 *   cancelled       no       no           no         no
 *   disputed        no       no           no         no
 *
 * Rules obeyed:
 *   - buttons are only rendered where the backend would accept the call
 *   - the refund figure returned by cancel-booking.php is described as a
 *     *calculated* credit; this MVP performs no real money movement
 *   - confirmation dialogs precede every state-changing action
 */

require_once __DIR__ . '/../../includes/artist-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$artistUser = ps_current_user();

$artistPage   = 'bookings';
$pageHeading  = 'My Bookings';
$pageStyles   = ['artist-bookings.css'];
$pageScripts  = ['js/artist-ui.js', 'js/artist-bookings.js'];

require __DIR__ . '/../../templates/artist-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>My Bookings</h1>
        <p data-bookings-summary>Loading your bookings&hellip;</p>
    </div>

    <div class="ps-pagehead__actions">
        <a class="ps-btn ps-btn--primary" href="<?= e(PS_BASE_URL . '/pages/artist/studios.php') ?>">
            <?= ps_icon('plus', 17) ?>
            <span>Book a session</span>
        </a>
    </div>
</section>

<!-- ============================= TABS ============================= -->
<section class="ps-toolbar">
    <div class="ps-segment" role="tablist" aria-label="Booking status filter">
        <button type="button" class="ps-segment__btn is-active" role="tab"
                aria-selected="true" data-tab="all">
            All
        </button>
        <button type="button" class="ps-segment__btn" role="tab"
                aria-selected="false" data-tab="upcoming">
            Upcoming
        </button>
        <button type="button" class="ps-segment__btn" role="tab"
                aria-selected="false" data-tab="past">
            Past
        </button>
        <button type="button" class="ps-segment__btn" role="tab"
                aria-selected="false" data-tab="cancelled">
            Cancelled
        </button>
    </div>

    <div class="ps-toolbar__spacer"></div>

    <div class="ps-search">
        <?= ps_icon('search', 17) ?>
        <label class="ps-sr-only" for="bookings-search">Search bookings</label>
        <input type="search" id="bookings-search" class="ps-input"
               placeholder="Search studio or booking ID&hellip;"
               data-bookings-search autocomplete="off">
    </div>
</section>

<!-- ============================== LIST ============================== -->
<section data-panel="list">
    <div class="ps-card">
        <div class="ps-card__body ps-card__body--flush">
            <div class="ps-table-wrap">
                <table class="ps-stateline ps-stateline--bookings ps-stateline--cards">
                    <caption class="ps-sr-only">Your bookings</caption>
                    <thead>
                        <tr>
                            <th scope="col">Studio</th>
                            <th scope="col">Date</th>
                            <th scope="col">Time</th>
                            <th scope="col" class="num">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col">Payment</th>
                            <th scope="col" class="actions">
                                <span class="ps-sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody data-booking-rows aria-busy="true">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <tr aria-hidden="true">
                                <td><div class="ps-skel ps-skel--line" style="width:70%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:60%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:55%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:50%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:60%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:60%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:40%"></div></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- ========================= BOOKING DETAIL ========================= -->
<div class="ps-modal" id="booking-modal" role="dialog" aria-modal="true"
     aria-labelledby="booking-modal-title" aria-hidden="true">
    <div class="ps-modal__backdrop"></div>

    <div class="ps-modal__panel" data-modal-panel>
        <div class="ps-modal__head">
            <div class="ps-modal__head-text">
                <h2 id="booking-modal-title">Booking details</h2>
                <p data-modal-subtitle></p>
            </div>
            <button type="button" class="ps-iconbtn" data-modal-close aria-label="Close dialog">
                <?= ps_icon('close', 18) ?>
            </button>
        </div>

        <div class="ps-modal__body">
            <!-- Loaded from GET /api/booking-details.php on open -->
            <div data-modal-body>
                <div class="ps-loading">
                    <span class="ps-spinner" aria-hidden="true"></span>
                    <span>Loading booking&hellip;</span>
                </div>
            </div>
        </div>

        <div class="ps-modal__foot">
            <button type="button" class="ps-btn ps-btn--secondary" data-modal-close>
                Close
            </button>
            <div data-modal-actions class="ps-row"></div>
        </div>
    </div>
</div>

<!-- ========================= RESCHEDULE MODAL ========================= -->
<div class="ps-modal" id="reschedule-modal" role="dialog" aria-modal="true"
     aria-labelledby="reschedule-title" aria-hidden="true">
    <div class="ps-modal__backdrop"></div>

    <div class="ps-modal__panel" data-modal-panel>
        <form class="ps-modal__form" data-reschedule-form novalidate>
            <div class="ps-modal__head">
                <div class="ps-modal__head-text">
                    <h2 id="reschedule-title">Reschedule session</h2>
                    <p data-reschedule-subtitle></p>
                </div>
                <button type="button" class="ps-iconbtn" data-modal-close aria-label="Close dialog">
                    <?= ps_icon('close', 18) ?>
                </button>
            </div>

            <div class="ps-modal__body">
                <div class="ps-form-error" data-form-error role="alert"></div>

                <div class="ps-field">
                    <label class="ps-field__label" for="reschedule-date">
                        New date <span class="ps-req" aria-hidden="true">*</span>
                    </label>
                    <input type="date" id="reschedule-date" class="ps-input"
                           required data-reschedule-date>
                </div>

                <div class="ps-field">
                    <label class="ps-field__label">New start time
                        <span class="ps-req" aria-hidden="true">*</span>
                    </label>
                    <div class="ps-slots" data-reschedule-slots role="group"
                         aria-label="New start times">
                        <div class="ps-loading" style="padding:16px">
                            <span class="ps-spinner" aria-hidden="true"></span>
                            <span>Pick a date first</span>
                        </div>
                    </div>
                </div>

                <div class="ps-field">
                    <label class="ps-field__label" for="reschedule-hours">
                        Duration <span class="ps-req" aria-hidden="true">*</span>
                    </label>
                    <select class="ps-select" id="reschedule-hours" data-reschedule-hours>
                        <option value="1">1 hour</option>
                        <option value="2">2 hours</option>
                        <option value="3">3 hours</option>
                        <option value="4">4 hours</option>
                        <option value="5">5 hours</option>
                        <option value="6">6 hours</option>
                        <option value="8">8 hours</option>
                    </select>
                </div>

                <dl class="ps-summary">
                    <div class="ps-summary__row">
                        <dt>New total</dt>
                        <dd data-reschedule-total>&mdash;</dd>
                    </div>
                </dl>

                <p class="ps-booking-note">
                    <?= ps_icon('info', 13) ?>
                    <span>
                        The server recalculates the total from the studio's current
                        hourly rate and rejects times that overlap another booking.
                    </span>
                </p>
            </div>

            <div class="ps-modal__foot">
                <button type="button" class="ps-btn ps-btn--secondary" data-modal-close>
                    Cancel
                </button>
                <button type="submit" class="ps-btn ps-btn--primary"
                        data-reschedule-submit data-loading-label="Saving…">
                    Confirm new time
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require __DIR__ . '/../../templates/artist-shell-end.php';