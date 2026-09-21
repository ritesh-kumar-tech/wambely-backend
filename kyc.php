<?php
require __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'status') {
    status();
} elseif ($method === 'POST' && $action === 'document') {
    submit_document();
} elseif ($method === 'POST' && $action === 'selfie') {
    submit_selfie();
} else {
    fail('Unsupported request.', 405);
}

/** Persists the provider's raw status + the Flutter-facing KycState mapping, and returns the latter. */
function sync_kyc_state(string $userId, string $providerStatus): string {
    $kycState = \Nium\NiumCustomerService::mapComplianceStatus($providerStatus);

    $stmt = db()->prepare('SELECT kyc_state FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $previousState = $stmt->fetchColumn();

    db()->prepare('UPDATE users SET kyc_state = ?, nium_compliance_status = ? WHERE id = ?')
        ->execute([$kycState, $providerStatus, $userId]);

    if ($kycState !== $previousState) {
        $messages = [
            'approved' => ['Identity verified', "You're verified — you can now add recipients and send money."],
            'rejected' => ['Verification unsuccessful', 'We could not verify your identity. Please contact support.'],
            'actionRequired' => ['More information needed', 'We need a bit more information to finish verifying you.'],
        ];
        if (isset($messages[$kycState])) {
            create_notification($userId, 'kyc', $messages[$kycState][0], $messages[$kycState][1]);
        }
    }

    return $kycState;
}

function status(): void {
    $userId = require_auth();
    $stmt = db()->prepare('SELECT nium_customer_hash_id FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $customerRef = $stmt->fetchColumn();

    if (!$customerRef) {
        respond(['kycState' => 'notStarted']);
        return;
    }
    $result = \Provider\money_transfer_provider()->getCustomerStatus($customerRef);
    respond(['kycState' => sync_kyc_state($userId, $result['status'])]);
}

function submit_document(): void {
    $userId = require_auth();
    $body = json_input();
    $documentType = (string) ($body['documentType'] ?? 'id_document');

    $customerRef = ensure_customer_ref($userId);
    $result = \Provider\money_transfer_provider()->submitKycDocument($userId, $customerRef, $documentType);
    respond(['kycState' => sync_kyc_state($userId, $result['status'])]);
}

function submit_selfie(): void {
    $userId = require_auth();
    $customerRef = ensure_customer_ref($userId);
    $result = \Provider\money_transfer_provider()->submitKycSelfie($userId, $customerRef);
    respond(['kycState' => sync_kyc_state($userId, $result['status'])]);
}
