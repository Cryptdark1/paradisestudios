<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/payments.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/money.php';

header('Content-Type: application/json');

requireRole('owner');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$ownerId = currentUserId();

try {

    /*
    |--------------------------------------------------------------------------
    | Booking count
    |--------------------------------------------------------------------------
    |
    | Count all bookings belonging to studios owned by this owner.
    |
    */

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM bookings b
        INNER JOIN studios s
            ON s.id = b.studio_id
        WHERE s.owner_id = ?
    ");

    $stmt->execute([$ownerId]);

    $bookingCount = (int) $stmt->fetchColumn();


    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(l.amount_minor), 0)
        FROM financial_ledger l
        INNER JOIN owner_payouts op ON op.booking_id = l.booking_id
        WHERE op.owner_id = ? AND l.entry_type = 'payment_captured'
    ");

    $stmt->execute([$ownerId]);

    $capturedGross = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(owner_net_minor - reversed_minor), 0)
        FROM owner_payouts
        WHERE owner_id = ? AND status IN ('pending_session', 'pending_delay')
    ");
    $stmt->execute([$ownerId]);
    $pendingEarnings = (int) $stmt->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | Peak booking hours
    |--------------------------------------------------------------------------
    |
    | Extract the starting hour from bookings and count how often
    | each hour is used.
    |
    */

    $stmt = $pdo->prepare("
        SELECT
            HOUR(b.start_time) AS booking_hour,
            COUNT(*) AS booking_count
        FROM bookings b
        INNER JOIN studios s
            ON s.id = b.studio_id
        WHERE s.owner_id = ?
        AND b.status NOT IN ('cancelled')
        GROUP BY HOUR(b.start_time)
        ORDER BY booking_count DESC, booking_hour ASC
    ");

    $stmt->execute([$ownerId]);

    $peakHours = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | Determine top peak hour
    |--------------------------------------------------------------------------
    */

    $topPeakHour = null;

    if (!empty($peakHours)) {
        $topPeakHour = [
            'hour' => (int) $peakHours[0]['booking_hour'],
            'booking_count' => (int) $peakHours[0]['booking_count']
        ];
    }


    echo json_encode([
        'success' => true,
        'data' => [
            'booking_count' => $bookingCount,
            'captured_gross' => ps_money_format($capturedGross, (string) ps_payment_config('currency')),
            'pending_earnings' => ps_money_format($pendingEarnings, (string) ps_payment_config('currency')),
            'peak_hours' => $peakHours,
            'top_peak_hour' => $topPeakHour
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve owner analytics.'
    ]);
}