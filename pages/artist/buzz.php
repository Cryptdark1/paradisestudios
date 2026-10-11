<?php

/**
 * Paradise Studios — Buzz XP and Reviews (artist).
 *
 * Endpoints:
 *   GET  /api/buzz.php      XP, rank, next rank, progress (server-computed)
 *   GET  /api/my-bookings.php  to find completed bookings awaiting a review
 *   GET  /api/reviews.php   reviews the artist has already written
 *   POST /api/reviews.php   submit a review
 *
 * Rank thresholds are fixed by the backend (api/buzz.php):
 *   Local         0 - 499 XP
 *   City Star     500 - 1,999 XP
 *   National Icon 2,000+ XP
 *
 * The page NEVER awards XP itself. It only displays what api/buzz.php returns.
 */

require_once __DIR__ . '/../../includes/artist-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$artistUser = ps_current_user();

$artistPage   = 'buzz';
$pageHeading  = 'Buzz XP';
$pageStyles   = ['artist-buzz.css'];
$pageScripts  = ['js/artist-ui.js', 'js/artist-buzz.js'];

require __DIR__ . '/../../templates/artist-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>Buzz XP</h1>
        <p data-buzz-summary>Loading your progress&hellip;</p>
    </div>

    <div class="ps-pagehead__actions">
        <div class="ps-toolbar">
            <label class="ps-sr-only" for="buzz-progress-filter">Progress view</label>
            <select class="ps-select ps-select--inline" id="buzz-progress-filter">
                <option value="all">All progress</option>
                <option value="earned">XP earned only</option>
            </select>
        </div>
    </div>
</section>

<!-- ============================ BUZZ XP CARD ============================ -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Total Buzz XP</h2>
        <div class="ps-card__head-actions">
            <span class="ps-badge ps-badge--neutral" data-buzz-tier hidden></span>
        </div>
    </div>

    <div class="ps-card__body">
        <div class="ps-buzz" data-buzz aria-busy="true">
            <div class="ps-buzz__ring">
                <div class="ps-buzz__centre">
                    <strong><span data-buzz-xp>—</span></strong>
                    <small>XP</small>
                </div>
            </div>

            <div class="ps-buzz__meta">
                <div class="ps-buzz__rank">
                    <strong data-buzz-level>Loading&hellip;</strong>
                </div>
                <p class="ps-buzz__next" data-buzz-next></p>
                <div class="ps-progress" style="margin-top:11px" aria-hidden="true">
                    <div class="ps-progress__bar" data-buzz-bar style="width:0"></div>
                </div>
                <p class="ps-tiny ps-muted" data-buzz-progress-label style="margin-top:5px"></p>
            </div>
        </div>
    </div>
</section>

<!-- ============================= RANK LADDER ============================= -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Level milestones</h2>
    </div>

    <div class="ps-card__body">
        <div class="ps-ranks" data-ranks aria-busy="true">
            <!-- Rendered from the same thresholds the backend uses -->
        </div>
    </div>
</section>

<!-- ============================== REVIEWS ============================== -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Level reviews</h2>
        <div class="ps-card__head-actions">
            <a class="ps-btn ps-btn--ghost ps-btn--sm"
               href="<?= e(PS_BASE_URL . '/pages/artist/bookings.php') ?>">
                My bookings
            </a>
        </div>
    </div>

    <div class="ps-card__body">
        <p class="ps-tiny ps-muted" style="margin-bottom:12px">
            Reviews can be left once a session has been marked complete.
        </p>

        <!-- Completed bookings with no review yet -->
        <div data-reviewable aria-busy="true">
            <div class="ps-loading">
                <span class="ps-spinner" aria-hidden="true"></span>
                <span>Loading sessions&hellip;</span>
            </div>
        </div>
    </div>
</section>

<!-- ======================= EXISTING REVIEWS ======================= -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Your reviews</h2>
    </div>

    <div class="ps-card__body">
        <div data-reviews aria-busy="true">
            <div class="ps-loading">
                <span class="ps-spinner" aria-hidden="true"></span>
                <span>Loading reviews&hellip;</span>
            </div>
        </div>
    </div>
</section>

<!-- ========================= REVIEW MODAL ========================= -->
<div class="ps-modal" id="review-modal" role="dialog" aria-modal="true"
     aria-labelledby="review-title" aria-hidden="true">
    <div class="ps-modal__backdrop"></div>

    <div class="ps-modal__panel ps-modal__panel--sm" data-modal-panel>
        <form class="ps-modal__form" data-review-form novalidate>
            <div class="ps-modal__head">
                <div class="ps-modal__head-text">
                    <h2 id="review-title">Review this session</h2>
                    <p data-review-subtitle></p>
                </div>
                <button type="button" class="ps-iconbtn" data-modal-close aria-label="Close dialog">
                    <?= ps_icon('close', 18) ?>
                </button>
            </div>

            <div class="ps-modal__body">
                <div class="ps-form-error" data-form-error role="alert"></div>

                <div class="ps-field">
                    <span class="ps-field__label" id="review-rating-label">
                        Your rating <span class="ps-req" aria-hidden="true">*</span>
                    </span>
                    <div class="ps-rating" data-rating role="radiogroup"
                         aria-labelledby="review-rating-label">
                        <!-- 5 real radio inputs, styled as stars -->
                    </div>
                    <span class="ps-field__error" data-field-error-rating></span>
                </div>

                <div class="ps-field">
                    <label class="ps-field__label" for="review-comment">Comment</label>
                    <textarea id="review-comment" name="comment" class="ps-textarea"
                              rows="4" maxlength="1000"
                              placeholder="How was the session? (optional)"
                              aria-describedby="review-comment-hint"></textarea>
                    <span class="ps-field__hint" id="review-comment-hint">
                        Optional &middot; 1000 characters maximum
                    </span>
                </div>
            </div>

            <div class="ps-modal__foot">
                <button type="button" class="ps-btn ps-btn--secondary" data-modal-close>
                    Cancel
                </button>
                <button type="submit" class="ps-btn ps-btn--primary"
                        data-review-submit data-loading-label="Submitting…">
                    Submit review
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require __DIR__ . '/../../templates/artist-shell-end.php';