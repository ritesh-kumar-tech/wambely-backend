<?php

namespace Nium;

/**
 * Wraps Nium's Beneficiary APIs. https://docs.nium.com/docs/beneficiaries
 *
 * NOT YET WIRED: the exact request/response schema for creating an
 * India/INR bank-account beneficiary + its payment-account (which fields
 * beyond entityType/name/email/contactNumber/dateOfBirth, plus the bank-side
 * accountNumber/ifsc/etc.) needs confirming from the Nium Portal API
 * reference or Postman collection before this can call the real endpoint —
 * see the "Open items" section of the Phase 1 plan. Calling any method here
 * throws until that's filled in, rather than guessing a payload that could
 * silently create a malformed beneficiary.
 */
final class NiumBeneficiaryService {
    public function __construct(private readonly NiumClient $client = new NiumClient()) {}

    /** @param array<string, mixed> $payload */
    public function createBeneficiary(string $customerHashId, array $payload): array {
        throw new \RuntimeException(
            'NiumBeneficiaryService::createBeneficiary is not implemented yet — ' .
            'need the exact India/INR beneficiary + payment-account schema from the Nium Portal.'
        );
    }
}
