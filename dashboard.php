<?php

/**
 * Paradise Studios — entry router.
 *
 * Owners are forwarded to the new owner interface. Artists and admins keep
 * the original placeholder pages so nothing that worked before is lost.
 *
 * Query parameters:
 *   ?denied=owner  an owner-only page was requested by a non-owner
 *   ?expired=1     the session ended and the user was returned here
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/icons.php';

requireLogin();

/*
|--------------------------------------------------------------------------
| Route each role to its own dashboard
|--------------------------------------------------------------------------
|
| Owners land in the Owner Frontend, artists in the Artist Frontend. The
| `denied` parameter is preserved so the target page can explain why the user
| was redirected.
|
*/
$homeForRole = [
    'owner'  => '/paradisestudios/pages/owner/dashboard.php',
    'artist' => '/paradisestudios/pages/artist/dashboard.php'
];

if (isset($homeForRole[$_SESSION['user_role']])) {
    $params = [];

    foreach (['denied', 'expired'] as $key) {
        if (isset($_GET[$key])) {
            $params[$key] = $_GET[$key];
        }
    }

    $query = $params ? '?' . http_build_query($params) : '';

    redirect($homeForRole[$_SESSION['user_role']] . $query);
}

/*
|--------------------------------------------------------------------------
| Artist / admin — original placeholder views
|--------------------------------------------------------------------------
*/
$notice = '';

if (isset($_GET['expired'])) {
    $notice = 'Your session has ended. Please sign in again.';
}

/*
 * `denied` names the interface the user tried to open. Each role is forwarded to
 * its own dashboard, so the notice is shown there.
 */
if (isset($_GET['denied'])) {
    $notice = $_GET['denied'] === 'owner'
        ? 'That page is part of the studio owner area, which your account cannot access.'
        : 'That page is part of the artist area, which your account cannot access.';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Paradise Studios</title>
</head>

<body>

    <h1>Paradise Studios</h1>

    <h2>Welcome, <?= e($_SESSION['user_name']) ?>!</h2>

    <p>
        Role:
        <strong><?= e($_SESSION['user_role']) ?></strong>
    </p>

    <?php if ($notice !== ''): ?>
        <p style="color: #a76a08;">
            <?= e($notice) ?>
        </p>
    <?php endif; ?>

    <hr>

    <?php if (currentUserRole() === 'artist'): ?>

        <h3>Artist Dashboard</h3>

        <ul>
            <li>Find Studios</li>
            <li>Book a Session</li>
            <li>My Bookings</li>
            <li>My Tracks</li>
            <li>My Buzz Meter</li>
        </ul>

    <?php elseif (currentUserRole() === 'admin'): ?>

        <h3>Admin Dashboard</h3>

        <ul>
            <li>Users</li>
            <li>Studios</li>
            <li>Bookings</li>
            <li>System Overview</li>
        </ul>

    <?php endif; ?>

    <div>
        <form method="POST" action="/paradisestudios/logout.php">
            <input type="hidden" name="_csrf_token" value="<?= e(ps_csrf_token()) ?>">
            <button type="submit">Log out</button>
        </form>
    </div>

</body>
</html>