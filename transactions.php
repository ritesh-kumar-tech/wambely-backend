<?php
require __DIR__ . '/config.php';

$userId = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && isset($_GET['id'])) {
    $stmt = db()->prepare('SELECT * FROM transactions WHERE id = ? AND user_id = ?');
    $stmt->execute([$_GET['id'], $userId]);
    $txn = $stmt->fetch();
    if (!$txn) fail('Transaction not found.', 404);
    respond(['transaction' => transaction_to_json($txn)]);
} elseif ($method === 'GET') {
    $stmt = db()->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$userId]);
    respond(['transactions' => array_map('transaction_to_json', $stmt->fetchAll())]);
} elseif ($method === 'POST' && $action === 'deposit') {
    $body = json_input();
    $amount = (float) ($body['amount'] ?? 0);
    $sourceLabel = (string) ($body['sourceLabel'] ?? '');

    if ($amount <= 0) fail('Deposit amount must be greater than zero.');

    $id = new_id('txn');
    $reference = 'WMB-' . random_int(10000, 99999);
    db()->prepare(
        'INSERT INTO transactions (id, user_id, type, status, amount, currency_code, recipient_name, reference)
         VALUES (?, ?, \'deposit\', \'completed\', ?, \'USD\', ?, ?)'
    )->execute([$id, $userId, $amount, $sourceLabel, $reference]);

    $stmt = db()->prepare('SELECT * FROM transactions WHERE id = ?');
    $stmt->execute([$id]);
    respond(['transaction' => transaction_to_json($stmt->fetch())]);
} else {
    fail('Unsupported request.', 405);
}
