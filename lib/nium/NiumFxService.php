<?php

namespace Nium;

/**
 * Wraps Nium's FX Quote API (POST /api/v1/client/{clientHashId}/quotes —
 * lockPeriod, conversionSchedule, quoteType=balanceTransfer; response
 * includes netExchangeRate/exchangeRate/markupRate/lockPeriod/expiryTime).
 *
 * NOT YET WIRED: the exact quote-id field name and fee field need
 * confirming from the Nium Portal before this maps onto the Flutter app's
 * TransferQuote shape (quote_id/source_amount/destination_amount/fee/expires_at)
 * — see the "Open items" section of the Phase 1 plan.
 */
final class NiumFxService {
    public function __construct(private readonly NiumClient $client = new NiumClient()) {}

    public function createQuote(string $sourceCurrency, string $destinationCurrency, float $sourceAmount): array {
        throw new \RuntimeException(
            'NiumFxService::createQuote is not implemented yet — need the exact quote request/response field names from the Nium Portal.'
        );
    }
}
