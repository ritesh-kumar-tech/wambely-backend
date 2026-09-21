<?php

namespace Provider;

use Nium\NiumCustomerService;
use Nium\NiumWalletService;
use Nium\NiumBeneficiaryService;
use Nium\NiumFxService;
use Nium\NiumPaymentService;

/**
 * Wraps the real Nium*Service classes (lib/nium/) behind the
 * MoneyTransferProvider interface. createCustomer/getCustomerStatus/
 * getWallets are wired for real; the rest throw until the exact Nium
 * schemas (beneficiary, FX quote, transfer/remittance) are confirmed — see
 * lib/nium/Nium{Beneficiary,Fx,Payment}Service.php and the Phase 1 plan's
 * "Open items" list.
 */
final class NiumMoneyTransferProvider implements MoneyTransferProvider {
    public function __construct(
        private readonly NiumCustomerService $customers = new NiumCustomerService(),
        private readonly NiumWalletService $wallets = new NiumWalletService(),
        private readonly NiumBeneficiaryService $beneficiaries = new NiumBeneficiaryService(),
        private readonly NiumFxService $fx = new NiumFxService(),
        private readonly NiumPaymentService $payments = new NiumPaymentService(),
    ) {}

    public function createCustomer(string $userId, array $profile): array {
        $result = $this->customers->createCustomer($profile);
        $customerRef = (string) ($result['customerHashId'] ?? '');
        \db()->prepare('UPDATE users SET nium_customer_hash_id = ?, nium_compliance_status = ? WHERE id = ?')
            ->execute([$customerRef, (string) ($result['status'] ?? 'INITIATED'), $userId]);
        return ['customerRef' => $customerRef, 'status' => (string) ($result['status'] ?? 'INITIATED')];
    }

    public function submitKycDocument(string $userId, string $customerRef, string $documentType): array {
        throw new \RuntimeException('Real Nium document upload is not wired up yet.');
    }

    public function submitKycSelfie(string $userId, string $customerRef): array {
        throw new \RuntimeException('Real Nium selfie/document upload is not wired up yet.');
    }

    public function getCustomerStatus(string $customerRef): array {
        $result = $this->customers->getCustomer($customerRef);
        return ['status' => (string) ($result['complianceStatus'] ?? $result['status'] ?? 'INITIATED')];
    }

    public function ensureWallet(string $customerRef, string $currencyCode): array {
        // Nium auto-creates a default wallet on onboarding; adding more
        // currencies is a real API call this hasn't needed yet in Phase 1.
        $wallets = $this->getWallets($customerRef);
        foreach ($wallets as $wallet) {
            if ($wallet['currencyCode'] === $currencyCode) return ['walletRef' => $wallet['walletRef']];
        }
        throw new \RuntimeException("No $currencyCode wallet exists for this customer and creating one isn't wired up yet.");
    }

    public function getWallets(string $customerRef): array {
        $result = $this->wallets->getWallets($customerRef);
        $list = $result['wallets'] ?? $result['content'] ?? (is_array($result) && array_is_list($result) ? $result : []);
        return array_map(fn(array $w) => [
            'walletRef' => (string) ($w['walletHashId'] ?? ''),
            'currencyCode' => (string) ($w['currencyCode'] ?? ''),
            'balance' => (float) ($w['balance'] ?? 0),
        ], $list);
    }

    public function fundWallet(string $walletRef, float $amount): array {
        throw new \RuntimeException('A real Nium wallet is funded via a Payin, not a direct "add funds" call — not designed yet.');
    }

    public function createBeneficiary(string $customerRef, array $payload): array {
        $result = $this->beneficiaries->createBeneficiary($customerRef, $payload);
        return [
            'beneficiaryRef' => (string) ($result['beneficiaryHashId'] ?? ''),
            'payoutRef' => isset($result['payoutHashId']) ? (string) $result['payoutHashId'] : null,
        ];
    }

    public function createQuote(string $sourceCurrency, string $destinationCurrency, float $sourceAmount): array {
        $result = $this->fx->createQuote($sourceCurrency, $destinationCurrency, $sourceAmount);
        return [
            'exchangeRate' => (float) ($result['netExchangeRate'] ?? $result['exchangeRate'] ?? 0),
            'fee' => (float) ($result['fee'] ?? 0),
            'destinationAmount' => (float) ($result['destinationAmount'] ?? 0),
            'expiresInSeconds' => (int) ($result['expiresInSeconds'] ?? 300),
            'deliveryEstimate' => (string) ($result['deliveryEstimate'] ?? ''),
        ];
    }

    public function createPayout(array $payload): array {
        $result = $this->payments->createPayout($payload);
        return ['payoutRef' => (string) ($result['id'] ?? ''), 'status' => (string) ($result['status'] ?? 'pending')];
    }

    public function getPayoutStatus(string $payoutRef): array {
        $result = $this->payments->getPayoutStatus($payoutRef);
        return ['status' => (string) ($result['status'] ?? 'pending')];
    }
}
