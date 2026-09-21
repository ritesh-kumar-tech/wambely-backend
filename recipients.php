<?php
require __DIR__ . '/config.php';

$userId = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    list_recipients($userId);
} elseif ($method === 'POST') {
    create_recipient($userId);
} elseif ($method === 'DELETE') {
    delete_recipient($userId);
} elseif ($method === 'PUT' && $action === 'toggle_favorite') {
    toggle_favorite($userId);
} else {
    fail('Unsupported request.', 405);
}

function mask_account_number(string $accountNumber): string {
    $len = strlen($accountNumber);
    if ($len <= 4) return str_repeat('*', $len);
    return str_repeat('*', $len - 4) . substr($accountNumber, -4);
}

function beneficiary_to_json(array $b): array {
    $bankDetails = json_decode((string) $b['bank_details'], true) ?? [];
    return [
        'id' => $b['id'],
        'fullName' => $b['full_name'],
        'countryCode' => $b['country_code'],
        'countryFlagEmoji' => $b['country_flag_emoji'] ?? '',
        'currencyCode' => $b['currency_code'],
        'bankName' => $bankDetails['bankName'] ?? null,
        'accountNumberMasked' => isset($bankDetails['accountNumber'])
            ? mask_account_number((string) $bankDetails['accountNumber'])
            : null,
        'phone' => $b['phone'],
        'isFavorite' => (bool) $b['is_favorite'],
        'bankDetails' => $bankDetails,
    ];
}

function list_recipients(string $userId): void {
    $stmt = db()->prepare(
        "SELECT * FROM beneficiaries WHERE user_id = ? AND status != 'deleted' ORDER BY is_favorite DESC, full_name"
    );
    $stmt->execute([$userId]);
    respond(['recipients' => array_map('beneficiary_to_json', $stmt->fetchAll())]);
}

function create_recipient(string $userId): void {
    if (!kyc_is_approved($userId)) {
        fail('Complete identity verification before adding a recipient.', 403);
    }

    $body = json_input();
    $fullName = trim((string) ($body['fullName'] ?? ''));
    $countryCode = strtoupper((string) ($body['countryCode'] ?? ''));
    $countryFlagEmoji = (string) ($body['countryFlagEmoji'] ?? '');
    $currencyCode = strtoupper((string) ($body['currencyCode'] ?? ''));
    $phone = $body['phone'] ?? null;
    $bankDetails = is_array($body['bankDetails'] ?? null) ? $body['bankDetails'] : [];

    if ($fullName === '' || $countryCode === '' || $currencyCode === '') {
        fail('Full name, destination country, and currency are required.');
    }

    // Validate against the corridor's actual requirements — never trust
    // the client's field set, even though the Flutter form already enforces it.
    foreach (corridor_requirements($countryCode) as $field) {
        $value = trim((string) ($bankDetails[$field['key']] ?? ''));
        if ($field['required'] && $value === '') {
            fail("{$field['label']} is required.");
        }
        if (isset($field['minLength']) && $value !== '' && strlen($value) < $field['minLength']) {
            fail("{$field['label']} must be at least {$field['minLength']} characters.");
        }
    }

    $customerRef = ensure_customer_ref($userId);
    $result = \Provider\money_transfer_provider()->createBeneficiary($customerRef, [
        'fullName' => $fullName,
        'countryCode' => $countryCode,
        'currencyCode' => $currencyCode,
        'phone' => $phone,
        'bankDetails' => $bankDetails,
    ]);

    $id = new_id('ben');
    db()->prepare(
        'INSERT INTO beneficiaries
            (id, user_id, nium_beneficiary_hash_id, nium_payout_hash_id, full_name, country_code,
             country_flag_emoji, currency_code, phone, bank_details)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id, $userId, $result['beneficiaryRef'], $result['payoutRef'] ?? null, $fullName, $countryCode,
        $countryFlagEmoji, $currencyCode, $phone, json_encode($bankDetails),
    ]);

    $stmt = db()->prepare('SELECT * FROM beneficiaries WHERE id = ?');
    $stmt->execute([$id]);
    respond(['recipient' => beneficiary_to_json($stmt->fetch())], 201);
}

function delete_recipient(string $userId): void {
    $id = (string) ($_GET['id'] ?? '');
    db()->prepare("UPDATE beneficiaries SET status = 'deleted' WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
    respond(['ok' => true]);
}

function toggle_favorite(string $userId): void {
    $id = (string) ($_GET['id'] ?? '');
    $stmt = db()->prepare('SELECT * FROM beneficiaries WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();
    if (!$row) fail('Recipient not found.', 404);

    $newValue = $row['is_favorite'] ? 0 : 1;
    db()->prepare('UPDATE beneficiaries SET is_favorite = ? WHERE id = ?')->execute([$newValue, $id]);
    $row['is_favorite'] = $newValue;
    respond(['recipient' => beneficiary_to_json($row)]);
}
