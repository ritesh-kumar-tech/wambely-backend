<?php

namespace Nium;

/**
 * Verifies and interprets inbound Nium webhook calls.
 *
 * NOT YET WIRED: the exact signature header name + algorithm need
 * confirming from the Nium Portal before this can safely trust an inbound
 * payload — see the "Open items" section of the Phase 1 plan. Until then,
 * verifySignature() always returns false so webhooks/nium.php cannot be
 * accidentally left open to spoofed status updates.
 */
final class NiumWebhookService {
    public function __construct(private readonly string $webhookSecret = '') {}

    public function verifySignature(string $rawBody, array $headers): bool {
        return false;
    }
}
