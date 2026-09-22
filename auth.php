<?php
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'register':
        if ($method !== 'POST') fail('Method not allowed.', 405);
        register();
        break;
    case 'verify_otp':
        if ($method !== 'POST') fail('Method not allowed.', 405);
        verify_otp();
        break;
    case 'resend_otp':
        if ($method !== 'POST') fail('Method not allowed.', 405);
        resend_otp();
        break;
    case 'login':
        if ($method !== 'POST') fail('Method not allowed.', 405);
        login();
        break;
    case 'google':
        if ($method !== 'POST') fail('Method not allowed.', 405);
        google_signin();
        break;
    case 'me':
        if ($method !== 'GET') fail('Method not allowed.', 405);
        me();
        break;
    case 'logout':
        if ($method !== 'POST') fail('Method not allowed.', 405);
        logout();
        break;
    case 'biometric':
        if ($method !== 'PUT') fail('Method not allowed.', 405);
        set_flag('biometric_enabled');
        break;
    case 'mfa':
        if ($method !== 'PUT') fail('Method not allowed.', 405);
        set_flag('mfa_enabled');
        break;
    case 'profile':
        if ($method !== 'PATCH') fail('Method not allowed.', 405);
        update_profile();
        break;
    case 'change_password':
        if ($method !== 'POST') fail('Method not allowed.', 405);
        change_password();
        break;
    case 'forgot_password':
        if ($method !== 'POST') fail('Method not allowed.', 405);
        // No email sender wired up yet — acknowledge without leaking whether the address exists.
        respond(['ok' => true]);
        break;
    default:
        fail('Unknown action.', 404);
}

function register(): void {
    $body = json_input();
    $fullName = trim((string) ($body['fullName'] ?? ''));
    $email = trim(strtolower((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');

    if ($fullName === '' || $email === '' || strlen($password) < 8) {
        fail('Full name, a valid email, and a password of at least 8 characters are required.');
    }

    $existing = db()->prepare('SELECT id FROM users WHERE email = ?');
    $existing->execute([$email]);
    if ($existing->fetch()) {
        fail('An account with that email already exists.', 409);
    }

    $requestId = new_id('req');
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $stmt = db()->prepare(
        'INSERT INTO otp_requests (request_id, full_name, email, password_hash, code, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $stmt->execute([$requestId, $fullName, $email, password_hash($password, PASSWORD_DEFAULT), $code]);

    $debugCode = send_otp_email($email, $code);
    respond(array_filter(['requestId' => $requestId, 'debug_code' => $debugCode], fn($v) => $v !== null));
}

/**
 * Sends the OTP by real email. Returns the code back to the caller ONLY if
 * sending failed (network hiccup, Brevo outage, misconfigured .env) — a
 * safety net so testing/registration isn't blocked, not a permanent
 * feature. Once email delivery is confirmed reliable in production, this
 * whole fallback (and the `debug_code` field) should be deleted outright.
 */
function send_otp_email(string $email, string $code): ?string {
    try {
        send_email(
            $email,
            'Your Wambely verification code',
            "Your Wambely verification code is: $code\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this email."
        );
        return null;
    } catch (\MailerException $e) {
        error_log('[otp-email] send failed, falling back to debug_code: ' . $e->getMessage());
        return $code;
    }
}

function verify_otp(): void {
    $body = json_input();
    $requestId = (string) ($body['requestId'] ?? '');
    $code = (string) ($body['code'] ?? '');
    $deviceName = (string) ($body['deviceName'] ?? 'Unknown device');

    $stmt = db()->prepare('SELECT * FROM otp_requests WHERE request_id = ? AND consumed = 0');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) fail('That verification request is invalid or already used.');
    if (strtotime($req['expires_at']) < time()) fail('That code has expired. Please request a new one.');
    if (!hash_equals($req['code'], $code)) fail('Incorrect verification code.');

    db()->prepare('UPDATE otp_requests SET consumed = 1 WHERE request_id = ?')->execute([$requestId]);

    $userId = new_id('usr');
    db()->prepare('INSERT INTO users (id, full_name, email, password_hash) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $req['full_name'], $req['email'], $req['password_hash']]);

    respond(issue_session($userId, $deviceName));
}

function resend_otp(): void {
    $body = json_input();
    $requestId = (string) ($body['requestId'] ?? '');

    $stmt = db()->prepare('SELECT email FROM otp_requests WHERE request_id = ? AND consumed = 0');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    if (!$row) fail('That verification request is invalid or already used.');

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare('UPDATE otp_requests SET code = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE request_id = ?')
        ->execute([$code, $requestId]);

    $debugCode = send_otp_email($row['email'], $code);
    respond(array_filter(['ok' => true, 'debug_code' => $debugCode], fn($v) => $v !== null));
}

function login(): void {
    $body = json_input();
    $identifier = trim(strtolower((string) ($body['identifier'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $deviceName = (string) ($body['deviceName'] ?? 'Unknown device');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR phone = ?');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        fail('Incorrect email/phone or password.', 401);
    }

    respond(issue_session($user['id'], $deviceName));
}

function google_signin(): void {
    $body = json_input();
    $idToken = (string) ($body['idToken'] ?? '');
    if ($idToken === '') fail('Missing Google ID token.');

    $clientId = (string) getenv('GOOGLE_CLIENT_ID');
    if ($clientId === '') {
        fail('Google Sign-In is not configured on this server yet.', 501);
    }

    $payload = verify_google_id_token($idToken, $clientId);
    if ($payload === null) {
        fail('That Google sign-in could not be verified. Please try again.', 401);
    }

    $googleSub = (string) $payload['sub'];
    $email = strtolower((string) ($payload['email'] ?? ''));
    $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $fullName = (string) ($payload['name'] ?? $email);

    if ($email === '' || !$emailVerified) {
        fail('Your Google account email is not verified.', 401);
    }

    // Match an existing account first by google_sub, then by email (lets
    // someone who registered with email/password link their Google account
    // just by signing in with Google using the same address), else create one.
    $stmt = db()->prepare('SELECT id FROM users WHERE google_sub = ?');
    $stmt->execute([$googleSub]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            db()->prepare('UPDATE users SET google_sub = ? WHERE id = ?')->execute([$googleSub, $userId]);
        } else {
            $userId = new_id('usr');
            // A Google-only account still needs *some* password_hash to
            // satisfy that column's NOT NULL — a random value that's never
            // shared with the user and so can't be used via the password form.
            $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            db()->prepare('INSERT INTO users (id, full_name, email, google_sub, password_hash) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $fullName, $email, $googleSub, $randomPassword]);
        }
    }

    respond(issue_session($userId, (string) ($body['deviceName'] ?? 'Google sign-in')));
}

/** Verifies a Google ID token via Google's tokeninfo endpoint; returns its claims, or null if invalid/wrong audience/issuer. */
function verify_google_id_token(string $idToken, string $expectedClientId): ?array {
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status !== 200) return null;
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) return null;
    if (($payload['aud'] ?? null) !== $expectedClientId) return null;
    if (!in_array($payload['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) return null;

    return $payload;
}

function issue_session(string $userId, string $deviceName): array {
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO auth_tokens (token, user_id, device_name) VALUES (?, ?, ?)')
        ->execute([$token, $userId, $deviceName]);

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return ['token' => $token, 'user' => user_to_json($stmt->fetch())];
}

function me(): void {
    $userId = require_auth();
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) fail('User not found.', 404);
    respond(['user' => user_to_json($user)]);
}

function logout(): void {
    $header = bearer_header();
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        db()->prepare('UPDATE auth_tokens SET revoked = 1 WHERE token = ?')->execute([$m[1]]);
    }
    respond(['ok' => true]);
}

function set_flag(string $column): void {
    $userId = require_auth();
    $body = json_input();
    $enabled = (bool) ($body['enabled'] ?? false);
    db()->prepare("UPDATE users SET $column = ? WHERE id = ?")->execute([$enabled ? 1 : 0, $userId]);
    respond(['ok' => true]);
}

function update_profile(): void {
    $userId = require_auth();
    $body = json_input();
    $fullName = trim((string) ($body['fullName'] ?? ''));
    $email = trim(strtolower((string) ($body['email'] ?? '')));
    $phone = $body['phone'] ?? null;

    if ($fullName === '' || $email === '') fail('Full name and email are required.');

    $existing = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $existing->execute([$email, $userId]);
    if ($existing->fetch()) fail('That email is already in use.', 409);

    db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?')
        ->execute([$fullName, $email, $phone, $userId]);
    respond(['ok' => true]);
}

function change_password(): void {
    $userId = require_auth();
    $body = json_input();
    $current = (string) ($body['currentPassword'] ?? '');
    $new = (string) ($body['newPassword'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($current, $user['password_hash'])) {
        fail('Current password is incorrect.', 401);
    }
    if (strlen($new) < 8) fail('New password must be at least 8 characters.');

    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
    create_notification($userId, 'security', 'Password changed', 'Your password was changed successfully.');
    respond(['ok' => true]);
}
