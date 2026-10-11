<?php

/**
 * Paradise Studios — owner layout (opening).
 *
 * Renders the shared chrome for every owner page: document head, sidebar,
 * topbar, bottom navigation and the opening of the page container.
 *
 * Expected variables (set by the including page before this require):
 *   $ownerPage    string  navigation key: dashboard|studios|bookings|analytics|profile
 *   $pageHeading  string  text shown in the topbar
 *   $pageStyles   array   extra stylesheet file names inside public/css
 *   $ownerUser    array   result of ps_current_user()
 */

if (!defined('PS_BASE_URL')) {
    require_once __DIR__ . '/../includes/owner-guard.php';
}

require_once __DIR__ . '/../includes/user.php';

$ownerUser = $ownerUser ?? ps_current_user();
$ownerNav  = ps_owner_nav();

$pageStyles  = $pageStyles ?? [];
$pageHeading = $pageHeading ?? 'Dashboard';

/**
 * Cache-busted URL for a file inside public/.
 */
if (!function_exists('ps_asset')) {
    function ps_asset(string $relative): string
    {
        $path = __DIR__ . '/../public/' . ltrim($relative, '/');
        $version = is_file($path) ? (string) filemtime($path) : '1';

        return PS_BASE_URL . '/public/' . ltrim($relative, '/') . '?v=' . $version;
    }
}

/**
 * Font stack: the design specifies Sora (display) and Inter (body). If the
 * webfont request fails the CSS falls back to system fonts.
 */
$psFonts = 'https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap';

$ownerInitials = $ownerUser ? substr((string) $ownerUser['name'], 0, 1) : 'P';

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="Paradise Studios — studio owner dashboard.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(ps_csrf_token()) ?>">

    <title><?= e($pageHeading) ?> · Paradise Studios</title>

    <script>
    /*
     * Resolve the stored theme BEFORE any stylesheet is parsed so the correct
     * palette is painted immediately and no flash of the wrong theme occurs.
     */
    (function () {
        var theme = 'light';
        try {
            var stored = window.localStorage.getItem('paradise-theme');
            if (stored === 'light' || stored === 'dark') {
                theme = stored;
            } else if (window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                theme = 'dark';
            }
        } catch (e) {
            theme = 'light';
        }
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= e($psFonts) ?>">

    <link rel="stylesheet" href="<?= e(ps_asset('css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(ps_asset('css/app.css')) ?>">
    <?php foreach ($pageStyles as $style): ?>
        <link rel="stylesheet" href="<?= e(ps_asset('css/' . $style)) ?>">
    <?php endforeach; ?>
</head>

<body>
<a class="ps-skip-link" href="#main-content">Skip to main content</a>

<div class="ps-shell" data-shell>

    <!-- ============================ SIDEBAR ============================ -->
    <aside class="ps-sidebar" id="ps-sidebar" data-sidebar aria-label="Owner navigation">
        <div class="ps-brand">
            <span class="ps-brand__mark" aria-hidden="true"><?= ps_icon('music', 19) ?></span>
            <span class="ps-brand__name">
                <span>Paradise</span>
                <span>Studios</span>
            </span>
        </div>

        <nav class="ps-nav">
            <p class="ps-nav__section" id="ps-nav-workspace">Workspace</p>

            <ul aria-labelledby="ps-nav-workspace">
                <?php foreach ($ownerNav as $item): ?>
                    <?php $isActive = ($item['key'] === ($ownerPage ?? '')); ?>
                    <li>
                        <a class="ps-nav__link<?= $isActive ? ' is-active' : '' ?>"
                           href="<?= e($item['href']) ?>"
                            <?= $isActive ? 'aria-current="page"' : '' ?>>
                            <?= ps_icon($item['icon'], 18) ?>
                            <span class="ps-nav__label"><?= e($item['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="ps-brand ps-brand--foot">
            <span class="ps-brand__mark" aria-hidden="true"><?= ps_icon('music', 17) ?></span>
            <span class="ps-brand__name">
                <span>Paradise</span>
                <span>Studios</span>
            </span>
        </div>
    </aside>

    <button type="button" class="ps-scrim" data-scrim aria-hidden="true" tabindex="-1"></button>

    <!-- ============================= MAIN ============================== -->
    <div class="ps-main">

        <header class="ps-topbar">
            <button type="button"
                    class="ps-iconbtn ps-topbar__burger"
                    data-nav-toggle
                    aria-controls="ps-sidebar"
                    aria-label="Open navigation menu"
                    aria-expanded="false">
                <?= ps_icon('menu', 20) ?>
            </button>

            <h1 class="ps-topbar__title"><?= e($pageHeading) ?></h1>

            <div class="ps-topbar__actions">

                <!--
                    The single theme toggle for the whole application.
                    It always renders the mode the user will switch TO.
                    public/js/theme.js fills in the glyph and label.
                -->
                <button type="button"
                        class="ps-theme-toggle"
                        data-theme-toggle
                        data-next-theme="dark"
                        aria-label="Switch theme"
                        title="Switch theme">
                    &#127769;
                </button>

                <div class="ps-usermenu" data-usermenu>
                    <button type="button"
                            class="ps-usermenu__trigger"
                            data-usermenu-trigger
                            aria-haspopup="true"
                            aria-expanded="false">
                        <span class="ps-avatar ps-avatar--sm" aria-hidden="true"><?= e($ownerInitials) ?></span>
                        <span class="ps-usermenu__meta">
                            <strong><?= e($ownerUser['name'] ?? 'Account') ?></strong>
                            <small><?= e($ownerUser['role'] ?? 'owner') ?></small>
                        </span>
                        <?= ps_icon('chevron-down', 16) ?>
                    </button>

                    <div class="ps-menu ps-menu--right" data-usermenu-menu role="menu" hidden>
                        <div class="ps-menu__header">
                            <strong><?= e($ownerUser['name'] ?? 'Account') ?></strong>
                            <small><?= e($ownerUser['email'] ?? '') ?></small>
                        </div>

                        <a class="ps-menu__item" role="menuitem"
                           href="<?= e(PS_BASE_URL . '/pages/owner/profile.php') ?>">
                            <?= ps_icon('profile', 17) ?>
                            <span>My profile</span>
                        </a>

                        <a class="ps-menu__item" role="menuitem"
                           href="<?= e(PS_BASE_URL . '/pages/owner/studios.php') ?>">
                            <?= ps_icon('studios', 17) ?>
                            <span>My studios</span>
                            <span class="ps-menu__role">Owner</span>
                        </a>

                        <div class="ps-menu__sep" role="separator"></div>

                        <form method="POST" action="<?= e(PS_BASE_URL . '/logout.php') ?>">
                            <input type="hidden" name="_csrf_token" value="<?= e(ps_csrf_token()) ?>">
                            <button class="ps-menu__item ps-menu__item--danger" type="submit" role="menuitem">
                                <?= ps_icon('logout', 17) ?>
                                <span>Log out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="ps-content" id="main-content">
            <div class="ps-page">