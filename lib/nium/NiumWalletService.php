<?php

namespace Nium;

/**
 * Wraps Nium's Wallet APIs. https://docs.nium.com/docs/wallets
 *
 * Wallets are never cached locally as a balance — every call here hits
 * Nium live, so the app can never show a stale/wrong number for real money.
 * The local `wallets` table only stores the id mapping (see migration_002).
 */
final class NiumWalletService {
    public function __construct(private readonly NiumClient $client = new NiumClient()) {}

    /** All wallets (one per currency) for a customer, with live balances. */
    public function getWallets(string $customerHashId): array {
        $clientHashId = NiumConfig::instance()->clientHashId;
        return $this->client->get("/api/v1/client/$clientHashId/customer/$customerHashId/wallet");
    }
}
