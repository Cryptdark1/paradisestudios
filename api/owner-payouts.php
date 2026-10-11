<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/payments.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments/ProviderFactory.php';
require_once __DIR__ . '/../includes/payments/FinancialLedger.php';
require_once __DIR__ . '/../includes/payments/PayoutService.php';
require_once __DIR__ . '/../includes/payments/OwnerTransferService.php';

header('Content-Type: application/json; charset=utf-8');
requireRole('owner');

function ps_owner_payouts_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $provider = ps_payment_provider();
    if (!$provider instanceof PayoutProviderInterface) {
        ps_owner_payouts_response(503, ['success' => false, 'message' => 'Payout provider is unavailable.']);
    }
    $service = new OwnerTransferService($pdo, $provider, new FinancialLedger($pdo));
    $ownerId = (int) currentUserId();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));

    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? 'summary');
        if ($action === 'banks') {
            $currency = strtoupper((string) ($_GET['currency'] ?? ps_payment_config('currency')));
            ps_owner_payouts_response(200, ['success' => true, 'data' => $service->banks($currency)]);
        }
        if ($action !== 'summary') {
            ps_owner_payouts_response(400, ['success' => false, 'message' => 'Unknown action.']);
        }

        $payoutService = new PayoutService($pdo, new FinancialLedger($pdo));
        $payoutService->evaluateForOwner($ownerId);
        $summary = $payoutService->summaryForOwner($ownerId);
        $eligible = $pdo->prepare("
            SELECT id AS payout_id, booking_id, currency,
                   GREATEST(owner_net_minor - reversed_minor, 0) AS amount_minor, eligible_at
            FROM owner_payouts WHERE owner_id = ? AND status = 'eligible'
            ORDER BY eligible_at ASC
        ");
        $eligible->execute([$ownerId]);
        $eligibleRows = $eligible->fetchAll(PDO::FETCH_ASSOC);
        foreach ($eligibleRows as &$earning) {
            $earning['amount_display'] = ps_money_format(
                (int) $earning['amount_minor'],
                (string) $earning['currency']
            );
        }
        unset($earning);
        $attempts = $pdo->prepare('
            SELECT id, payout_id, reference, amount_minor, currency, status,
                   provider_status, failure_reason, created_at, updated_at
            FROM owner_transfer_attempts WHERE owner_id = ?
            ORDER BY id DESC LIMIT 100
        ');
        $attempts->execute([$ownerId]);
        ps_owner_payouts_response(200, [
            'success' => true,
            'data' => [
                'account' => $service->payoutAccount($ownerId),
                'summary' => $summary,
                'eligible' => $eligibleRows,
                'attempts' => $attempts->fetchAll(PDO::FETCH_ASSOC),
            ],
        ]);
    }

    if ($method !== 'POST') {
        ps_owner_payouts_response(405, ['success' => false, 'message' => 'Method not allowed.']);
    }
    ps_require_api_csrf_token();

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        ps_owner_payouts_response(400, ['success' => false, 'message' => 'Invalid JSON request.']);
    }
    $action = is_string($input['action'] ?? null) ? $input['action'] : '';

    if ($action === 'register_account') {
        $result = $service->registerAccount(
            $ownerId,
            is_string($input['account_number'] ?? null) ? trim($input['account_number']) : '',
            is_string($input['bank_code'] ?? null) ? trim($input['bank_code']) : '',
            is_string($input['bank_name'] ?? null) ? trim($input['bank_name']) : '',
            (string) ps_payment_config('currency')
        );
    } elseif ($action === 'request_payout') {
        $payoutId = filter_var($input['payout_id'] ?? null, FILTER_VALIDATE_INT);
        if ($payoutId === false || $payoutId <= 0) {
            ps_owner_payouts_response(400, ['success' => false, 'message' => 'A valid payout ID is required.']);
        }
        $result = $service->requestPayout($ownerId, (int) $payoutId);
    } elseif ($action === 'reconcile') {
        $attemptId = filter_var($input['attempt_id'] ?? null, FILTER_VALIDATE_INT);
        if ($attemptId === false || $attemptId <= 0) {
            ps_owner_payouts_response(400, ['success' => false, 'message' => 'A valid transfer attempt ID is required.']);
        }
        $result = $service->reconcile($ownerId, (int) $attemptId);
    } else {
        ps_owner_payouts_response(400, ['success' => false, 'message' => 'Unknown action.']);
    }

    ps_owner_payouts_response(200, ['success' => true, 'data' => $result]);
} catch (PaymentPendingException $e) {
    ps_owner_payouts_response(202, ['success' => false, 'pending' => true, 'message' => $e->getMessage()]);
} catch (PaymentValidationException $e) {
    ps_owner_payouts_response(422, ['success' => false, 'message' => $e->getMessage()]);
} catch (PaymentProviderException $e) {
    ps_owner_payouts_response($e->httpStatus(), ['success' => false, 'message' => 'Payout provider request failed.']);
} catch (Throwable $e) {
    error_log('[paradisestudios] owner payout API error: ' . get_class($e) . ' ' . $e->getMessage());
    ps_owner_payouts_response(500, ['success' => false, 'message' => 'Unable to process payout request.']);
}
