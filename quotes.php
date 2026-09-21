<?php
require __DIR__ . '/config.php';

$userId = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    fail('Unsupported request.', 405);
}

$body = json_input();
$sourceCurrency = strtoupper((string) ($body['source_currency'] ?? ''));
$destinationCurrency = strtoupper((string) ($body['destination_currency'] ?? ''));
$sourceAmount = (float) ($body['amount'] ?? 0);

if ($sourceCurrency === '' || $destinationCurrency === '' || $sourceAmount <= 0) {
    fail('Source currency, destination currency, and a positive amount are required.');
}

try {
    $quote = \Provider\money_transfer_provider()->createQuote($sourceCurrency, $destinationCurrency, $sourceAmount);
} catch (\Provider\UnsupportedCorridorException $e) {
    fail($e->getMessage(), 422);
}

$quoteId = new_id('quote');
$expiresAt = date('Y-m-d H:i:s', time() + $quote['expiresInSeconds']);

db()->prepare(
    'INSERT INTO nium_quotes
        (quote_id, user_id, source_currency, destination_currency, source_amount,
         destination_amount, exchange_rate, fee, delivery_estimate, expires_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $quoteId, $userId, $sourceCurrency, $destinationCurrency, $sourceAmount,
    $quote['destinationAmount'], $quote['exchangeRate'], $quote['fee'], $quote['deliveryEstimate'], $expiresAt,
]);

respond(['quote' => [
    'quote_id' => $quoteId,
    'source_currency' => $sourceCurrency,
    'source_amount' => $sourceAmount,
    'destination_currency' => $destinationCurrency,
    'destination_amount' => $quote['destinationAmount'],
    'exchange_rate' => $quote['exchangeRate'],
    'fee' => $quote['fee'],
    'expires_at' => str_replace(' ', 'T', $expiresAt),
    'delivery_estimate' => $quote['deliveryEstimate'],
]]);
