<?php

/**
 * Provider operations used for owner bank verification and transfers.
 */
interface PayoutProviderInterface
{
    public function name(): string;

    public function banks(string $currency): array;

    public function resolveBankAccount(
        string $accountNumber,
        string $bankCode,
        string $currency
    ): array;

    public function createTransferRecipient(
        string $name,
        string $accountNumber,
        string $bankCode,
        string $currency
    ): array;

    public function initiateTransfer(
        string $reference,
        int $amountMinor,
        string $currency,
        string $recipientCode,
        string $reason
    ): array;

    public function verifyTransfer(string $reference): array;
}
