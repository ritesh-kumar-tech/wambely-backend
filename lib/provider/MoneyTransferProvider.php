<?php

namespace Provider;

/**
 * Everything an endpoint needs from "whatever actually moves the money" —
 * implemented by StubMoneyTransferProvider (deterministic local fake data,
 * used while real Nium sandbox access is still being sorted out) and
 * NiumMoneyTransferProvider (wraps the Nium*Service classes in lib/nium/).
 * Selected by the MONEY_TRANSFER_PROVIDER env var — see provider.php.
 *
 * No endpoint file should ever touch a Nium*Service, or read/write
 * `wallets`/`users.nium_customer_hash_id`/`users.nium_compliance_status`
 * directly — everything goes through this interface so swapping stub ->
 * nium later is a config change, not a rewrite. (Endpoint files DO still
 * own the `beneficiaries` and `transactions` tables directly, since those
 * carry app-display data — recipient name, flag emoji, etc. — the provider
 * only ever hands back opaque reference ids for those.)
 */
interface MoneyTransferProvider {
    /** @param array<string, mixed> $profile @return array{customerRef: string, status: string} */
    public function createCustomer(string $userId, array $profile): array;

    /** @return array{status: string} */
    public function submitKycDocument(string $userId, string $customerRef, string $documentType): array;

    /** @return array{status: string} */
    public function submitKycSelfie(string $userId, string $customerRef): array;

    /** @return array{status: string} */
    public function getCustomerStatus(string $customerRef): array;

    /** Idempotent — returns the existing wallet if one already exists for this currency. @return array{walletRef: string} */
    public function ensureWallet(string $customerRef, string $currencyCode): array;

    /** @return list<array{walletRef: string, currencyCode: string, balance: float}> */
    public function getWallets(string $customerRef): array;

    /**
     * Adds funds to a wallet for testing. Under the stub this is a direct
     * local credit; a real Nium wallet is normally funded via a Payin, not
     * an arbitrary "add funds" call — NiumMoneyTransferProvider throws
     * until that's designed. @return array{balance: float}
     */
    public function fundWallet(string $walletRef, float $amount): array;

    /** @param array<string, mixed> $payload @return array{beneficiaryRef: string, payoutRef: ?string} */
    public function createBeneficiary(string $customerRef, array $payload): array;

    /** @return array{exchangeRate: float, fee: float, destinationAmount: float, expiresInSeconds: int, deliveryEstimate: string} */
    public function createQuote(string $sourceCurrency, string $destinationCurrency, float $sourceAmount): array;

    /**
     * @param array{
     *   sourceWalletRef: string, beneficiaryRef: string, sourceAmount: float,
     *   sourceCurrency: string, destinationAmount: float, destinationCurrency: string,
     *   exchangeRate: float, fee: float, totalDebit: float, clientReference: string
     * } $payload
     * @return array{payoutRef: string, status: string}
     */
    public function createPayout(array $payload): array;

    /** @return array{status: string} */
    public function getPayoutStatus(string $payoutRef): array;
}

/** Thrown by fundWallet/createPayout when the wallet can't cover the amount. */
class InsufficientFundsException extends \RuntimeException {}

/** Thrown by the stub for currency pairs it doesn't have a fake rate for. */
class UnsupportedCorridorException extends \RuntimeException {}
