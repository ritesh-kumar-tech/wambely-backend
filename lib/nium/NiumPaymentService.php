<?php

namespace Nium;

/**
 * Wraps Nium's Transfer Money / remittance creation + status APIs.
 *
 * NOT YET WIRED: the exact endpoint path, required fields, and the full
 * payout status enum need confirming from the Nium Portal before this can
 * create a real payout — see the "Open items" section of the Phase 1 plan.
 */
final class NiumPaymentService {
    public function __construct(private readonly NiumClient $client = new NiumClient()) {}

    /** @param array<string, mixed> $payload */
    public function createPayout(array $payload): array {
        throw new \RuntimeException(
            'NiumPaymentService::createPayout is not implemented yet — need the exact Transfer Money / remittance endpoint and fields from the Nium Portal.'
        );
    }

    public function getPayoutStatus(string $niumTransactionId): array {
        throw new \RuntimeException(
            'NiumPaymentService::getPayoutStatus is not implemented yet — need the exact remittance-status endpoint from the Nium Portal.'
        );
    }
}
