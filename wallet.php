<?php
require __DIR__ . '/config.php';

const PRIMARY_CURRENCY = 'USD';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'rates') {
    rates();
} elseif ($method === 'GET') {
    get_wallet();
} elseif ($method === 'POST' && $action === 'add_funds') {
    add_funds();
} else {
    fail('Unsupported request.', 405);
}

/** @return array{primaryBalance: array, otherBalances: array} */
function wallet_json(array $wallets): array {
    $primary = null;
    $others = [];
    foreach ($wallets as $w) {
        if ($w['currencyCode'] === PRIMARY_CURRENCY) {
            $primary = $w;
        } else {
            $others[] = $w;
        }
    }
    if (!$primary) fail('Wallet is not available yet.', 503);

    return [
        'primaryBalance' => ['currencyCode' => $primary['currencyCode'], 'amount' => $primary['balance']],
        'otherBalances' => array_map(
            fn(array $w) => ['currencyCode' => $w['currencyCode'], 'amount' => $w['balance']],
            $others
        ),
    ];
}

function get_wallet(): void {
    $userId = require_auth();
    $customerRef = ensure_customer_ref($userId);
    $provider = \Provider\money_transfer_provider();
    $provider->ensureWallet($customerRef, PRIMARY_CURRENCY);
    respond(['wallet' => wallet_json($provider->getWallets($customerRef))]);
}

function rates(): void {
    require_auth();
    // Static for now, matching the stub provider's fixed rate table — a
    // real rates feed would come from the same Nium FX APIs as quotes.php.
    respond(['rates' => [
        ['fromCurrency' => 'USD', 'toCurrency' => 'INR', 'rate' => 83.50, 'feePercent' => 0.5],
    ]]);
}

function add_funds(): void {
    $userId = require_auth();
    $body = json_input();
    $amount = (float) ($body['amount'] ?? 0);
    if ($amount <= 0) fail('Amount must be greater than zero.');

    $customerRef = ensure_customer_ref($userId);
    $provider = \Provider\money_transfer_provider();
    $wallet = $provider->ensureWallet($customerRef, PRIMARY_CURRENCY);

    try {
        $provider->fundWallet($wallet['walletRef'], $amount);
    } catch (\Throwable $e) {
        fail($e->getMessage(), 501);
    }

    respond(['wallet' => wallet_json($provider->getWallets($customerRef))]);
}
