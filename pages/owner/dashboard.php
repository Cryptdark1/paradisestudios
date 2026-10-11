<?php

/**
 * Paradise Studios — Owner dashboard.
 *
 * Every figure on this page comes from the existing backend APIs:
 *   GET /api/owner-analytics.php   totals + peak hours
 *   GET /api/owner-bookings.php    upcoming sessions + revenue series
 *   GET /api/owner-studios.php     studio count
 * Nothing is hardcoded and nothing is fabricated.
 */

require_once __DIR__ . '/../../includes/owner-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$ownerUser   = ps_current_user();
$ownerName   = $ownerUser['name'] ?? 'there';
$firstName   = explode(' ', trim($ownerName))[0] ?: 'there';

/* Time-of-day greeting, mirroring the design ("Good morning, John"). */
$hour        = (int) date('G');
$salutation  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$ownerPage   = 'dashboard';
$pageHeading = 'Dashboard';
$pageStyles  = ['owner-dashboard.css'];

$pageVendorScripts = ['vendor/chartjs/chart.umd.min.js'];
$pageScripts       = ['js/owner-dashboard.js'];

require __DIR__ . '/../../templates/owner-shell-start.php';

?>

<!-- =========================== WELCOME =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1><?= e($salutation) ?>, <?= e($firstName) ?></h1>
        <p data-dashboard-summary>
            Loading your studio activity&hellip;
        </p>
    </div>

    <div class="ps-pagehead__actions">
        <a class="ps-btn ps-btn--secondary" href="<?= e(PS_BASE_URL . '/pages/owner/analytics.php') ?>">
            <?= ps_icon('analytics', 17) ?>
            <span>View analytics</span>
        </a>
        <a class="ps-btn ps-btn--primary" href="<?= e(PS_BASE_URL . '/pages/owner/studios.php?action=add') ?>">
            <?= ps_icon('plus', 17) ?>
            <span>Add studio</span>
        </a>
    </div>
</section>

<!-- =========================== STATISTICS =========================== -->
<section class="ps-stats" aria-label="Business summary">

    <article class="ps-stat">
        <div class="ps-stat__label">
            <?= ps_icon('bookings', 15) ?>
            <span>Total Bookings</span>
        </div>
        <div class="ps-stat__value ps-num" data-stat="bookings">
            <span class="ps-skel ps-skel--title" style="width:70px;height:26px"></span>
        </div>
        <div class="ps-stat__foot" data-stat-foot="bookings">
            <span class="ps-muted">Across all studios</span>
        </div>
    </article>

    <article class="ps-stat">
        <div class="ps-stat__label">
            <?= ps_icon('wallet', 15) ?>
            <span>Captured Payments</span>
        </div>
        <div class="ps-stat__value ps-num" data-stat="revenue">
            <span class="ps-skel ps-skel--title" style="width:110px;height:26px"></span>
        </div>
        <div class="ps-stat__foot" data-stat-foot="revenue">
            <span class="ps-muted">Verified payment captures; not owner transfers</span>
        </div>
    </article>

    <article class="ps-stat">
        <div class="ps-stat__label">
            <?= ps_icon('clock', 15) ?>
            <span>Pending Owner Earnings</span>
        </div>
        <div class="ps-stat__value ps-stat__value--sm ps-num" data-stat="escrow">
            <span class="ps-skel ps-skel--title" style="width:95px;height:26px"></span>
        </div>
        <div class="ps-stat__foot" data-stat-foot="escrow">
            <span class="ps-muted">Accounting eligibility; no custody implied</span>
        </div>
    </article>

    <article class="ps-stat">
        <div class="ps-stat__label">
            <?= ps_icon('studios', 15) ?>
            <span>My Studios</span>
        </div>
        <div class="ps-stat__value ps-num" data-stat="studios">
            <span class="ps-skel ps-skel--title" style="width:56px;height:26px"></span>
        </div>
        <div class="ps-stat__foot" data-stat-foot="studios">
            <span class="ps-muted">Loading locations&hellip;</span>
        </div>
    </article>

</section>

<section class="ps-card" aria-labelledby="owner-payout-heading">
    <div class="ps-card__head">
        <div>
            <h2 id="owner-payout-heading">Owner Payouts</h2>
            <p class="ps-muted">Eligible earnings can be transferred to your verified bank account.</p>
        </div>
        <strong data-payout-available>Loading&hellip;</strong>
    </div>
    <div class="ps-card__body">
        <form data-payout-account-form class="ps-row">
            <label>
                <span>Bank</span>
                <select class="ps-select" required data-payout-bank>
                    <option value="">Loading banks&hellip;</option>
                </select>
            </label>
            <label>
                <span>10-digit account number</span>
                <input class="ps-input" inputmode="numeric" autocomplete="off" maxlength="10"
                       pattern="[0-9]{10}" required data-payout-account-number>
            </label>
            <button class="ps-btn ps-btn--secondary" type="submit">Verify bank account</button>
        </form>
        <p data-payout-account-status class="ps-muted" aria-live="polite"></p>
        <div data-payout-eligible aria-live="polite">Loading eligible earnings&hellip;</div>
        <h3>Transfer history</h3>
        <div data-payout-history aria-live="polite">Loading transfer history&hellip;</div>
        <p class="ps-muted">A processing, uncertain, or failed transfer is not reported as paid. Reconcile uncertain attempts before retrying.</p>
    </div>
</section>

<!-- =================== REVENUE + UPCOMING SESSIONS =================== -->
<section class="ps-grid ps-grid--main-side">

    <article class="ps-card">
        <div class="ps-card__head">
            <h2>Revenue Over Time</h2>

            <div class="ps-card__head-actions">
                <label class="ps-sr-only" for="dash-range">Revenue range</label>
                <select class="ps-select ps-select--inline" id="dash-range" data-range-select>
                    <option value="all">All time</option>
                    <option value="30">Last 30 days</option>
                    <option value="7">Last 7 days</option>
                    <option value="forward">Next 30 days</option>
                    <option value="365">Last 12 months</option>
                </select>
            </div>
        </div>

        <div class="ps-card__body">
            <div class="ps-row ps-row--between ps-chart-legend" aria-hidden="true">
                <span class="ps-chart-legend__item">
                    <span class="ps-chart-legend__swatch ps-chart-legend__swatch--amber"></span>
                    Completed booking value
                </span>
                <span class="ps-chart-legend__item">
                    <span class="ps-chart-legend__swatch ps-chart-legend__swatch--indigo"></span>
                    Confirmed booking value
                </span>
            </div>

            <div class="ps-chartbox ps-chartbox--md" data-chart-revenue>
                <div class="ps-loading" data-chart-loading>
                    <span class="ps-spinner" aria-hidden="true"></span>
                    <span>Loading revenue&hellip;</span>
                </div>
            </div>
        </div>
    </article>

    <article class="ps-card">
        <div class="ps-card__head">
            <h2>Upcoming Sessions</h2>
            <div class="ps-card__head-actions">
                <a class="ps-btn ps-btn--ghost ps-btn--sm" href="<?= e(PS_BASE_URL . '/pages/owner/bookings.php') ?>">
                    View all
                </a>
            </div>
        </div>

        <div class="ps-card__body ps-card__body--flush">
            <ul class="ps-sessions" data-upcoming-list aria-busy="true">
                <li class="ps-sessions__item">
                    <div class="ps-skel ps-skel--line" style="width:75%;margin-bottom:8px"></div>
                    <div class="ps-skel ps-skel--line" style="width:50%"></div>
                </li>
                <li class="ps-sessions__item">
                    <div class="ps-skel ps-skel--line" style="width:70%;margin-bottom:8px"></div>
                    <div class="ps-skel ps-skel--line" style="width:45%"></div>
                </li>
                <li class="ps-sessions__item">
                    <div class="ps-skel ps-skel--line" style="width:80%;margin-bottom:8px"></div>
                    <div class="ps-skel ps-skel--line" style="width:55%"></div>
                </li>
            </ul>
        </div>
    </article>

</section>

<!-- =================== PEAK HOURS + QUICK ACTIONS =================== -->
<section class="ps-grid ps-grid--2">

    <article class="ps-card">
        <div class="ps-card__head">
            <h2>Peak Booking Hours</h2>
            <div class="ps-card__head-actions">
                <span class="ps-badge ps-badge--neutral" data-top-peak hidden></span>
            </div>
        </div>

        <div class="ps-card__body">
            <div class="ps-chartbox ps-chartbox--sm" data-chart-peak>
                <div class="ps-loading" data-chart-loading>
                    <span class="ps-spinner" aria-hidden="true"></span>
                    <span>Loading peak hours&hellip;</span>
                </div>
            </div>
        </div>
    </article>

    <article class="ps-card">
        <div class="ps-card__head">
            <h2>Quick Actions</h2>
        </div>

        <div class="ps-card__body">
            <div class="ps-quickgrid">
                <a class="ps-quick" href="<?= e(PS_BASE_URL . '/pages/owner/studios.php?action=add') ?>">
                    <span class="ps-quick__icon"><?= ps_icon('plus', 18) ?></span>
                    <span class="ps-quick__text">
                        <strong>Add Studio</strong>
                        <span>List a new room</span>
                    </span>
                </a>

                <a class="ps-quick" href="<?= e(PS_BASE_URL . '/pages/owner/bookings.php') ?>">
                    <span class="ps-quick__icon"><?= ps_icon('bookings', 18) ?></span>
                    <span class="ps-quick__text">
                        <strong>View Bookings</strong>
                        <span>List &amp; calendar</span>
                    </span>
                </a>

                <a class="ps-quick" href="<?= e(PS_BASE_URL . '/pages/owner/studios.php') ?>">
                    <span class="ps-quick__icon"><?= ps_icon('studios', 18) ?></span>
                    <span class="ps-quick__text">
                        <strong>Manage Studios</strong>
                        <span>Details, rates &amp; gear</span>
                    </span>
                </a>

                <a class="ps-quick" href="<?= e(PS_BASE_URL . '/pages/owner/analytics.php') ?>">
                    <span class="ps-quick__icon"><?= ps_icon('chart-line', 18) ?></span>
                    <span class="ps-quick__text">
                        <strong>Full Analytics</strong>
                        <span>Revenue &amp; demand</span>
                    </span>
                </a>
            </div>
        </div>
    </article>

</section>

<?php
require __DIR__ . '/../../templates/owner-shell-end.php';