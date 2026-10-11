<?php

require_once __DIR__ . '/PayoutProviderInterface.php';
require_once __DIR__ . '/PayoutService.php';
require_once __DIR__ . '/FinancialLedger.php';
require_once __DIR__ . '/exceptions.php';
require_once __DIR__ . '/exceptions.php';

final class OwnerTransferService
{
    public function __construct(
        private PDO $pdo,
        private PayoutProviderInterface $provider,
        private FinancialLedger $ledger
    ) {
    }

    public function banks(string $currency): array
    {
        return $this->provider->banks($currency);
    }

    public function registerAccount(
        int $ownerId,
        string $accountNumber,
        string $bankCode,
        string $bankName,
        string $currency
    ): array {
        if (preg_match('/^\d{10}$/D', $accountNumber) !== 1 || $bankCode === '' || $bankName === '') {
            throw new PaymentValidationException('Provide a valid 10-digit account number and bank.');
        }
        if (!in_array(strtoupper($currency), ['NGN'], true)) {
            throw new PaymentValidationException('Owner payouts are not configured for this currency.');
        }

        $resolved = $this->provider->resolveBankAccount($accountNumber, $bankCode, $currency);
        if (!hash_equals($accountNumber, (string) $resolved['account_number'])) {
            throw new PaymentVerificationException('Verified bank account did not match the submitted account.');
        }
        $recipient = $this->provider->createTransferRecipient(
            (string) $resolved['account_name'],
            $accountNumber,
            $bankCode,
            $currency
        );

        $this->pdo->prepare('
            INSERT INTO owner_payout_accounts
                (owner_id, provider, recipient_code, bank_code, bank_name,
                 account_name, account_last4, currency, verified_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                recipient_code = VALUES(recipient_code),
                bank_code = VALUES(bank_code),
                bank_name = VALUES(bank_name),
                account_name = VALUES(account_name),
                account_last4 = VALUES(account_last4),
                currency = VALUES(currency),
                verified_at = UTC_TIMESTAMP()
        ')->execute([
            $ownerId,
            $this->provider->name(),
            $recipient['recipient_code'],
            $bankCode,
            $bankName,
            $resolved['account_name'],
            substr($accountNumber, -4),
            strtoupper($currency),
        ]);

        return [
            'bank_name' => $bankName,
            'account_name' => $resolved['account_name'],
            'account_last4' => substr($accountNumber, -4),
            'currency' => strtoupper($currency),
        ];
    }

    public function payoutAccount(int $ownerId): ?array
    {
        $statement = $this->pdo->prepare('
            SELECT bank_name, account_name, account_last4, currency, verified_at
            FROM owner_payout_accounts WHERE owner_id = ? LIMIT 1
        ');
        $statement->execute([$ownerId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        return $account ?: null;
    }

    public function requestPayout(int $ownerId, int $payoutId): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('
                SELECT op.*, a.recipient_code, a.provider AS account_provider
                FROM owner_payouts op
                INNER JOIN owner_payout_accounts a ON a.owner_id = op.owner_id
                WHERE op.id = ? AND op.owner_id = ?
                FOR UPDATE
            ');
            $statement->execute([$payoutId, $ownerId]);
            $payout = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$payout) {
                throw new PaymentValidationException('Eligible payout or verified bank account was not found.');
            }
            if ($payout['status'] !== 'eligible') {
                throw new PaymentValidationException('This earning is not currently eligible for payout.');
            }
            if ($payout['account_provider'] !== $this->provider->name()) {
                throw new PaymentValidationException('The verified payout account belongs to a different provider.');
            }

            $active = $this->pdo->prepare("
                SELECT id FROM owner_transfer_attempts
                WHERE payout_id = ? AND status IN ('submitting', 'pending', 'unknown')
                LIMIT 1
            ");
            $active->execute([$payoutId]);
            if ($active->fetchColumn()) {
                throw new PaymentPendingException('An existing transfer attempt must be reconciled first.', 'unknown');
            }

            $attemptNumber = (int) $this->pdo->query(
                'SELECT COALESCE(MAX(id), 0) + 1 FROM owner_transfer_attempts'
            )->fetchColumn();
            $reference = 'ps_owner_' . $payoutId . '_' . $attemptNumber . '_' . bin2hex(random_bytes(8));
            $amountMinor = max(0, (int) $payout['owner_net_minor'] - (int) $payout['reversed_minor']);
            if ($amountMinor <= 0) {
                throw new PaymentValidationException('There is no payable balance for this earning.');
            }

            $this->pdo->prepare("
                INSERT INTO owner_transfer_attempts
                    (payout_id, owner_id, provider, reference, recipient_code,
                     amount_minor, currency, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'submitting')
            ")->execute([
                $payoutId,
                $ownerId,
                $this->provider->name(),
                $reference,
                (string) $payout['recipient_code'],
                $amountMinor,
                $payout['currency'],
            ]);
            $attemptId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            $transfer = $this->provider->initiateTransfer(
                $reference,
                $amountMinor,
                (string) $payout['currency'],
                (string) $payout['recipient_code'],
                'Paradise Studios booking payout ' . (int) $payout['booking_id']
            );
            return $this->applyProviderResult(
                $attemptId,
                $transfer,
                $amountMinor,
                (string) $payout['currency']
            );
        } catch (Throwable $e) {
            $definitiveFailure = $e instanceof PaymentProviderException
                && $e->safeCode() === 'provider_rejected'
                && $e->httpStatus() >= 400
                && $e->httpStatus() < 500;
            $this->pdo->prepare("
                UPDATE owner_transfer_attempts
                SET status = ?, provider_status = ?, failure_reason = ?
                WHERE id = ? AND status = 'submitting'
            ")->execute([
                $definitiveFailure ? 'failed' : 'unknown',
                $definitiveFailure ? 'rejected' : 'unknown',
                $definitiveFailure
                    ? 'Provider rejected the transfer request.'
                    : 'Provider result requires reconciliation.',
                $attemptId,
            ]);
            if ($definitiveFailure) {
                throw new PaymentProviderException(
                    'The provider rejected this transfer request.',
                    'provider_rejected',
                    $e->httpStatus()
                );
            }
            throw new PaymentPendingException(
                'The transfer result is uncertain and must be reconciled before another attempt.',
                'unknown'
            );
        }
    }

    public function reconcile(int $ownerId, int $attemptId, bool $allowCompleted = false): array
    {
        $statement = $this->pdo->prepare('
            SELECT id, reference, status, amount_minor, currency FROM owner_transfer_attempts
            WHERE id = ? AND owner_id = ? LIMIT 1
        ');
        $statement->execute([$attemptId, $ownerId]);
        $attempt = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new PaymentValidationException('Transfer attempt was not found.');
        }
        if (
            !in_array($attempt['status'], ['pending', 'unknown', 'submitting'], true)
            && !($allowCompleted && $attempt['status'] === 'success')
        ) {
            return ['attempt_id' => $attemptId, 'status' => $attempt['status']];
        }
        try {
            $result = $this->provider->verifyTransfer((string) $attempt['reference']);
        } catch (Throwable $e) {
            $this->pdo->prepare("
                UPDATE owner_transfer_attempts SET status = 'unknown'
                WHERE id = ? AND status IN ('submitting', 'pending', 'unknown')
            ")->execute([$attemptId]);
            throw new PaymentPendingException('The provider could not resolve this transfer. It remains blocked from retry.', 'unknown');
        }
        if (isset($result['status']) && strtolower((string) $result['status']) === 'not_found') {
            $this->pdo->prepare("
                UPDATE owner_transfer_attempts SET status = 'failed', provider_status = 'not_found',
                    failure_reason = 'Provider confirms no transfer exists for this reference.'
                WHERE id = ? AND status IN ('submitting', 'pending', 'unknown')
            ")->execute([$attemptId]);
            return ['attempt_id' => $attemptId, 'status' => 'failed', 'provider_status' => 'not_found'];
        }
        return $this->applyProviderResult(
            $attemptId,
            $result,
            (int) $attempt['amount_minor'],
            (string) $attempt['currency']
        );
    }

    public function reconcileByReference(string $reference): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, owner_id FROM owner_transfer_attempts WHERE reference = ? LIMIT 1'
        );
        $statement->execute([$reference]);
        $attempt = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new PaymentValidationException('Transfer attempt was not found.');
        }
        return $this->reconcile((int) $attempt['owner_id'], (int) $attempt['id'], true);
    }

    private function applyProviderResult(
        int $attemptId,
        array $result,
        ?int $expectedAmount = null,
        ?string $expectedCurrency = null
    ): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('
                SELECT * FROM owner_transfer_attempts WHERE id = ? FOR UPDATE
            ');
            $statement->execute([$attemptId]);
            $attempt = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$attempt) {
                throw new RuntimeException('Transfer attempt disappeared during reconciliation.');
            }

            $expectedAmount ??= (int) $attempt['amount_minor'];
            $expectedCurrency ??= (string) $attempt['currency'];
            if (
                ($result['reference'] ?? null) !== $attempt['reference']
                || ($result['amount_minor'] ?? null) !== $expectedAmount
                || strtoupper((string) ($result['currency'] ?? '')) !== strtoupper($expectedCurrency)
                || !is_string($result['recipient_code'] ?? null)
                || !hash_equals((string) $attempt['recipient_code'], (string) $result['recipient_code'])
            ) {
                $status = 'unknown';
                $providerStatus = 'mismatch';
            } else {
                $providerStatus = strtolower((string) ($result['status'] ?? 'unknown'));
                $status = match ($providerStatus) {
                    'success' => 'success',
                    'failed' => $attempt['status'] === 'success' ? 'unknown' : 'failed',
                    'reversed' => $attempt['status'] === 'success' ? 'reversed' : 'unknown',
                    'pending', 'processing', 'queued', 'otp' => 'pending',
                    default => 'unknown',
                };
            }

            if ($status === 'success' && $attempt['status'] === 'success') {
                $this->pdo->commit();
                return ['attempt_id' => $attemptId, 'status' => 'success', 'provider_status' => $providerStatus];
            }

            $this->pdo->prepare('
                UPDATE owner_transfer_attempts
                SET status = ?, provider_status = ?, provider_reference = ?,
                    failure_reason = ?
                WHERE id = ?
            ')->execute([
                $status,
                substr($providerStatus, 0, 64),
                $result['provider_reference'] ?? null,
                $status === 'unknown' ? 'Provider outcome requires manual reconciliation.' : null,
                $attemptId,
            ]);

            if ($status === 'success') {
                $payout = $this->pdo->prepare('
                    SELECT status, booking_id FROM owner_payouts WHERE id = ? FOR UPDATE
                ');
                $payout->execute([(int) $attempt['payout_id']]);
                $payoutRow = $payout->fetch(PDO::FETCH_ASSOC);
                if ($payoutRow && $payoutRow['status'] !== 'paid') {
                    $alreadyPaid = $this->pdo->prepare("
                        SELECT COUNT(*) FROM owner_transfer_attempts
                        WHERE payout_id = ? AND status = 'success' AND id != ?
                    ");
                    $alreadyPaid->execute([(int) $attempt['payout_id'], $attemptId]);
                    if ((int) $alreadyPaid->fetchColumn() > 0) {
                        $this->pdo->prepare("
                            UPDATE owner_transfer_attempts SET status = 'unknown',
                                failure_reason = 'Additional successful transfer requires manual recovery.'
                            WHERE id = ?
                        ")->execute([$attemptId]);
                        $this->pdo->commit();
                        return ['attempt_id' => $attemptId, 'status' => 'unknown', 'provider_status' => 'duplicate_success'];
                    }
                    $this->pdo->prepare("
                        UPDATE owner_payouts
                        SET status = 'paid', provider = ?, provider_reference = ?, paid_at = UTC_TIMESTAMP()
                        WHERE id = ? AND status != 'paid'
                    ")->execute([
                        $attempt['provider'],
                        $result['provider_reference'] ?? $attempt['reference'],
                        $attempt['payout_id'],
                    ]);
                    $this->ledger->record('payout', 'owner', (int) $attempt['amount_minor'], [
                        'party_user_id' => (int) $attempt['owner_id'],
                        'booking_id' => (int) $payoutRow['booking_id'],
                        'payout_id' => (int) $attempt['payout_id'],
                        'currency' => (string) $attempt['currency'],
                        'description' => 'Provider-confirmed owner transfer',
                        'metadata' => ['reference' => (string) $attempt['reference']],
                    ]);
                } elseif ($payoutRow && $payoutRow['status'] === 'paid') {
                    $this->pdo->commit();
                    return ['attempt_id' => $attemptId, 'status' => 'success', 'provider_status' => $providerStatus];
                } elseif (!$payoutRow) {
                    throw new RuntimeException('A successful transfer has no matching payout.');
                }
            }
            if ($status === 'reversed' && $attempt['status'] === 'success') {
                $payout = $this->pdo->prepare(
                    'SELECT booking_id, status FROM owner_payouts WHERE id = ? FOR UPDATE'
                );
                $payout->execute([(int) $attempt['payout_id']]);
                $payoutRow = $payout->fetch(PDO::FETCH_ASSOC);
                if (!$payoutRow || $payoutRow['status'] !== 'paid') {
                    throw new RuntimeException('A reversed transfer has no matching paid payout.');
                }
                $this->pdo->prepare("
                    UPDATE owner_payouts SET status = 'reversed',
                        hold_reason = 'provider_transfer_reversed'
                    WHERE id = ? AND status = 'paid'
                ")->execute([(int) $attempt['payout_id']]);
                $this->ledger->record('payout_reversed', 'owner', (int) $attempt['amount_minor'], [
                    'party_user_id' => (int) $attempt['owner_id'],
                    'booking_id' => (int) $payoutRow['booking_id'],
                    'payout_id' => (int) $attempt['payout_id'],
                    'currency' => (string) $attempt['currency'],
                    'description' => 'Provider-confirmed transfer reversal',
                    'metadata' => ['reference' => (string) $attempt['reference']],
                ]);
            }

            $this->pdo->commit();
            return ['attempt_id' => $attemptId, 'status' => $status, 'provider_status' => $providerStatus];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
