<?php
require __DIR__ . '/config.php';

$userId = require_auth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Unsupported request.', 405);
}

$body = json_input();
$quoteId = (string) ($body['quote_id'] ?? '');
$beneficiaryId = (string) ($body['beneficiary_id'] ?? '');
$idempotencyKey = (string) ($body['idempotency_key'] ?? '');

if ($quoteId === '' || $beneficiaryId === '' || $idempotencyKey === '') {
    fail('quote_id, beneficiary_id, and idempotency_key are all required.');
}

// Idempotent replay: if this exact attempt already produced a transaction
// (double-tap, retried request after a dropped response), return that
// transaction again rather than creating — or rejecting — a second one.
$stmt = db()->prepare('SELECT * FROM transactions WHERE user_id = ? AND idempotency_key = ?');
$stmt->execute([$userId, $idempotencyKey]);
$existing = $stmt->fetch();
if ($existing) {
    respond(['transaction' => transaction_to_json($existing)]);
}

if (!kyc_is_approved($userId)) {
    fail('Complete identity verification before sending money.', 403);
}

// Never trust the client's quote_id/amount — re-validate ownership,
// consumption, and expiry against our own record of what Nium (or the
// stub) actually quoted.
$stmt = db()->prepare('SELECT * FROM nium_quotes WHERE quote_id = ? AND user_id = ?');
$stmt->execute([$quoteId, $userId]);
$quote = $stmt->fetch();
if (!$quote) fail('Quote not found.', 404);
if ($quote['consumed']) fail('This quote has already been used.', 409);
if (strtotime($quote['expires_at']) < time()) fail('This quote has expired. Please request a new one.', 409);

$stmt = db()->prepare("SELECT * FROM beneficiaries WHERE id = ? AND user_id = ? AND status != 'deleted'");
$stmt->execute([$beneficiaryId, $userId]);
$beneficiary = $stmt->fetch();
if (!$beneficiary) fail('Recipient not found.', 404);

$customerRef = ensure_customer_ref($userId);
$provider = \Provider\money_transfer_provider();
$wallet = $provider->ensureWallet($customerRef, $quote['source_currency']);

$sourceAmount = (float) $quote['source_amount'];
$fee = (float) $quote['fee'];
$totalDebit = round($sourceAmount + $fee, 2);

try {
    $payout = $provider->createPayout([
        'sourceWalletRef' => $wallet['walletRef'],
        'beneficiaryRef' => $beneficiary['nium_beneficiary_hash_id'],
        'sourceAmount' => $sourceAmount,
        'sourceCurrency' => $quote['source_currency'],
        'destinationAmount' => (float) $quote['destination_amount'],
        'destinationCurrency' => $quote['destination_currency'],
        'exchangeRate' => (float) $quote['exchange_rate'],
        'fee' => $fee,
        'totalDebit' => $totalDebit,
        'clientReference' => $idempotencyKey,
    ]);
} catch (\Provider\InsufficientFundsException $e) {
    fail($e->getMessage(), 402);
}

$status = $payout['status'] === 'completed' ? 'completed' : 'processing';

$id = new_id('txn');
$reference = 'WMB-' . random_int(10000, 99999);
try {
    db()->prepare(
        'INSERT INTO transactions
            (id, user_id, type, status, amount, currency_code, fee, exchange_rate,
             recipient_name, recipient_flag_emoji, delivery_estimate, reference,
             nium_transaction_id, quote_id, destination_amount, idempotency_key)
         VALUES (?, ?, \'sent\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id, $userId, $status, $sourceAmount, $quote['source_currency'], $fee, $quote['exchange_rate'],
        $beneficiary['full_name'], $beneficiary['country_flag_emoji'], $quote['delivery_estimate'], $reference,
        $payout['payoutRef'], $quoteId, $quote['destination_amount'], $idempotencyKey,
    ]);
} catch (\PDOException $e) {
    // Unique (user_id, idempotency_key) violation from a concurrent retry
    // that raced this one — the other request's row already exists, so
    // hand that back instead of erroring or double-charging the wallet.
    if ($e->getCode() === '23000') {
        $stmt = db()->prepare('SELECT * FROM transactions WHERE user_id = ? AND idempotency_key = ?');
        $stmt->execute([$userId, $idempotencyKey]);
        respond(['transaction' => transaction_to_json($stmt->fetch())]);
    }
    throw $e;
}

db()->prepare('UPDATE nium_quotes SET consumed = 1 WHERE quote_id = ?')->execute([$quoteId]);

create_notification(
    $userId,
    'transfer',
    $status === 'completed' ? 'Transfer completed' : 'Transfer processing',
    sprintf(
        '%s %.2f %s to %s (%s).',
        $status === 'completed' ? 'Sent' : 'Sending',
        $sourceAmount,
        $quote['source_currency'],
        $beneficiary['full_name'],
        $reference
    )
);

$stmt = db()->prepare('SELECT * FROM transactions WHERE id = ?');
$stmt->execute([$id]);
respond(['transaction' => transaction_to_json($stmt->fetch())], 201);
