<?php

/**
 * Paradise Studios — Owner analytics.
 *
 * Reads GET /api/owner-analytics.php and renders it with Chart.js.
 * Every value comes from the API; the page never invents a figure.
 *
 * Charts shown:
 *   - bookings by peak hour      (bar)   <- analytics.peak_hours
 *   - status distribution        (doughnut) <- derived from owner-bookings.php
 *   - revenue over time          (line)  <- derived from owner-bookings.php
 */

require_once __DIR__ . '/../../includes/owner-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$ownerUser = ps_current_user();

$ownerPage   = 'analytics';
$pageHeading = 'Analytics';
$pageStyles  = ['owner-analytics.css'];

$pageVendorScripts = ['vendor/chartjs/chart.umd.min.js'];
$pageScripts       = ['js/owner-analytics.js'];

require __DIR__ . '/../../templates/owner-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>Analytics</h1>
        <p data-analytics-summary>Loading your performance data&hellip;</p>
    </div>

    <div class="ps-pagehead__actions">
        <button type="button" class="ps-btn ps-btn--secondary" data-reload>
            <?= ps_icon('refresh', 17) ?>
            <span>Refresh</span>
        </button>
        <a class="ps-btn ps-btn--primary" href="<?= e(PS_BASE_URL . '/pages/owner/bookings.php') ?>">
            <?= ps_icon('bookings', 17) ?>
            <span>View bookings</span>
        </a>
    </div>
</section>

<!-- =========================== KEY FIGURES =========================== -->
<section class="ps-stats" aria-label="Key figures">

    <article class="ps-stat">
        <div class="ps-stat__label">
            <?= ps_icon('bookings', 15) ?>
            <span>Total bookings</span>
        </div>
        <div class="ps-stat__value ps-num" data-stat="bookings">
            <span class="ps-skel ps-skel--title" style="width:70px;height:26px"></span>
        </div>
        <div class="ps-stat__foot" data-stat-foot="bookings"></div>
    </article>

    <article class="ps-stat">
        <div class="ps-stat__label">
            <?= ps_icon('wallet', 15) ?>
            <span>Gross revenue</span>
        </div>
        <div class="ps-stat__value ps-num" data-stat="revenue">
            <span class="ps-skel ps-skel--title" style="width:110px;height:26px"></span>
        </div>
        <div class="ps-stat__foot" data-stat-foot="revenue"></div>
    </article>

    <article class="ps-stat">
        <div class="ps-stat__label">
            <?= ps_icon('clock', 15) ?>
            <span>Pending escrow</span>
        </div>
        <div class="ps-stat__value ps-stat__value--sm ps-num" data-stat="escrow">
            <span class="ps-skel ps-skel--title" style="width:95px;height:26px"></span>
        </div>
        <div class="ps-stat__foot" data-stat-foot="escrow"></div>
    </article>

    <article class="ps-stat">
        <div class="ps-stat__label">
            <?= ps_icon('bolt', 15) ?>
            <span>Top peak hour</span>
        </div>
        <div class="ps-stat__value ps-stat__value--sm" data-stat="tophour">
            <span class="ps-skel ps-skel--title" style="width:100px;height:26px"></span>
        </div>
        <div class="ps-stat__foot" data-stat-foot="tophour"></div>
    </article>

</section>

<!-- ============================ PEAK HOURS ============================ -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Peak booking hours</h2>
        <div class="ps-card__head-actions">
            <span class="ps-muted ps-tiny">Sessions by start hour</span>
        </div>
    </div>

    <div class="ps-card__body">
        <div class="ps-chartbox ps-chartbox--lg" data-chart-peak>
            <div class="ps-loading" data-chart-loading>
                <span class="ps-spinner" aria-hidden="true"></span>
                <span>Loading peak hours&hellip;</span>
            </div>
        </div>
    </div>
</section>

<!-- ==================== STATUS MIX + REVENUE ==================== -->
<section class="ps-grid ps-grid--2">

    <article class="ps-card">
        <div class="ps-card__head">
            <h2>Booking status mix</h2>
        </div>

        <div class="ps-card__body">
            <div class="ps-chartbox ps-chartbox--md" data-chart-status>
                <div class="ps-loading" data-chart-loading>
                    <span class="ps-spinner" aria-hidden="true"></span>
                    <span>Loading bookings&hellip;</span>
                </div>
            </div>

            <ul class="ps-legend" data-status-legend></ul>
        </div>
    </article>

    <article class="ps-card">
        <div class="ps-card__head">
            <h2>Revenue over time</h2>

            <div class="ps-card__head-actions">
                <label class="ps-sr-only" for="ana-range">Revenue range</label>
                <select class="ps-select ps-select--inline" id="ana-range" data-range-select>
                    <option value="all">All time</option>
                    <option value="30">Last 30 days</option>
                    <option value="7">Last 7 days</option>
                    <option value="forward">Next 30 days</option>
                    <option value="365">Last 12 months</option>
                </select>
            </div>
        </div>

        <div class="ps-card__body">
            <div class="ps-chartbox ps-chartbox--md" data-chart-revenue>
                <div class="ps-loading" data-chart-loading>
                    <span class="ps-spinner" aria-hidden="true"></span>
                    <span>Loading revenue&hellip;</span>
                </div>
            </div>
        </div>
    </article>

</section>

<!-- ======================= HOURLY DEMAND TABLE ======================= -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Demand by hour</h2>
        <div class="ps-card__head-actions">
            <span class="ps-muted ps-tiny" data-peak-total></span>
        </div>
    </div>

    <div class="ps-card__body ps-card__body--flush">
        <div class="ps-table-wrap">
            <table class="ps-table ps-table--hours">
                <caption class="ps-sr-only">Bookings by start hour</caption>
                <thead>
                    <tr>
                        <th scope="col">Hour</th>
                        <th scope="col" class="num">Sessions</th>
                        <th scope="col">Share</th>
                    </tr>
                </thead>
                <tbody data-peak-rows aria-busy="true">
                    <tr aria-hidden="true">
                        <td><div class="ps-skel ps-skel--line" style="width:60%"></div></td>
                        <td><div class="ps-skel ps-skel--line" style="width:40%"></div></td>
                        <td><div class="ps-skel ps-skel--line" style="width:70%"></div></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php
require __DIR__ . '/../../templates/owner-shell-end.php';