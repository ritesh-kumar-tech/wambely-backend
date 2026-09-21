<?php
require __DIR__ . '/config.php';

const VALID_TYPES = ['bankAccount', 'debitCard'];

$userId = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    list_payout_methods($userId);
} elseif ($method === 'POST') {
    add_payout_method($userId);
} elseif ($method === 'DELETE') {
    remove_payout_method($userId);
} else {
    fail('Unsupported request.', 405);
}

function payout_method_to_json(array $m): array {
    return [
        'id' => $m['id'],
        'type' => $m['type'],
        'label' => $m['label'],
        'maskedDetail' => $m['masked_detail'],
    ];
}

function list_payout_methods(string $userId): void {
    $stmt = db()->prepare('SELECT * FROM payout_methods WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$userId]);
    respond(['payoutMethods' => array_map('payout_method_to_json', $stmt->fetchAll())]);
}

function add_payout_method(string $userId): void {
    $body = json_input();
    $type = (string) ($body['type'] ?? '');
    $label = trim((string) ($body['label'] ?? ''));
    $maskedDetail = trim((string) ($body['maskedDetail'] ?? ''));

    if (!in_array($type, VALID_TYPES, true)) fail('type must be bankAccount or debitCard.');
    if ($label === '' || $maskedDetail === '') fail('label and maskedDetail are required.');

    $id = new_id('pm');
    db()->prepare('INSERT INTO payout_methods (id, user_id, type, label, masked_detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $userId, $type, $label, $maskedDetail]);

    $stmt = db()->prepare('SELECT * FROM payout_methods WHERE id = ?');
    $stmt->execute([$id]);
    respond(['payoutMethod' => payout_method_to_json($stmt->fetch())], 201);
}

function remove_payout_method(string $userId): void {
    $id = (string) ($_GET['id'] ?? '');
    db()->prepare('DELETE FROM payout_methods WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    respond(['ok' => true]);
}
