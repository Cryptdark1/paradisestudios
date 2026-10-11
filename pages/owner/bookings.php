<?php

/**
 * Paradise Studios — Owner bookings.
 *
 * Reads GET /api/owner-bookings.php and presents it in two views:
 *   - a sortable/searchable list table (responsive: becomes cards on phones)
 *   - a FullCalendar month / week / list calendar built from the same rows
 *
 * Clicking a row or a calendar event opens the booking detail dialog using the
 * real booking record returned by the API.
 */

require_once __DIR__ . '/../../includes/owner-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$ownerUser = ps_current_user();

$ownerPage   = 'bookings';
$pageHeading = 'Bookings';
$pageStyles  = ['owner-bookings.css'];

/*
 * FullCalendar is vendored locally under public/vendor.
 *
 * Only the core global bundle is required: in v6 it already registers the
 * standard daygrid / timegrid / interaction / list plugins. Loading the
 * individual plugin bundles on top of it registers them a second time, which
 * makes FullCalendar log "Duplicate plugin" warnings.
 */
$pageVendorScripts = [
    'vendor/fullcalendar/index.global.min.js',
];

$pageScripts = ['js/owner-bookings.js'];

require __DIR__ . '/../../templates/owner-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>Bookings</h1>
        <p data-bookings-summary>Loading bookings&hellip;</p>
    </div>

    <div class="ps-pagehead__actions">
        <a class="ps-btn ps-btn--secondary" href="<?= e(PS_BASE_URL . '/pages/owner/dashboard.php') ?>">
            <?= ps_icon('dashboard', 17) ?>
            <span>Dashboard</span>
        </a>
    </div>
</section>

<!-- ============================= TOOLBAR ============================= -->
<section class="ps-toolbar">
    <div class="ps-search">
        <?= ps_icon('search', 17) ?>
        <label class="ps-sr-only" for="booking-search">Search bookings</label>
        <input
            type="search"
            id="booking-search"
            class="ps-input"
            placeholder="Search artist, studio or booking ID&hellip;"
            data-booking-search
            autocomplete="off"
        >
    </div>

    <div class="ps-toolbar__spacer"></div>

    <label class="ps-sr-only" for="booking-status">Filter by status</label>
    <select class="ps-select ps-select--inline" id="booking-status" data-status-filter>
        <option value="all">All statuses</option>
        <option value="pending">Pending</option>
        <option value="confirmed">Confirmed</option>
        <option value="escrow_held">Escrow held</option>
        <option value="in_session">In session</option>
        <option value="completed">Completed</option>
        <option value="cancelled">Cancelled</option>
        <option value="disputed">Disputed</option>
    </select>

    <div class="ps-segment" role="tablist" aria-label="Booking view">
        <button type="button"
                class="ps-segment__btn is-active"
                role="tab"
                aria-selected="true"
                data-view="list">
            <?= ps_icon('list', 15) ?>
            <span>List</span>
        </button>
        <button type="button"
                class="ps-segment__btn"
                role="tab"
                aria-selected="false"
                data-view="calendar">
            <?= ps_icon('calendar', 15) ?>
            <span>Calendar</span>
        </button>
    </div>
</section>

<!-- ============================== LIST ============================== -->
<section data-panel="list">
    <div class="ps-card">
        <div class="ps-card__body ps-card__body--flush">
            <div class="ps-table-wrap">
                <table class="ps-table ps-table--bookings">
                    <caption class="ps-sr-only">Your bookings</caption>
                    <thead>
                        <tr>
                            <th scope="col">Artist</th>
                            <th scope="col">Studio</th>
                            <th scope="col">Date</th>
                            <th scope="col">Time</th>
                            <th scope="col" class="num">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="actions">
                                <span class="ps-sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody data-booking-rows aria-busy="true">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr aria-hidden="true">
                                <td><div class="ps-skel ps-skel--line" style="width:80%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:70%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:60%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:55%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:50%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:65%"></div></td>
                                <td><div class="ps-skel ps-skel--line" style="width:40%"></div></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- ============================ CALENDAR ============================ -->
<section data-panel="calendar" hidden>

    <div class="ps-card">
        <div class="ps-card__head">
            <h2 data-calendar-title>Calendar</h2>

            <div class="ps-card__head-actions ps-calnav">
                <button type="button"
                        class="ps-iconbtn"
                        data-cal-nav="prev"
                        aria-label="Previous period"
                        title="Previous">
                    <?= ps_icon('chevron-left', 17) ?>
                </button>

                <button type="button"
                        class="ps-iconbtn"
                        data-cal-nav="today"
                        aria-label="Go to today"
                        title="Today">
                    <?= ps_icon('calendar', 16) ?>
                </button>

                <button type="button"
                        class="ps-iconbtn"
                        data-cal-nav="next"
                        aria-label="Next period"
                        title="Next">
                    <?= ps_icon('chevron-right', 17) ?>
                </button>

                <label class="ps-sr-only" for="calendar-range">Calendar range</label>
                <select class="ps-select ps-select--inline" id="calendar-range" data-cal-range>
                    <option value="dayGridMonth">Monthly</option>
                    <option value="timeGridWeek">Weekly</option>
                    <option value="listWeek">Agenda</option>
                </select>
            </div>
        </div>

        <div class="ps-card__body">
            <!--
                Events are generated from the real bookings returned by
                GET /api/owner-bookings.php. No sample data is used.
            -->
            <div class="ps-calendar" data-calendar></div>
            <div data-calendar-state hidden></div>
        </div>
    </div>

</section>

<!-- ======================= BOOKING DETAIL MODAL ======================= -->
<div class="ps-modal" id="booking-detail-modal" role="dialog" aria-modal="true"
     aria-labelledby="booking-detail-title" aria-hidden="true">

    <div class="ps-modal__backdrop"></div>

    <div class="ps-modal__panel" data-modal-panel>
        <div class="ps-modal__head">
            <div class="ps-modal__head-text">
                <h2 id="booking-detail-title">Booking details</h2>
                <p data-detail-subtitle></p>
            </div>
            <button type="button" class="ps-iconbtn" data-modal-close aria-label="Close dialog">
                <?= ps_icon('close', 18) ?>
            </button>
        </div>

        <div class="ps-modal__body">
            <div class="ps-detail">
                <div class="ps-detail__artist" data-detail-artist></div>

                <div class="ps-detail__when" data-detail-when></div>

                <dl class="ps-dl" data-detail-list></dl>
            </div>
        </div>

        <div class="ps-modal__foot">
            <button type="button" class="ps-btn ps-btn--secondary" data-modal-close>
                Close
            </button>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../../templates/owner-shell-end.php';