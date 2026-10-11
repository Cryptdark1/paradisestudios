<?php

/**
 * Paradise Studios — Discover Studios (artist marketplace).
 *
 * Reads GET /api/studios.php, whose verified query parameters are:
 *   location    partial match on studio location
 *   max_price   hourly_rate <= this value
 *   equipment   comma separated equipment IDs that must ALL be present
 *
 * The equipment filter needs IDs, so the catalogue is loaded once from
 * GET /api/equipment.php to build the checkbox list.
 *
 * Sorting is applied to the returned rows because the endpoint does not
 * support a sort parameter; this is presentation only and never affects which
 * studios are returned.
 */

require_once __DIR__ . '/../../includes/artist-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$artistUser = ps_current_user();
$firstName  = explode(' ', trim($artistUser['name'] ?? 'there'))[0] ?: 'there';

$artistPage   = 'studios';
$pageHeading  = 'Marketplace';
$pageStyles   = ['artist-studios.css'];
$pageScripts  = ['js/artist-ui.js', 'js/artist-studios.js'];

require __DIR__ . '/../../templates/artist-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>Marketplace</h1>
        <p data-mplace-summary>Loading available studios&hellip;</p>
    </div>

    <div class="ps-pagehead__actions">
        <a class="ps-btn ps-btn--secondary" href="<?= e(PS_BASE_URL . '/pages/artist/bookings.php') ?>">
            <?= ps_icon('bookings', 17) ?>
            <span>My bookings</span>
        </a>
    </div>
</section>

<!-- ============================= FILTERS ============================= -->
<section class="ps-filters" aria-label="Studio filters">

    <div class="ps-filters__search">
        <?= ps_icon('search', 17) ?>
        <label class="ps-sr-only" for="mplace-location">Search studios by location</label>
        <input
            type="search"
            id="mplace-location"
            class="ps-input"
            placeholder="Search studios by location&hellip;"
            data-filter-location
            autocomplete="off"
        >
    </div>

    <div class="ps-filters__field">
        <label for="mplace-maxprice">Max price / hour</label>
        <select class="ps-select" id="mplace-maxprice" data-filter-price>
            <option value="">Any price</option>
            <option value="10000">Up to &#8358;10,000</option>
            <option value="15000">Up to &#8358;15,000</option>
            <option value="20000">Up to &#8358;20,000</option>
            <option value="30000">Up to &#8358;30,000</option>
            <option value="50000">Up to &#8358;50,000</option>
        </select>
    </div>

    <div class="ps-equip-pop">
        <button type="button"
                class="ps-btn ps-btn--secondary ps-filters__equip-btn"
                data-equip-toggle
                aria-expanded="false"
                aria-haspopup="true">
            <?= ps_icon('sliders2', 16) ?>
            <span>Equipment</span>
            <span class="ps-filters__equip-count" data-equip-count hidden></span>
        </button>

        <div class="ps-equip-pop__menu" data-equip-menu hidden role="group" aria-label="Required equipment">
            <!-- Built from GET /api/equipment.php -->
        </div>
    </div>

    <div class="ps-filters__field">
        <label for="mplace-sort">Sort by</label>
        <select class="ps-select" id="mplace-sort" data-filter-sort>
            <option value="relevance">Relevance</option>
            <option value="price-asc">Price: low to high</option>
            <option value="price-desc">Price: high to low</option>
            <option value="name">Name A&ndash;Z</option>
            <option value="equipment">Most equipment</option>
        </select>
    </div>

    <div class="ps-filters__field">
        <button type="button" class="ps-btn ps-btn--ghost" data-filter-reset>Reset</button>
    </div>

</section>

<!-- ============================ STUDIO GRID ============================ -->
<section class="ps-mplace" data-mplace-grid aria-busy="true" aria-label="Available studios">
    <?php for ($i = 0; $i < 4; $i++): ?>
        <article class="ps-mcard" aria-hidden="true">
            <div class="ps-skel" style="height:132px;border-radius:15px 15px 0 0"></div>
            <div class="ps-mcard__body">
                <div class="ps-skel ps-skel--line" style="width:65%;height:13px;margin-bottom:8px"></div>
                <div class="ps-skel ps-skel--line" style="width:45%;margin-bottom:10px"></div>
                <div class="ps-skel ps-skel--line" style="width:100%;height:40px"></div>
            </div>
        </article>
    <?php endfor; ?>
</section>

<?php
require __DIR__ . '/../../templates/artist-shell-end.php';