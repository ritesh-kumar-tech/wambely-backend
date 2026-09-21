<?php

namespace Provider;

/**
 * Single switch for the whole app: MONEY_TRANSFER_PROVIDER=stub (default —
 * no Nium credentials needed) or =nium (real Nium sandbox/production, once
 * .env's NIUM_* vars are filled in). Every endpoint calls this instead of
 * ever constructing a provider class directly.
 */
function money_transfer_provider(): MoneyTransferProvider {
    static $instance = null;
    if ($instance !== null) return $instance;

    $selected = strtolower((string) (getenv('MONEY_TRANSFER_PROVIDER') ?: 'stub'));
    $instance = match ($selected) {
        'nium' => new NiumMoneyTransferProvider(),
        'stub' => new StubMoneyTransferProvider(),
        default => throw new \RuntimeException("MONEY_TRANSFER_PROVIDER must be 'stub' or 'nium', got '$selected'."),
    };
    return $instance;
}
