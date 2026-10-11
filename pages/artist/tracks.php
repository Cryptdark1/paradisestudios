<?php

/**
 * Paradise Studios — My Tracks (artist library).
 *
 * Endpoints:
 *   GET  /api/tracks.php     -> list the artist's own tracks
 *   POST /api/tracks.php     -> upload (multipart: booking_id, title, audio)
 *   PUT  /api/release-track.php?id= -> release, awarding XP server-side
 *
 * Upload rules verified from the backend source, and mirrored in the UI purely
 * as guidance. The server remains authoritative:
 *   - booking_id must belong to the signed-in artist AND be 'completed'
 *   - title required, max 100 characters
 *   - audio file required, max 20 MB
 *   - MIME must be audio/mpeg, audio/wav, audio/x-wav, audio/mp4, audio/x-m4a
 *     or audio/ogg  (checked with finfo, not the file extension)
 *
 * file_path is never returned by the GET branch, so no internal server path is
 * exposed to the browser.
 */

require_once __DIR__ . '/../../includes/artist-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$artistUser = ps_current_user();

/* Deep link from the dashboard's "Upload Track" quick action. */
$openUpload = isset($_GET['action']) && $_GET['action'] === 'upload';

$artistPage   = 'tracks';
$pageHeading  = 'Library';
$pageStyles   = ['artist-tracks.css'];
$pageScripts  = ['js/artist-ui.js', 'js/artist-tracks.js'];

require __DIR__ . '/../../templates/artist-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>Library</h1>
        <p data-tracks-summary>Loading your tracks&hellip;</p>
    </div>

    <div class="ps-pagehead__actions">
        <button type="button" class="ps-btn ps-btn--primary" data-open-upload>
            <?= ps_icon('upload', 17) ?>
            <span>Upload track</span>
        </button>
    </div>
</section>

<!-- ============================= TOOLBAR ============================= -->
<section class="ps-toolbar">
    <div class="ps-segment" role="tablist" aria-label="Track status filter">
        <button type="button" class="ps-segment__btn is-active" role="tab"
                aria-selected="true" data-track-tab="all">
            All
        </button>
        <button type="button" class="ps-segment__btn" role="tab"
                aria-selected="false" data-track-tab="recording">
            Recording
        </button>
        <button type="button" class="ps-segment__btn" role="tab"
                aria-selected="false" data-track-tab="released">
            Released
        </button>
    </div>

    <div class="ps-toolbar__spacer"></div>

    <div class="ps-search">
        <?= ps_icon('search', 17) ?>
        <label class="ps-sr-only" for="tracks-search">Search tracks</label>
        <input type="search" id="tracks-search" class="ps-input"
               placeholder="Search track title&hellip;"
               data-tracks-search autocomplete="off">
    </div>
</section>

<!-- ============================== LIBRARY ============================== -->
<section class="ps-card">
    <div class="ps-card__body ps-card__body--flush">
        <div class="ps-tracks" data-tracks-list aria-busy="true">
            <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="ps-track" aria-hidden="true">
                    <div class="ps-skel" style="width:40px;height:40px;border-radius:10px"></div>
                    <div class="ps-track__main">
                        <div class="ps-skel ps-skel--line" style="width:50%;margin-bottom:6px"></div>
                        <div class="ps-skel ps-skel--line" style="width:32%"></div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="ps-card__foot ps-tracks-foot">
        <span class="ps-tiny ps-muted" data-tracks-foot></span>
    </div>
</section>

<!-- =========================== UPLOAD MODAL =========================== -->
<div class="ps-modal" id="upload-modal" role="dialog" aria-modal="true"
     aria-labelledby="upload-title" aria-hidden="true">

    <div class="ps-modal__backdrop"></div>

    <div class="ps-modal__panel" data-modal-panel>
        <form class="ps-modal__form" data-upload-form novalidate>

            <div class="ps-modal__head">
                <div class="ps-modal__head-text">
                    <!-- Distinct id: the dialog heading must not collide with
                         the "upload-title" input, or UI.qs('#upload-title')
                         would resolve to the heading instead of the field. -->
                    <h2 id="upload-track-heading">Upload track</h2>
                    <p>Attach a recording to a completed booking.</p>
                </div>
                <button type="button" class="ps-iconbtn" data-modal-close aria-label="Close dialog">
                    <?= ps_icon('close', 18) ?>
                </button>
            </div>

            <div class="ps-modal__body">
                <div class="ps-form-error" data-form-error role="alert"></div>

                <div class="ps-field">
                    <label class="ps-field__label" for="upload-booking">
                        Completed booking <span class="ps-req" aria-hidden="true">*</span>
                    </label>
                    <select class="ps-select" id="upload-booking"
                            name="booking_id"
                            data-upload-booking required
                            aria-describedby="upload-booking-hint upload-booking-error">
                        <option value="">Loading bookings&hellip;</option>
                    </select>
                    <span class="ps-field__hint" id="upload-booking-hint">
                        Only completed sessions can have a track attached.
                    </span>
                    <span class="ps-field__error" id="upload-booking-error" data-field-error></span>
                </div>

                <div class="ps-field">
                    <label class="ps-field__label" for="upload-title">
                        Track title <span class="ps-req" aria-hidden="true">*</span>
                    </label>
                    <input type="text" id="upload-title" name="title" class="ps-input"
                           maxlength="100" required
                           placeholder="e.g. Midnight Frequencies"
                           aria-describedby="upload-title-error">
                    <span class="ps-field__error" id="upload-title-error" data-field-error></span>
                </div>

                <div class="ps-field">
                    <span class="ps-field__label" id="upload-file-label">
                        Audio file <span class="ps-req" aria-hidden="true">*</span>
                    </span>

                    <label class="ps-file" for="upload-audio">
                        <span class="ps-file__icon"><?= ps_icon('music', 18) ?></span>
                        <span class="ps-file__text">
                            <span class="ps-file__name" data-file-name>Choose an audio file</span>
                            <span class="ps-file__hint">
                                MP3, WAV, M4A or OGG &middot; 20 MB maximum
                            </span>
                        </span>
                        <input type="file"
                               id="upload-audio"
                               name="audio"
                               accept="audio/mpeg,audio/wav,audio/x-wav,audio/mp4,audio/x-m4a,audio/ogg,.mp3,.wav,.m4a,.ogg"
                               aria-describedby="upload-file-label upload-audio-error">
                    </label>

                    <span class="ps-field__error" id="upload-audio-error" data-field-error></span>

                    <!-- Progress is only shown while a real request is running -->
                    <!--
                        Hidden until a real upload starts, so the form never
                        implies work is happening.
                    -->
                    <div class="ps-upload-progress" data-upload-progress hidden>
                        <div class="ps-progress">
                            <div class="ps-progress__bar" data-progress-bar></div>
                        </div>
                        <span class="ps-tiny ps-muted" data-progress-text></span>
                    </div>
                </div>

                <div class="ps-notice">
                    <span class="ps-notice__icon"><?= ps_icon('shield', 18) ?></span>
                    <div class="ps-notice__text">
                        <strong>Checked on the server</strong>
                        <p>
                            File type and size are validated again by the backend when the
                            upload arrives, and the file is stored under a generated name.
                            Nothing is reported as uploaded until the server confirms it.
                        </p>
                    </div>
                </div>
            </div>

            <div class="ps-modal__foot">
                <button type="button" class="ps-btn ps-btn--secondary" data-modal-close>
                    Cancel
                </button>
                <button type="submit" class="ps-btn ps-btn--primary"
                        data-upload-submit data-loading-label="Uploading…">
                    Upload track
                </button>
            </div>

        </form>
    </div>
</div>

<?php if ($openUpload): ?>
    <script>
    /* The dashboard's "Upload Track" action deep-links here with ?action=upload. */
    window.addEventListener('DOMContentLoaded', function () {
        window.setTimeout(function () {
            var button = document.querySelector('[data-open-upload]');
            if (button) {
                button.click();
            }
        }, 260);
    });
    </script>
<?php endif; ?>

<?php
require __DIR__ . '/../../templates/artist-shell-end.php';