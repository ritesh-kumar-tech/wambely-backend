<?php
require __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'status') {
    status();
} elseif ($method === 'POST' && $action === 'personal_details') {
    submit_personal_details();
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

/**
 * Step 1 of real KYC: personal details Nium's customer-creation API needs
 * beyond name/email/phone. Just saved to `users` here — the actual Nium
 * customer isn't created until submit_document() below, which is the only
 * place that also has the ID document to include in that one-shot call.
 */
function submit_personal_details(): void {
    $userId = require_auth();
    $body = json_input();

    $dateOfBirth = (string) ($body['dateOfBirth'] ?? '');
    $nationality = strtoupper((string) ($body['nationality'] ?? ''));
    $mobileCountryCode = (string) ($body['mobileCountryCode'] ?? '');
    $mobileNumber = trim((string) ($body['mobileNumber'] ?? ''));
    $addressLine1 = trim((string) ($body['addressLine1'] ?? ''));
    $addressCity = trim((string) ($body['addressCity'] ?? ''));
    $addressState = trim((string) ($body['addressState'] ?? ''));
    $addressPostalCode = trim((string) ($body['addressPostalCode'] ?? ''));
    $addressCountry = strtoupper((string) ($body['addressCountry'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) fail('A valid date of birth (YYYY-MM-DD) is required.');
    if (strlen($nationality) !== 2) fail('Nationality must be a 2-letter country code.');
    if ($mobileCountryCode === '' || $mobileNumber === '') fail('A mobile number with country code is required.');
    if ($addressLine1 === '' || $addressCity === '' || $addressPostalCode === '' || strlen($addressCountry) !== 2) {
        fail('Address line 1, city, postal code, and a 2-letter country code are all required.');
    }

    db()->prepare(
        'UPDATE users SET date_of_birth = ?, nationality = ?, mobile_country_code = ?, phone = ?,
            address_line1 = ?, address_city = ?, address_state = ?, address_postal_code = ?, address_country = ?
         WHERE id = ?'
    )->execute([
        $dateOfBirth, $nationality, $mobileCountryCode, $mobileNumber,
        $addressLine1, $addressCity, $addressState, $addressPostalCode, $addressCountry,
        $userId,
    ]);

    respond(['ok' => true]);
}

/**
 * Step 2: the ID document. For a brand-new customer (stub or real Nium),
 * this is the single call that actually creates the provider customer —
 * Nium's "Unified Add Customer" takes the document inline, there's no
 * separate upload-after-create step. For a customer that already exists
 * (e.g. re-running this after an earlier partial attempt), it's treated as
 * a resubmission: just refresh status rather than trying to create twice.
 *
 * The uploaded file is relayed straight through in $profile and never
 * written to our own database (see migration_009's comment) — document
 * metadata (type/number/issuance/expiry) IS kept, for the user's own
 * reference, but never the image itself.
 */
function submit_document(): void {
    $userId = require_auth();
    $body = json_input();

    $documentType = (string) ($body['documentType'] ?? '');
    $documentNumber = trim((string) ($body['documentNumber'] ?? ''));
    $documentIssuanceCountry = strtoupper((string) ($body['documentIssuanceCountry'] ?? ''));
    $documentExpiry = (string) ($body['documentExpiry'] ?? '');
    $fileBase64 = (string) ($body['fileBase64'] ?? '');
    $fileName = (string) ($body['fileName'] ?? 'document.jpg');
    $fileType = (string) ($body['fileType'] ?? 'image/jpeg');

    if ($documentNumber === '' || strlen($documentIssuanceCountry) !== 2 || $fileBase64 === '') {
        fail('Document number, a 2-letter issuance country, and the document photo are all required.');
    }

    db()->prepare(
        'UPDATE users SET kyc_document_type = ?, kyc_document_number = ?,
            kyc_document_issuance_country = ?, kyc_document_expiry = ? WHERE id = ?'
    )->execute([$documentType, $documentNumber, $documentIssuanceCountry, $documentExpiry ?: null, $userId]);

    $stmt = db()->prepare('SELECT nium_customer_hash_id FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $existingRef = $stmt->fetchColumn();

    $provider = \Provider\money_transfer_provider();

    if ($existingRef) {
        // Already created — this is a resubmission; just refresh status.
        $result = $provider->getCustomerStatus($existingRef);
        respond(['kycState' => sync_kyc_state($userId, $result['status'])]);
    }

    $profile = array_merge(build_kyc_profile($userId), [
        'documentType' => $documentType,
        'documentNumber' => $documentNumber,
        'documentIssuanceCountry' => $documentIssuanceCountry,
        'documentExpiry' => $documentExpiry,
        'documentFileName' => $fileName,
        'documentFileType' => $fileType,
        'documentFileBase64' => $fileBase64,
    ]);

    $result = $provider->createCustomer($userId, $profile);
    respond(['kycState' => sync_kyc_state($userId, $result['status'])]);
}

function submit_selfie(): void {
    $userId = require_auth();
    $customerRef = ensure_customer_ref($userId);
    $result = \Provider\money_transfer_provider()->submitKycSelfie($userId, $customerRef);
    respond(['kycState' => sync_kyc_state($userId, $result['status'])]);
}
