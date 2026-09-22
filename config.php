<?php
// Shared bootstrap for every API endpoint: DB connection + small JSON/auth
// helpers. Admin panel pages use their own session-based auth (see
// admin/includes/guard.php) and do not include this file.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/env.php';
require __DIR__ . '/lib/autoload.php';
require __DIR__ . '/lib/mailer.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . db_host() . ';dbname=' . db_name() . ';charset=utf8mb4',
            db_user(),
            db_pass(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function fail(string $message, int $status = 400): never {
    respond(['error' => $message], $status);
}

function new_id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}

/**
 * Apache/PHP (mod_php or plain CGI, depending on config) commonly drops the
 * Authorization header from $_SERVER unless the vhost explicitly re-adds it
 * — `getallheaders()` still sees it, so fall back to that.
 */
function bearer_header(): string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) return $value;
        }
    }
    return '';
}

/** Validates the bearer token and returns the authenticated user's id, or fails the request. */
function require_auth(): string {
    $header = bearer_header();
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        fail('Missing or invalid Authorization header.', 401);
    }
    $token = $m[1];
    $stmt = db()->prepare('SELECT user_id FROM auth_tokens WHERE token = ? AND revoked = 0');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('Session expired. Please sign in again.', 401);
    }
    db()->prepare('UPDATE auth_tokens SET last_active_at = NOW() WHERE token = ?')->execute([$token]);
    return $row['user_id'];
}

function user_to_json(array $u): array {
    return [
        'id' => $u['id'],
        'fullName' => $u['full_name'],
        'email' => $u['email'],
        'phone' => $u['phone'],
        'kycState' => $u['kyc_state'],
        'biometricEnabled' => (bool) $u['biometric_enabled'],
        'mfaEnabled' => (bool) $u['mfa_enabled'],
    ];
}

/**
 * Phase 1 targets India specifically (US -> India, bank payout); other
 * countries get a generic bank-details form so the app doesn't hard-fail
 * if a different destination is picked, but only India has been tested
 * end-to-end. Add a corridor here once it's actually been verified.
 * Shared by corridors.php (what the app displays) and recipients.php
 * (what the backend actually requires — never trust the client's fields).
 */
function corridor_requirements(string $countryCode): array {
    $corridors = [
        'IN' => [
            ['key' => 'bankName', 'label' => 'Bank name', 'type' => 'text', 'required' => true],
            ['key' => 'accountNumber', 'label' => 'Account number', 'type' => 'text', 'required' => true, 'minLength' => 6],
            ['key' => 'ifsc', 'label' => 'IFSC code', 'type' => 'text', 'required' => true, 'minLength' => 11,
                'hint' => '11-character bank branch code, e.g. HDFC0000123'],
        ],
    ];
    $genericFallback = [
        ['key' => 'bankName', 'label' => 'Bank name', 'type' => 'text', 'required' => true],
        ['key' => 'accountNumber', 'label' => 'Account number', 'type' => 'text', 'required' => true, 'minLength' => 6],
    ];
    return $corridors[$countryCode] ?? $genericFallback;
}

/**
 * Creates (once) or returns the provider customer reference for a user —
 * lazy, so wallet/kyc/recipients endpoints all "just work" right after
 * login instead of requiring a separate blocking onboarding step first.
 */
function ensure_customer_ref(string $userId): string {
    $stmt = db()->prepare('SELECT nium_customer_hash_id, full_name, email, phone FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) fail('User not found.', 404);
    if ($user['nium_customer_hash_id']) return $user['nium_customer_hash_id'];

    $result = \Provider\money_transfer_provider()->createCustomer($userId, [
        'fullName' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
    ]);
    return $result['customerRef'];
}

/** True once the user's KYC/compliance status is fully approved. */
function kyc_is_approved(string $userId): bool {
    $stmt = db()->prepare('SELECT kyc_state FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() === 'approved';
}

/**
 * Records an in-app notification for a key event (transfer completed, KYC
 * decision, security change, ...). Called from wherever that event
 * actually happens (transfers.php, kyc.php, auth.php) rather than
 * reconstructed later from raw table state.
 */
function create_notification(string $userId, string $kind, string $title, string $body): void {
    db()->prepare('INSERT INTO notifications (id, user_id, kind, title, body) VALUES (?, ?, ?, ?, ?)')
        ->execute([new_id('notif'), $userId, $kind, $title, $body]);
}

function transaction_to_json(array $t): array {
    return [
        'id' => $t['id'],
        'type' => $t['type'],
        'status' => $t['status'],
        'amount' => (float) $t['amount'],
        'currencyCode' => $t['currency_code'],
        'createdAt' => str_replace(' ', 'T', $t['created_at']),
        'recipientName' => $t['recipient_name'],
        'recipientFlagEmoji' => $t['recipient_flag_emoji'],
        'fee' => (float) $t['fee'],
        'exchangeRate' => $t['exchange_rate'] !== null ? (float) $t['exchange_rate'] : null,
        'deliveryEstimate' => $t['delivery_estimate'],
        'reference' => $t['reference'],
        'paymentStatus' => $t['payment_status'],
        'complianceStatus' => $t['compliance_status'],
    ];
}
