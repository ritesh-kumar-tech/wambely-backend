<?php

namespace Provider;

use Nium\NiumConfig;
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

    /**
     * Builds Nium's confirmed "Unified Add Customer" request from the
     * app's richer KYC profile (see config.php's build_kyc_profile() +
     * kyc.php's submit_document(), which merges in the document fields).
     *
     * `complianceTemplateId` and `identificationType: "National Id"` are
     * both fixed, account-specific constraints confirmed with Nium support
     * for our compliance profile — NOT general Nium requirements, so this
     * will need revisiting if the account's compliance template ever changes.
     */
    public function createCustomer(string $userId, array $profile): array {
        $complianceTemplateId = NiumConfig::instance()->complianceTemplateId;
        if ($complianceTemplateId === '') {
            throw new \RuntimeException('NIUM_COMPLIANCE_TEMPLATE_ID is not configured — see .env.example.');
        }

        $nameParts = explode(' ', trim((string) $profile['fullName']), 2);

        $payload = [
            'firstName' => $nameParts[0],
            'lastName' => $nameParts[1] ?? $nameParts[0],
            'email' => $profile['email'],
            'countryCode' => $profile['nationality'],
            'nationality' => $profile['nationality'],
            'mobile' => $profile['phone'],
            'mobileCountryCode' => $profile['mobileCountryCode'],
            'dateOfBirth' => $profile['dateOfBirth'],
            'complianceTemplateId' => $complianceTemplateId,
            'billingAddress1' => $profile['addressLine1'],
            'billingCity' => $profile['addressCity'],
            'billingState' => $profile['addressState'],
            'billingCountry' => $profile['addressCountry'],
            'billingZipCode' => $profile['addressPostalCode'],
            'identificationDoc' => [[
                'identificationType' => 'National Id',
                'identificationValue' => $profile['documentNumber'],
                'identificationDocIssuanceCountry' => $profile['documentIssuanceCountry'],
                'identificationDocExpiry' => $profile['documentExpiry'] ?: null,
                'identificationDocument' => [[
                    'fileName' => $profile['documentFileName'],
                    'fileType' => $profile['documentFileType'],
                    'document' => $profile['documentFileBase64'],
                ]],
            ]],
        ];

        $result = $this->customers->createCustomer($payload);
        $customerRef = (string) ($result['customerHashId'] ?? '');
        $status = (string) ($result['complianceStatus'] ?? 'INITIATED');
        \db()->prepare('UPDATE users SET nium_customer_hash_id = ?, nium_compliance_status = ? WHERE id = ?')
            ->execute([$customerRef, $status, $userId]);
        return ['customerRef' => $customerRef, 'status' => $status];
    }

    /** Not called directly for Nium — kyc.php's document step goes straight to createCustomer(). Kept for interface compliance. */
    public function submitKycDocument(string $userId, string $customerRef, string $documentType): array {
        return $this->getCustomerStatus($customerRef);
    }

    /**
     * Nium's confirmed Unified Add Customer schema has no separate selfie
     * concept — the real create-customer call already happened in
     * createCustomer() above, so this is just a status refresh to keep the
     * app's existing document-then-selfie UI flow working unchanged.
     */
    public function submitKycSelfie(string $userId, string $customerRef): array {
        return $this->getCustomerStatus($customerRef);
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

    /**
     * Confirmed response shape (2026-10-20, real sandbox call): a JSON array
     * of wallet objects, each holding MULTIPLE currency balances —
     * `[{ "walletHashId": "...", "currencies": [{ "code": "USD", "balance": 0.0, "default": true }, ...] }]`
     * — not one wallet per currency as originally assumed. Flattened here
     * into the one-row-per-currency shape the rest of the app expects;
     * `walletRef` is the same walletHashId for every currency on that
     * wallet, since Nium addresses a currency balance by (walletHashId,
     * currencyCode) together, not by a separate ref per currency.
     */
    public function getWallets(string $customerRef): array {
        $wallets = $this->wallets->getWallets($customerRef);
        $rows = [];
        foreach ($wallets as $wallet) {
            $walletRef = (string) ($wallet['walletHashId'] ?? '');
            foreach ($wallet['currencies'] ?? [] as $currency) {
                $rows[] = [
                    'walletRef' => $walletRef,
                    'currencyCode' => (string) ($currency['code'] ?? ''),
                    'balance' => (float) ($currency['balance'] ?? 0),
                ];
            }
        }
        return $rows;
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
        // NiumFxService already returns exactly this shape (plus
        // providerQuoteId, which quotes.php persists for later reference).
        return $this->fx->createQuote($sourceCurrency, $destinationCurrency, $sourceAmount);
    }

    public function createPayout(array $payload): array {
        $result = $this->payments->createPayout($payload);
        return [
            'payoutRef' => (string) ($result['id'] ?? $result['systemReferenceNumber'] ?? ''),
            'status' => (string) ($result['status'] ?? 'pending'),
        ];
    }

    public function getPayoutStatus(string $payoutRef): array {
        $result = $this->payments->getPayoutStatus($payoutRef);
        return ['status' => (string) ($result['status'] ?? 'pending')];
    }
}
