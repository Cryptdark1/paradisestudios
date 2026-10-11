<?php

if (!function_exists('UI_state_html')) {
    /**
     * Render a shared empty/error state from PHP.
     *
     * The artist pages occasionally need a state before JavaScript runs (for
     * example when a required query parameter is missing). This reuses the same
     * markup shape as UI.stateHtml() in public/js/ui.js so server-rendered and
     * client-rendered states look identical.
     *
     * @param string $title
     * @param string $message
     * @param string $icon   icon key from includes/icons.php
     */
    function UI_state_html(string $title, string $message, string $icon = 'info'): string
    {
        return '<div class="ps-state">'
            . '<div class="ps-state__icon">' . ps_icon($icon, 24) . '</div>'
            . '<div class="ps-state__title">' . e($title) . '</div>'
            . '<div class="ps-state__msg">' . e($message) . '</div>'
            . '</div>';
    }
}

/**
 * Paradise Studios — artist page guard.
 *
 * Every page under /pages/artist/ includes this file first. It reuses the
 * existing authentication architecture in includes/auth.php; no second
 * authentication system is introduced and the role is taken from the session
 * only, never from the request.
 *
 * Mirrors includes/owner-guard.php so both frontends behave identically:
 *   - not signed in          -> login page
 *   - signed in, wrong role  -> the dashboard with an explanatory notice
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/user.php';

if (!defined('PS_BASE_URL')) {
    define('PS_BASE_URL', '/paradisestudios');
}

requireLogin();

if (currentUserRole() !== 'artist') {
    /*
     * `denied` names the interface that was refused, so the destination page can
     * explain it accurately:
     *   denied=owner  -> an artist tried to open the studio owner area
     *   denied=artist -> anyone else tried to open the artist area
     */
    $deniedFor = currentUserRole() === 'owner' ? 'owner' : 'artist';

    redirect(PS_BASE_URL . '/dashboard.php?denied=' . $deniedFor);
}