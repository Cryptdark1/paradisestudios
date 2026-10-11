<?php

/**
 * Paradise Studios — Artist profile and settings.
 *
 * Reads the authenticated account server-side and saves changes through
 * PUT /api/artist-update-profile.php.
 *
 * What is editable, and why:
 *   name  — writable; the backend validates length
 *   email — writable; the backend validates format and uniqueness
 *
 * What is deliberately NOT editable here:
 *   role       — privilege escalation; rejected server-side as well
 *   buzz XP    — awarded only by the server (booking completion, track release)
 *   user ID    — identity comes from the session, never from the browser
 *   password   — no password-change endpoint exists in this backend
 *
 * api/owner-update-profile.php is owner-authorized and therefore not used here;
 * the artist has a dedicated endpoint instead.
 */

require_once __DIR__ . '/../../includes/artist-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$artistUser = ps_current_user();

/* Nothing to show if the session lookup failed. */
if (!$artistUser) {
    ps_clear_authentication_state();
    redirect(PS_BASE_URL . '/login.php');
}

/*
 * XP is read live from the artist row instead of reusing anything cached in the
 * session, so this page always agrees with api/buzz.php and the dashboard after
 * a booking completion or track release.
 */
$artistXp = 0;

try {
    $psXpStmt = $pdo->prepare('SELECT buzz_level FROM users WHERE id = ? LIMIT 1');
    $psXpStmt->execute([$artistUser['id']]);
    $artistXp = (int) ($psXpStmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $artistXp = 0;
}

$artistPage   = 'profile';
$pageHeading  = 'Profile';
$pageStyles   = ['artist-profile.css'];
$pageScripts  = ['js/artist-profile.js'];

require __DIR__ . '/../../templates/artist-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>Profile</h1>
        <p>Manage your artist account and preferences.</p>
    </div>
</section>

<!-- ============================== PROFILE ============================== -->
<section class="ps-grid ps-grid--main-side">

    <!-- Identity card -->
    <article class="ps-card ps-profile">
        <div class="ps-card__body">
            <div class="ps-profile__head">
                <span class="ps-avatar ps-avatar--xl ps-avatar--artist" aria-hidden="true">
                    <?= e(strtoupper(substr((string) $artistUser['name'], 0, 1))) ?>
                </span>

                <div class="ps-profile__id">
                    <h2 class="ps-profile__name" data-profile-name>
                        <?= e($artistUser['name']) ?>
                    </h2>
                    <p class="ps-profile__email" data-profile-email>
                        <?= e($artistUser['email']) ?>
                    </p>

                    <div class="ps-profile__badges">
                        <span class="ps-badge ps-badge--solid ps-badge--warning">
                            <?= ps_icon('music', 12) ?>
                            <span>Artist</span>
                        </span>

                        <span class="ps-badge ps-badge--neutral">
                            <?= ps_icon('bolt', 12) ?>
                            <span data-profile-xp>
                                <?= e(number_format($artistXp)) ?> XP
                            </span>
                        </span>

                        <?php if (!empty($artistUser['created_at'])): ?>
                            <span class="ps-badge ps-badge--neutral">
                                <?= ps_icon('clock', 12) ?>
                                <span>Since <?= e(date('M Y', strtotime((string) $artistUser['created_at']))) ?></span>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ps-divider"></div>

            <h3 class="ps-profile__section-title">Account information</h3>

            <dl class="ps-dl ps-dl--stacked">
                <div class="ps-dl__row">
                    <dt>Full name</dt>
                    <dd data-row-name><?= e($artistUser['name']) ?></dd>
                </div>
                <div class="ps-dl__row">
                    <dt>Email address</dt>
                    <dd data-row-email><?= e($artistUser['email']) ?></dd>
                </div>
                <div class="ps-dl__row">
                    <dt>Account role</dt>
                    <dd>Artist</dd>
                </div>
                <div class="ps-dl__row">
                    <dt>Artist ID</dt>
                    <dd class="ps-num">#<?= e($artistUser['id']) ?></dd>
                </div>
                <?php if (!empty($artistUser['created_at'])): ?>
                    <div class="ps-dl__row">
                        <dt>Registered</dt>
                        <dd><?= e(date('j F Y', strtotime((string) $artistUser['created_at']))) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <div class="ps-card__foot">
            <form method="POST" action="<?= e(PS_BASE_URL . '/logout.php') ?>">
                <input type="hidden" name="_csrf_token" value="<?= e(ps_csrf_token()) ?>">
                <button class="ps-btn ps-btn--secondary ps-btn--block" type="submit">
                    <?= ps_icon('logout', 17) ?>
                    <span>Log out of Paradise Studios</span>
                </button>
            </form>
        </div>
    </article>

    <!-- Edit form -->
    <article class="ps-card">
        <div class="ps-card__head">
            <h2>Edit details</h2>
        </div>

        <form class="ps-card__body ps-profile__form" data-profile-form novalidate>

            <div class="ps-form-error" data-form-error role="alert"></div>

            <div class="ps-field">
                <label class="ps-field__label" for="profile-name">
                    Full name <span class="ps-req" aria-hidden="true">*</span>
                </label>
                <input type="text"
                       id="profile-name"
                       name="name"
                       class="ps-input"
                       maxlength="100"
                       required
                       autocomplete="name"
                       value="<?= e($artistUser['name']) ?>"
                       aria-describedby="profile-name-error">
                <span class="ps-field__error" id="profile-name-error" data-field-error></span>
            </div>

            <div class="ps-field">
                <label class="ps-field__label" for="profile-email">
                    Email address <span class="ps-req" aria-hidden="true">*</span>
                </label>
                <input type="email"
                       id="profile-email"
                       name="email"
                       class="ps-input"
                       maxlength="100"
                       required
                       autocomplete="email"
                       value="<?= e($artistUser['email']) ?>"
                       aria-describedby="profile-email-hint profile-email-error">
                <span class="ps-field__hint" id="profile-email-hint">
                    This is the address you sign in with.
                </span>
                <span class="ps-field__error" id="profile-email-error" data-field-error></span>
            </div>

            <!-- Read-only: these are managed by the platform. -->
            <div class="ps-field">
                <label class="ps-field__label" for="profile-role">Account role</label>
                <input type="text" id="profile-role" class="ps-input" value="Artist" disabled
                       aria-describedby="profile-role-hint">
                <span class="ps-field__hint" id="profile-role-hint">
                    Roles cannot be changed from this page.
                </span>
            </div>

            <div class="ps-field">
                <label class="ps-field__label" for="profile-xp">Buzz XP</label>
                <input type="text" id="profile-xp" class="ps-input" disabled
                       value="<?= e(number_format($artistXp)) ?> XP"
                       aria-describedby="profile-xp-hint">
                <span class="ps-field__hint" id="profile-xp-hint">
                    XP is awarded by the server when you complete a session or release a track.
                </span>
            </div>

            <div class="ps-profile__actions">
                <button type="button" class="ps-btn ps-btn--ghost" data-profile-reset>
                    Discard changes
                </button>
                <button type="submit" class="ps-btn ps-btn--primary"
                        data-profile-submit data-loading-label="Saving…">
                    Save changes
                </button>
            </div>
        </form>
    </article>

</section>

<!-- ============================= SETTINGS ============================= -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Settings</h2>
    </div>

    <div class="ps-card__body">
        <!-- Theme preference is a real, working control handled by theme.js. -->
        <div class="ps-setting">
            <span class="ps-setting__icon"><?= ps_icon('settings', 18) ?></span>

            <div class="ps-setting__text">
                <strong>Appearance</strong>
                <p>
                    Switch between the light and dark theme. Your choice is saved
                    on this device and carries across every page.
                </p>
            </div>

            <?php
            /*
             * Deliberately NOT a second theme toggle: the product must have
             * exactly one. This row only reports the current state and points
             * at the single control in the topbar.
             */
            ?>
            <span class="ps-setting__value">
                <span class="ps-badge ps-badge--neutral" data-theme-state>
                    Light mode
                </span>
                <span class="ps-tiny ps-muted" data-theme-hint>
                    Use the toggle in the top bar
                </span>
            </span>
        </div>
    </div>
</section>

<!-- ============================= SECURITY ============================= -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Security &amp; account</h2>
    </div>

    <div class="ps-card__body">
        <div class="ps-notice">
            <span class="ps-notice__icon"><?= ps_icon('lock', 18) ?></span>
            <div class="ps-notice__text">
                <strong>Password changes are not available yet</strong>
                <p>
                    The current backend has no password-change or password-reset
                    endpoint, so this is deliberately not offered here rather than
                    presented as a control that cannot work. It would need a
                    server-side endpoint that verifies the current password,
                    re-hashes the new one, and invalidates other sessions.
                </p>
            </div>
        </div>
    </div>
</section>

<?php
require __DIR__ . '/../../templates/artist-shell-end.php';