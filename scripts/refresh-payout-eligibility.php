<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/payments.php';
require_once __DIR__ . '/../includes/payments/FinancialLedger.php';
require_once __DIR__ . '/../includes/payments/PayoutService.php';

$statement = $pdo->query("
    SELECT booking_id FROM owner_payouts
    WHERE status IN ('pending_session', 'pending_delay', 'eligible')
    ORDER BY booking_id
");
$service = new PayoutService($pdo, new FinancialLedger($pdo));
$processed = 0;
foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $bookingId) {
    $service->evaluateBooking((int) $bookingId);
    $processed++;
}
fwrite(STDOUT, 'Refreshed payout eligibility for ' . $processed . " earnings.\n");
