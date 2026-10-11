<?php

/**
 * Paradise Studios — owner page guard.
 *
 * Every page under /pages/owner/ includes this file first. It reuses the
 * existing authentication architecture in includes/auth.php; no second
 * authentication system is introduced and role information is taken from the
 * session only, never from the request.
 *
 * Usage:
 *     require_once __DIR__ . '/../../includes/owner-guard.php';
 */

/*
 * The database connection is needed as well: the shared layout reads the
 * signed-in owner's name, email and account date for the topbar and profile.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/user.php';

/**
 * Root URL of the application. Matches the paths already used by login.php
 * and logout.php, and is defined once so it can be changed in a single place.
 */
if (!defined('PS_BASE_URL')) {
    define('PS_BASE_URL', '/paradisestudios');
}

/* Unauthenticated visitors are sent to the sign-in page. */
requireLogin();

/*
|--------------------------------------------------------------------------
| Authorisation
|--------------------------------------------------------------------------
|
| The backend APIs enforce ownership server-side. This guard simply avoids
| rendering the owner interface to a user who cannot use it, sending them to
| the dashboard with an explanatory notice instead of a raw 403 page.
|
*/
if (currentUserRole() !== 'owner') {
    /* The parameter names the interface that was refused, not the role that
       was turned away. See includes/artist-guard.php for the counterpart. */
    redirect(PS_BASE_URL . '/dashboard.php?denied=owner');
}