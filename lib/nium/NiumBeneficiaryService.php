<?php

namespace Nium;

/**
 * Wraps Nium's Beneficiary API — confirmed from docs.nium.com/docs/payouts/beneficiaries:
 *
 *   POST /api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries
 *   { beneficiaryName, beneficiaryAccountType: "Individual"|"Corporate",
 *     beneficiaryCountryCode, destinationCurrency, destinationCountry,
 *     payoutMethod: "LOCAL", beneficiaryAccountNumber, beneficiaryBankName,
 *     routingCodeType1, routingCodeValue1, ... }
 *   -> { beneficiaryHashId, payoutHashId, ... }
 *
 * Only India (routingCodeType1=IFSC) is wired to a real routing code right
 * now, matching corridors.php's own "only India tested end-to-end" scope —
 * other countries' bank_details don't carry a routing code field yet, so
 * routingCodeType1/Value1 are omitted for them (Nium will likely reject
 * those until that's added corridor-by-corridor, same as the stub).
 */
final class NiumBeneficiaryService {
    public function __construct(private readonly NiumClient $client = new NiumClient()) {}

    /** @param array<string, mixed> $payload */
    public function createBeneficiary(string $customerHashId, array $payload): array {
        $clientHashId = NiumConfig::instance()->clientHashId;
        $bankDetails = is_array($payload['bankDetails'] ?? null) ? $payload['bankDetails'] : [];
        $countryCode = strtoupper((string) $payload['countryCode']);

        $request = [
            'beneficiaryName' => (string) $payload['fullName'],
            'beneficiaryAccountType' => 'Individual',
            'beneficiaryCountryCode' => $countryCode,
            'destinationCurrency' => strtoupper((string) $payload['currencyCode']),
            'destinationCountry' => $countryCode,
            'payoutMethod' => 'LOCAL',
            'beneficiaryAccountNumber' => (string) ($bankDetails['accountNumber'] ?? ''),
            'beneficiaryBankName' => (string) ($bankDetails['bankName'] ?? ''),
        ];
        if ($countryCode === 'IN' && isset($bankDetails['ifsc'])) {
            $request['routingCodeType1'] = 'IFSC';
            $request['routingCodeValue1'] = (string) $bankDetails['ifsc'];
        }
        if (!empty($payload['phone'])) {
            $request['beneficiaryContactNumber'] = (string) $payload['phone'];
        }

        return $this->client->post("/api/v2/client/$clientHashId/customer/$customerHashId/beneficiaries", $request);
    }
}
