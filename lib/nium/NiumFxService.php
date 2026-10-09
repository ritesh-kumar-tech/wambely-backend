<?php

namespace Nium;

/**
 * Wraps Nium's FX Quote API — confirmed from docs.nium.com/docs/foreign-exchange/locked-rate:
 *
 *   POST /api/v1/client/{clientHashId}/quotes
 *   { sourceCurrencyCode, destinationCurrencyCode, conversionSchedule: "immediate",
 *     lockPeriod: "8_hours", quoteType: "balance_transfer" }
 *   -> { id, netExchangeRate, expiryTime, exchangeRate, markupRate, ... }
 *
 * `netExchangeRate` already has Nium's markup applied — that's the rate the
 * customer actually gets, so `fee` is reported as 0 here rather than
 * inventing a separate fee by reverse-engineering the markup. Nium's
 * response has no `destinationAmount` until the quote is actually
 * consumed, so it's computed locally from `sourceAmount * netExchangeRate`.
 *
 * ASSUMPTION TO VERIFY IN SANDBOX: this quote is used only to show the user
 * an indicative rate before confirming — NiumPaymentService::createPayout()
 * does NOT thread the returned `id` through to the remittance call (Nium's
 * remittance API appears to resolve FX at execution time from the
 * beneficiary's own destinationCurrency, separately from this rate-lock
 * flow). If a real transfer ends up settling at a different rate than
 * quoted here, this assumption is wrong and the quote `id` needs to be
 * carried through to a conversion step before the remittance call.
 */
final class NiumFxService {
    public function __construct(private readonly NiumClient $client = new NiumClient()) {}

    public function createQuote(string $sourceCurrency, string $destinationCurrency, float $sourceAmount): array {
        $clientHashId = NiumConfig::instance()->clientHashId;
        $quote = $this->client->post("/api/v1/client/$clientHashId/quotes", [
            'sourceCurrencyCode' => $sourceCurrency,
            'destinationCurrencyCode' => $destinationCurrency,
            'conversionSchedule' => 'immediate',
            'lockPeriod' => '8_hours',
            'quoteType' => 'balance_transfer',
        ]);

        $netExchangeRate = (float) $quote['netExchangeRate'];
        $expiresInSeconds = max(60, strtotime($quote['expiryTime']) - time());

        return [
            'providerQuoteId' => (string) $quote['id'],
            'exchangeRate' => $netExchangeRate,
            'fee' => 0.0,
            'destinationAmount' => round($sourceAmount * $netExchangeRate, 2),
            'expiresInSeconds' => $expiresInSeconds,
            'deliveryEstimate' => '1-2 business days',
        ];
    }
}
