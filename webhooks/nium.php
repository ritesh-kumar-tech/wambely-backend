<?php
require __DIR__ . '/../config.php';

// No auth token here — Nium calls this directly, not a logged-in user.
// Trust nothing until the signature verifies.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Unsupported request.', 405);
}

$rawBody = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? getallheaders() : [];

$webhookSecret = (string) (getenv('NIUM_WEBHOOK_SECRET') ?: '');
$verifier = new \Nium\NiumWebhookService($webhookSecret);

// Deliberately returns false until the real signature header/algorithm is
// confirmed from the Nium Portal (see NiumWebhookService's doc comment) —
// so this endpoint safely rejects everything rather than trusting an
// unverified payload. Once that's wired, remove this early return.
if (!$verifier->verifySignature($rawBody, $headers)) {
    http_response_code(401);
    error_log('[nium-webhook] rejected: signature verification not yet implemented (or invalid signature)');
    exit;
}

$payload = json_decode($rawBody, true);
$payload = is_array($payload) ? $payload : [];

$eventId = (string) ($payload['eventId'] ?? $payload['id'] ?? '');
$eventType = (string) ($payload['eventType'] ?? $payload['type'] ?? 'unknown');

if ($eventId === '') {
    fail('Missing event id.', 400);
}

// Idempotent: a redelivered webhook (Nium retries on timeout) is a no-op
// the second time, thanks to the unique index on event_id.
try {
    db()->prepare('INSERT INTO nium_webhook_events (event_id, event_type, payload, status) VALUES (?, ?, ?, ?)')
        ->execute([$eventId, $eventType, $rawBody, 'received']);
} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        respond(['ok' => true, 'note' => 'duplicate event, already processed']);
    }
    throw $e;
}

// Real event -> transaction status mapping goes here once the actual
// payload shape (which field carries the Nium transaction/remittance id,
// and the exact status vocabulary) is confirmed.

db()->prepare("UPDATE nium_webhook_events SET status = 'processed', processed_at = NOW() WHERE event_id = ?")
    ->execute([$eventId]);

respond(['ok' => true]);
