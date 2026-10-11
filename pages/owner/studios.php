<?php

/**
 * Paradise Studios — My Studios (owner).
 *
 * Reads studios from GET /api/owner-studios.php and manages them through the
 * existing write endpoints:
 *   POST /api/owner-create-studio.php
 *   PUT  /api/owner-update-studio.php?id={studio_id}
 *   PUT  /api/owner-studio-equipment.php?id={studio_id}
 *   GET  /api/owner-equipment.php   (catalogue + assignments, one request)
 *
 * The page works with any number of studios and never assumes a fixed count.
 */

require_once __DIR__ . '/../../includes/owner-guard.php';
require_once __DIR__ . '/../../includes/user.php';

/**
 * Skeleton placeholders for the studio grid, rendered server-side so the first
 * paint already shows the intended layout while the real request is in flight.
 */
function ps_studio_skeleton_cards(): string
{
    $html = '';

    for ($i = 0; $i < 3; $i++) {
        $html .= '<article class="ps-studio-card ps-studio-card--skeleton" aria-hidden="true">'
            . '<div class="ps-skel" style="height:78px;border-radius:14px 14px 0 0"></div>'
            . '<div class="ps-studio-card__body">'
            . '<div class="ps-skel ps-skel--title" style="margin-bottom:10px"></div>'
            . '<div class="ps-skel ps-skel--line" style="width:65%;margin-bottom:8px"></div>'
            . '<div class="ps-skel ps-skel--line" style="width:100%;height:44px"></div>'
            . '<div class="ps-skel ps-skel--line" style="width:45%;margin-top:12px"></div>'
            . '</div></article>';
    }

    return $html;
}

$ownerUser  = ps_current_user();
$firstName  = explode(' ', trim($ownerUser['name'] ?? 'there'))[0] ?: 'here';

$ownerPage   = 'studios';
$pageHeading = 'My Studios';
$pageStyles  = ['owner-studios.css'];

$pageScripts = ['js/owner-studios.js'];

require __DIR__ . '/../../templates/owner-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>My Studios</h1>
        <p data-studios-summary>Loading your studios&hellip;</p>
    </div>

    <div class="ps-pagehead__actions">
        <button type="button" class="ps-btn ps-btn--primary" data-open-add>
            <?= ps_icon('plus', 17) ?>
            <span>Add new studio</span>
        </button>
    </div>
</section>

<!-- ============================= TOOLBAR ============================= -->
<section class="ps-toolbar">
    <div class="ps-search">
        <?= ps_icon('search', 17) ?>
        <label class="ps-sr-only" for="studio-search">Search studios</label>
        <input
            type="search"
            id="studio-search"
            class="ps-input"
            placeholder="Search by name, location or address&hellip;"
            data-studio-search
            autocomplete="off"
        >
    </div>

    <div class="ps-toolbar__spacer"></div>

    <label class="ps-sr-only" for="studio-sort">Sort studios</label>
    <select class="ps-select ps-select--inline" id="studio-sort" data-studio-sort>
        <option value="newest">Newest first</option>
        <option value="name">Name A–Z</option>
        <option value="rate">Rate: high to low</option>
        <option value="rate-asc">Rate: low to high</option>
    </select>
</section>

<!-- ============================ STUDIO GRID ============================ -->
<section
    class="ps-studio-grid"
    data-studio-grid
    aria-busy="true"
    aria-label="Your studios"
>
    <?= ps_studio_skeleton_cards() ?>
</section>

<!-- ====================== ADD / EDIT STUDIO MODAL ====================== -->
<div class="ps-modal" id="studio-form-modal" role="dialog" aria-modal="true"
     aria-labelledby="studio-form-title" aria-hidden="true">

    <div class="ps-modal__backdrop"></div>

    <div class="ps-modal__panel" data-modal-panel>
        <form class="ps-modal__form" data-studio-form novalidate>

            <div class="ps-modal__head">
                <div class="ps-modal__head-text">
                    <h2 id="studio-form-title" data-studio-form-title>Add new studio</h2>
                    <p data-studio-form-subtitle>
                        List a room so artists can find and book it.
                    </p>
                </div>
                <button type="button" class="ps-iconbtn" data-modal-close
                        aria-label="Close dialog">
                    <?= ps_icon('close', 18) ?>
                </button>
            </div>

            <div class="ps-modal__body">
                <div class="ps-form-error" data-form-error role="alert"></div>

                <div class="ps-form-grid">
                    <div class="ps-field">
                        <label class="ps-field__label" for="studio-name">
                            Studio name <span class="ps-req" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="studio-name" name="name" class="ps-input"
                               maxlength="100" required data-autofocus
                               placeholder="e.g. Paradise Sound Lab"
                               aria-describedby="studio-name-error">
                        <span class="ps-field__error" id="studio-name-error"
                              data-field-error></span>
                    </div>

                    <div class="ps-field">
                        <label class="ps-field__label" for="studio-location">
                            Location <span class="ps-req" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="studio-location" name="location" class="ps-input"
                               maxlength="100" required
                               placeholder="e.g. Lagos"
                               aria-describedby="studio-location-error">
                        <span class="ps-field__error" id="studio-location-error"
                              data-field-error></span>
                    </div>

                    <div class="ps-field ps-field--full">
                        <label class="ps-field__label" for="studio-address">Address</label>
                        <input type="text" id="studio-address" name="address" class="ps-input"
                               maxlength="1000"
                               placeholder="Street, area, city"
                               aria-describedby="studio-address-hint studio-address-error">
                        <span class="ps-field__hint" id="studio-address-hint">
                            Optional — helps artists find the exact room.
                        </span>
                        <span class="ps-field__error" id="studio-address-error"
                              data-field-error></span>
                    </div>

                    <div class="ps-field">
                        <label class="ps-field__label" for="studio-rate">
                            Hourly rate (₦)
                            <span class="ps-req" aria-hidden="true">*</span>
                        </label>
                        <input type="number" id="studio-rate" name="hourly_rate"
                               class="ps-input" min="1" step="500" required
                               placeholder="15000"
                               aria-describedby="studio-rate-error">
                        <span class="ps-field__error" id="studio-rate-error"
                              data-field-error></span>
                    </div>

                    <div class="ps-field ps-field--full">
                        <label class="ps-field__label" for="studio-description">Description</label>
                        <textarea id="studio-description" name="description"
                                  class="ps-textarea" maxlength="5000" rows="4"
                                  placeholder="Describe the room, its acoustics and what is included."
                                  aria-describedby="studio-description-error"></textarea>
                        <span class="ps-field__error" id="studio-description-error"
                              data-field-error></span>
                    </div>
                </div>
            </div>

            <div class="ps-modal__foot">
                <button type="button" class="ps-btn ps-btn--secondary" data-modal-close>
                    Cancel
                </button>
                <button type="submit" class="ps-btn ps-btn--primary" data-studio-submit
                        data-loading-label="Saving…">
                    <span data-submit-label>Create studio</span>
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ======================= EQUIPMENT MODAL ======================= -->
<div class="ps-modal" id="equipment-modal" role="dialog" aria-modal="true"
     aria-labelledby="equipment-title" aria-hidden="true">

    <div class="ps-modal__backdrop"></div>

    <div class="ps-modal__panel ps-modal__panel--lg" data-modal-panel>

        <div class="ps-modal__head">
            <div class="ps-modal__head-text">
                <h2 id="equipment-title">Manage equipment</h2>
                <p data-equipment-subtitle>Choose what is available in this room.</p>
            </div>
            <button type="button" class="ps-iconbtn" data-modal-close aria-label="Close dialog">
                <?= ps_icon('close', 18) ?>
            </button>
        </div>

        <div class="ps-modal__body">
            <div class="ps-form-error" data-equipment-error role="alert"></div>

            <div class="ps-equipment">
                <div class="ps-equipment__search">
                    <div class="ps-search">
                        <?= ps_icon('search', 16) ?>
                        <label class="ps-sr-only" for="equipment-search">Search equipment</label>
                        <input type="search" id="equipment-search" class="ps-input"
                               placeholder="Search equipment&hellip;"
                               data-equipment-search autocomplete="off">
                    </div>
                    <span class="ps-badge ps-badge--info" data-equipment-count>0 selected</span>
                </div>

                <div class="ps-equipment__list" data-equipment-list aria-busy="true"></div>
            </div>
        </div>

        <div class="ps-modal__foot">
            <span class="ps-modal__foot-note" data-equipment-note>
                Changes are saved when you press Save.
            </span>
            <button type="button" class="ps-btn ps-btn--secondary" data-modal-close>
                Cancel
            </button>
            <button type="button" class="ps-btn ps-btn--primary" data-equipment-save
                    data-loading-label="Saving equipment…">
                <span>Save changes</span>
            </button>
        </div>

    </div>
</div>

<?php
require __DIR__ . '/../../templates/owner-shell-end.php';