<?php

namespace Nium;

/**
 * Wraps Nium's individual-customer onboarding + status APIs.
 * https://docs.nium.com/docs/onboarding/individual-customers
 *
 * NOTE: createCustomer()'s $payload shape (personal details, address, KYC
 * fields) is Nium's "Unified Add Customer" request body — kept as a plain
 * pass-through array here rather than guessed/hardcoded field names, since
 * the exact required fields for this product config need confirming from
 * the Nium Portal before kyc.php calls this for real.
 */
final class NiumCustomerService {
    public function __construct(private readonly NiumClient $client = new NiumClient()) {}

    /** @param array<string, mixed> $payload */
    public function createCustomer(array $payload): array {
        $clientHashId = NiumConfig::instance()->clientHashId;
        return $this->client->post("/api/v4/client/$clientHashId/customer", $payload);
    }

    /** Compliance/KYC status + full profile for an onboarded customer. */
    public function getCustomer(string $customerHashId): array {
        $clientHashId = NiumConfig::instance()->clientHashId;
        return $this->client->get("/api/v2/client/$clientHashId/customer/$customerHashId");
    }

    /**
     * Maps Nium's `complianceStatus` (INITIATED / IN PROGRESS / ACTION REQUIRED /
     * RFI_REQUESTED / COMPLETED / ERROR / REJECT / EXPIRED) onto the
     * Flutter app's `KycState` enum (notStarted/pending/approved/rejected/actionRequired).
     */
    public static function mapComplianceStatus(?string $niumStatus): string {
        return match (strtoupper((string) $niumStatus)) {
            '' => 'notStarted',
            'COMPLETED' => 'approved',
            'REJECT', 'ERROR', 'EXPIRED' => 'rejected',
            'ACTION REQUIRED', 'RFI_REQUESTED' => 'actionRequired',
            default => 'pending', // INITIATED, IN PROGRESS
        };
    }
}
