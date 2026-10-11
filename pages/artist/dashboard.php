<?php

/**
 * Paradise Studios — Artist dashboard.
 *
 * Data sources (all existing endpoints, all real):
 *   GET /api/buzz.php          -> XP total, rank, next-rank target, remaining
 *   GET /api/my-bookings.php   -> upcoming sessions + recent activity
 *   GET /api/tracks.php        -> recent / released tracks
 *
 * No statistic on this page is fabricated: every figure is either returned by an
 * endpoint or derived from real rows by client-side arithmetic.
 */

require_once __DIR__ . '/../../includes/artist-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$artistUser = ps_current_user();
$firstName  = explode(' ', trim($artistUser['name'] ?? 'there'))[0] ?: 'there';

$hour       = (int) date('G');
$greeting   = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$artistPage   = 'dashboard';
$pageHeading  = 'Dashboard';
$pageStyles   = ['artist-dashboard.css'];
$pageScripts  = ['js/artist-ui.js', 'js/artist-dashboard.js'];

require __DIR__ . '/../../templates/artist-shell-start.php';

?>

<!-- ============================== HERO ============================== -->
<section class="ps-hero">
    <div class="ps-hero__text">
        <h1 class="ps-hero__title">Your next hit starts here</h1>
        <p class="ps-hero__sub" data-hero-sub>
            Find a room, book the session, and keep every track in one place.
        </p>
    </div>

    <div class="ps-hero__actions">
        <a class="ps-btn ps-btn--secondary" href="<?= e(PS_BASE_URL . '/pages/artist/studios.php') ?>">
            <?= ps_icon('search', 17) ?>
            <span>Discover studios</span>
        </a>
        <a class="ps-btn ps-btn--primary" href="<?= e(PS_BASE_URL . '/pages/artist/bookings.php') ?>">
            <?= ps_icon('bookings', 17) ?>
            <span>My bookings</span>
        </a>
    </div>
</section>

<!-- ============ BUZZ XP + UPCOMING SESSION (side by side) ============ -->
<section class="ps-grid ps-grid--halves">

<!-- ============================ BUZZ XP ============================ -->
<article class="ps-card">
    <div class="ps-card__head">
        <h2>Buzz XP</h2>
        <div class="ps-card__head-actions">
            <a class="ps-btn ps-btn--ghost ps-btn--sm" href="<?= e(PS_BASE_URL . '/pages/artist/buzz.php') ?>">
                View progress
            </a>
        </div>
    </div>

    <div class="ps-card__body">
        <div class="ps-buzz" data-buzz>
            <!-- Populated from GET /api/buzz.php -->
            <div class="ps-buzz__ring">
                <div class="ps-buzz__centre">
                    <strong><span data-buzz-xp>—</span></strong>
                    <small>XP</small>
                </div>
            </div>

            <div class="ps-buzz__meta">
                <div class="ps-buzz__rank">
                    <strong data-buzz-level>Loading&hellip;</strong>
                    <span class="ps-badge ps-badge--neutral" data-buzz-level-badge hidden></span>
                </div>
                <p class="ps-buzz__next" data-buzz-next></p>
            </div>
        </div>
    </div>
</article>

<article class="ps-card">
    <div class="ps-card__head">
        <h2>Upcoming session</h2>
    </div>

    <div class="ps-card__body ps-card__body--flush">
        <div data-upcoming aria-busy="true">
            <div class="ps-loading">
                <span class="ps-spinner" aria-hidden="true"></span>
                <span>Loading session&hellip;</span>
            </div>
        </div>
    </div>
</article>

</section>

<!-- ============== RECENT BOOKING (full width) ==============
     Full width because six columns need more room than the two-up
     grid gives them; in a half column the actions button was being
     pushed out of the card. -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Recent booking</h2>
        <div class="ps-card__head-actions">
            <a class="ps-btn ps-btn--ghost ps-btn--sm"
               href="<?= e(PS_BASE_URL . '/pages/artist/bookings.php') ?>">
                View all
            </a>
        </div>
    </div>

    <div class="ps-card__body ps-card__body--flush">
        <div class="ps-table-wrap">
            <table class="ps-stateline ps-stateline--cards" data-recent-table>
                <caption class="ps-sr-only">Your most recent bookings</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Date</th>
                        <th scope="col">Time</th>
                        <th scope="col" class="num">Price</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="actions">
                            <span class="ps-sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody data-recent-rows aria-busy="true">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <tr aria-hidden="true">
                            <td><div class="ps-skel ps-skel--line" style="width:75%"></div></td>
                            <td><div class="ps-skel ps-skel--line" style="width:65%"></div></td>
                            <td><div class="ps-skel ps-skel--line" style="width:60%"></div></td>
                            <td><div class="ps-skel ps-skel--line" style="width:50%"></div></td>
                            <td><div class="ps-skel ps-skel--line" style="width:60%"></div></td>
                            <td><div class="ps-skel ps-skel--line" style="width:40%"></div></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- =========================== QUICK ACTIONS =========================== -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Quick actions</h2>
    </div>

    <div class="ps-card__body">
        <div class="ps-quickgrid">
            <a class="ps-quick" href="<?= e(PS_BASE_URL . '/pages/artist/studios.php') ?>">
                <span class="ps-quick__icon"><?= ps_icon('search', 18) ?></span>
                <span class="ps-quick__text">
                    <strong>Discover Studios</strong>
                    <span>Browse every open room</span>
                </span>
            </a>

            <a class="ps-quick" href="<?= e(PS_BASE_URL . '/pages/artist/bookings.php') ?>">
                <span class="ps-quick__icon"><?= ps_icon('bookings', 18) ?></span>
                <span class="ps-quick__text">
                    <strong>View Bookings</strong>
                    <span>Upcoming, past &amp; cancelled</span>
                </span>
            </a>

            <a class="ps-quick" href="<?= e(PS_BASE_URL . '/pages/artist/tracks.php?action=upload') ?>">
                <span class="ps-quick__icon"><?= ps_icon('music', 18) ?></span>
                <span class="ps-quick__text">
                    <strong>Upload Track</strong>
                    <span>Add audio from a session</span>
                </span>
            </a>

            <a class="ps-quick" href="<?= e(PS_BASE_URL . '/pages/artist/buzz.php') ?>">
                <span class="ps-quick__icon"><?= ps_icon('bolt', 18) ?></span>
                <span class="ps-quick__text">
                    <strong>Buzz XP</strong>
                    <span>Rank, XP and reviews</span>
                </span>
            </a>
        </div>
    </div>
</section>

<!-- ============================ MY TRACKS ============================ -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>My tracks</h2>
        <div class="ps-card__head-actions">
            <a class="ps-btn ps-btn--secondary ps-btn--sm"
               href="<?= e(PS_BASE_URL . '/pages/artist/tracks.php') ?>">
                Open library
            </a>
        </div>
    </div>

    <div class="ps-card__body ps-card__body--flush">
        <div data-tracks aria-busy="true">
            <?php for ($i = 0; $i < 3; $i++): ?>
                <div class="ps-track" aria-hidden="true">
                    <div class="ps-skel" style="width:40px;height:40px;border-radius:10px"></div>
                    <div class="ps-track__main">
                        <div class="ps-skel ps-skel--line" style="width:55%;margin-bottom:6px"></div>
                        <div class="ps-skel ps-skel--line" style="width:35%"></div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</section>

<?php
require __DIR__ . '/../../templates/artist-shell-end.php';