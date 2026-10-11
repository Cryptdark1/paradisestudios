<?php

/**
 * Paradise Studios — Owner profile.
 *
 * Shows the authenticated account (read from the session + database) and
 * allows the owner to update their own name and email through
 * PUT /api/owner-update-profile.php.
 *
 * The password hash is never selected, and the user ID always comes from the
 * session, so nothing here can be used to edit another account.
 */

require_once __DIR__ . '/../../includes/owner-guard.php';
require_once __DIR__ . '/../../includes/user.php';

$ownerUser = ps_current_user();

/* If the session lookup failed there is nothing to display. */
if (!$ownerUser) {
    ps_clear_authentication_state();
    redirect(PS_BASE_URL . '/login.php');
}

$ownerPage   = 'profile';
$pageHeading = 'Profile';
$pageStyles  = ['owner-profile.css'];

$pageScripts = ['js/owner-profile.js'];

$initials = strtoupper(substr((string) $ownerUser['name'], 0, 1));
$roleLabel = ucfirst((string) $ownerUser['role']);

require __DIR__ . '/../../templates/owner-shell-start.php';

?>

<!-- =========================== PAGE HEADER =========================== -->
<section class="ps-pagehead">
    <div class="ps-pagehead__text">
        <h1>Profile</h1>
        <p>Manage the account you use to sign in to Paradise Studios.</p>
    </div>
</section>

<!-- ============================= PROFILE ============================= -->
<section class="ps-grid ps-grid--main-side">

    <!-- Identity card (server rendered from real session data) -->
    <article class="ps-card ps-profile">
        <div class="ps-card__body">
            <div class="ps-profile__head">
                <span class="ps-avatar ps-avatar--xl" aria-hidden="true"><?= e($initials) ?></span>

                <div class="ps-profile__id">
                    <h2 class="ps-profile__name"><?= e($ownerUser['name']) ?></h2>
                    <p class="ps-profile__email"><?= e($ownerUser['email']) ?></p>

                    <div class="ps-profile__badges">
                        <span class="ps-badge ps-badge--solid ps-badge--warning">
                            <?= ps_icon('badge-check', 12) ?>
                            <span><?= e($roleLabel) ?></span>
                        </span>

                        <?php if (!empty($ownerUser['created_at'])): ?>
                            <span class="ps-badge ps-badge--neutral">
                                <?= ps_icon('clock', 12) ?>
                                <span>Member since <?= e(date('M Y', strtotime((string) $ownerUser['created_at']))) ?></span>
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
                    <dd><?= e($ownerUser['name']) ?></dd>
                </div>
                <div class="ps-dl__row">
                    <dt>Email address</dt>
                    <dd><?= e($ownerUser['email']) ?></dd>
                </div>
                <div class="ps-dl__row">
                    <dt>Account role</dt>
                    <dd><?= e($roleLabel) ?></dd>
                </div>
                <div class="ps-dl__row">
                    <dt>User ID</dt>
                    <dd class="ps-num">#<?= e($ownerUser['id']) ?></dd>
                </div>
                <?php if (!empty($ownerUser['created_at'])): ?>
                    <div class="ps-dl__row">
                        <dt>Registered</dt>
                        <dd><?= e(date('j F Y', strtotime((string) $ownerUser['created_at']))) ?></dd>
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
                       value="<?= e($ownerUser['name']) ?>"
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
                       value="<?= e($ownerUser['email']) ?>"
                       aria-describedby="profile-email-hint profile-email-error">
                <span class="ps-field__hint" id="profile-email-hint">
                    This is the address you sign in with.
                </span>
                <span class="ps-field__error" id="profile-email-error" data-field-error></span>
            </div>

            <div class="ps-field">
                <label class="ps-field__label" for="profile-role">Account role</label>
                <input type="text"
                       id="profile-role"
                       class="ps-input"
                       value="<?= e($roleLabel) ?>"
                       disabled
                       aria-describedby="profile-role-hint">
                <span class="ps-field__hint" id="profile-role-hint">
                    Roles are managed by the platform and cannot be changed here.
                </span>
            </div>

            <div class="ps-profile__actions">
                <button type="button" class="ps-btn ps-btn--ghost" data-profile-reset>
                    Discard changes
                </button>
                <button type="submit"
                        class="ps-btn ps-btn--primary"
                        data-profile-submit
                        data-loading-label="Saving…">
                    Save changes
                </button>
            </div>
        </form>
    </article>

</section>

<!-- ============================ SECURITY ============================ -->
<section class="ps-card">
    <div class="ps-card__head">
        <h2>Security</h2>
    </div>

    <div class="ps-card__body">
        <div class="ps-notice">
            <span class="ps-notice__icon"><?= ps_icon('lock', 18) ?></span>
            <div class="ps-notice__text">
                <strong>Password changes are handled separately</strong>
                <p>
                    Updating your password, your role or other account credentials is
                    intentionally not part of this page &mdash; those actions require
                    re-authentication and are not exposed by the current backend.
                </p>
            </div>
        </div>
    </div>
</section>

<?php
require __DIR__ . '/../../templates/owner-shell-end.php';