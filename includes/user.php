<?php

/**
 * Paradise Studios — current user helper.
 *
 * The session established by login.php stores the user ID, name and role. The
 * Profile page additionally needs the account email and creation date, which
 * are read once from the database.
 *
 * Security notes:
 *   - The result is cached per request.
 *   - The password hash is never selected or returned.
 *   - Identity always comes from the session, never from request parameters.
 */

require_once __DIR__ . '/auth.php';

if (!function_exists('ps_current_user')) {
    /**
     * @return array{id:int,name:string,email:string,role:string,created_at:string}|null
     */
    function ps_current_user(): ?array
    {
        static $user = false;

        if ($user !== false) {
            return $user ?: null;
        }

        $userId = currentUserId();

        if (!$userId) {
            $user = null;
            return null;
        }

        global $pdo;

        try {
            $stmt = $pdo->prepare(
                'SELECT id, name, email, role, created_at
                 FROM users
                 WHERE id = ?
                 LIMIT 1'
            );

            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            $user = $row ?: null;
        } catch (Throwable $e) {
            $user = null;
        }

        return $user;
    }
}

if (!function_exists('ps_artist_nav')) {
    /**
     * The artist navigation, mirroring ps_owner_nav().
     *
     * 'bottom' marks the entries shown in the phone bottom bar. The prototype
     * uses a five-slot bar with a raised circular centre action rather than a
     * flat six-item row, so 'buzz' opts out of the bar (it stays reachable from
     * the drawer, the dashboard cards and its own page) and 'bookings' is
     * promoted to the centre slot.
     *
     * @return array<int, array{key:string,label:string,short:string,icon:string,href:string,bottom:string|bool}>
     */
    function ps_artist_nav(): array
    {
        return [
            [
                'key'    => 'dashboard',
                'label'  => 'Dashboard',
                'short'  => 'Home',
                'icon'   => 'dashboard',
                'href'   => PS_BASE_URL . '/pages/artist/dashboard.php',
                'bottom' => true
            ],
            [
                'key'    => 'studios',
                'label'  => 'Discover',
                'short'  => 'Discover',
                'icon'   => 'search',
                'href'   => PS_BASE_URL . '/pages/artist/studios.php',
                'bottom' => true
            ],
            [
                'key'    => 'bookings',
                'label'  => 'My Bookings',
                'short'  => 'Book',
                'icon'   => 'bookings',
                'href'   => PS_BASE_URL . '/pages/artist/bookings.php',
                'bottom' => 'fab'
            ],
            [
                'key'    => 'tracks',
                'label'  => 'My Tracks',
                'short'  => 'Tracks',
                'icon'   => 'music',
                'href'   => PS_BASE_URL . '/pages/artist/tracks.php',
                'bottom' => true
            ],
            [
                'key'    => 'buzz',
                'label'  => 'Buzz XP',
                'short'  => 'Buzz',
                'icon'   => 'bolt',
                'href'   => PS_BASE_URL . '/pages/artist/buzz.php',
                'bottom' => false
            ],
            [
                'key'    => 'profile',
                'label'  => 'Profile',
                'short'  => 'Profile',
                'icon'   => 'profile',
                'href'   => PS_BASE_URL . '/pages/artist/profile.php',
                'bottom' => true
            ]
        ];
    }
}

if (!function_exists('ps_owner_nav')) {
    /**
     * The owner navigation, defined once for the sidebar, the mobile bottom
     * navigation and the active-state highlighting.
     *
     * @return array<int, array{key:string,label:string,icon:string,href:string}>
     */
    function ps_owner_nav(): array
    {
        return [
            [
                'key'   => 'dashboard',
                'label' => 'Dashboard',
                'icon'  => 'dashboard',
                'href'  => PS_BASE_URL . '/pages/owner/dashboard.php'
            ],
            [
                'key'   => 'studios',
                'label'  => 'My Studios',
                'icon'  => 'studios',
                'href'  => PS_BASE_URL . '/pages/owner/studios.php'
            ],
            [
                'key'   => 'bookings',
                'label' => 'Bookings',
                'icon'  => 'bookings',
                'href'  => PS_BASE_URL . '/pages/owner/bookings.php'
            ],
            [
                'key'   => 'analytics',
                'label' => 'Analytics',
                'icon'  => 'analytics',
                'href'  => PS_BASE_URL . '/pages/owner/analytics.php'
            ],
            [
                'key'   => 'profile',
                'label' => 'Profile',
                'icon'  => 'profile',
                'href'  => PS_BASE_URL . '/pages/owner/profile.php'
            ]
        ];
    }
}