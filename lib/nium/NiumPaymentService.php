<?php

namespace Nium;

/**
 * Wraps Nium's Transfer Money / remittance creation API — confirmed from
 * docs.nium.com/docs/payouts/transfer-money (pre-created-beneficiary flow):
 *
 *   POST /api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance
 *   { "beneficiary": { "id": "<beneficiaryHashId>" },
 *     "payout": { "destinationAmount": 10 },
 *     "purposeCode": "IR005" }
 *
 * ASSUMPTIONS TO VERIFY IN SANDBOX (once customer onboarding is unblocked):
 * - `purposeCode: "IR005"` is the only example value the docs show; it may
 *   need to vary by transfer purpose (e.g. family support vs trade) — check
 *   the Purpose Codes page if Nium rejects this for a given corridor.
 * - No `sourceCurrency`/`sourceAmount` field is shown in the confirmed
 *   example — only `payout.destinationAmount`. That means Nium resolves FX
 *   from the wallet's funding currency at execution time, independent of
 *   the earlier /quotes rate-lock (see NiumFxService's doc comment).
 * - getPayoutStatus() below is NOT wired yet — docs point to a separate
 *   "Get Transaction" endpoint whose exact path isn't confirmed.
 */
final class NiumPaymentService {
    public function __construct(private readonly NiumClient $client = new NiumClient()) {}

    /** @param array<string, mixed> $payload */
    public function createPayout(array $payload): array {
        $clientHashId = NiumConfig::instance()->clientHashId;
        $customerHashId = (string) $payload['customerRef'];
        $walletHashId = (string) $payload['sourceWalletRef'];

        return $this->client->post(
            "/api/v2/client/$clientHashId/customer/$customerHashId/wallet/$walletHashId/remittance",
            [
                'beneficiary' => ['id' => (string) $payload['beneficiaryRef']],
                'payout' => ['destinationAmount' => (float) $payload['destinationAmount']],
                'purposeCode' => 'IR005',
            ],
            idempotencyKey: (string) $payload['clientReference'],
        );
    }

    public function getPayoutStatus(string $niumTransactionId): array {
        throw new \RuntimeException(
            'NiumPaymentService::getPayoutStatus is not implemented yet — need the exact "Get Transaction" endpoint path from Nium docs/support.'
        );
    }
}
