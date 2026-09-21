<?php

namespace Nium;

/** Thrown when required Nium configuration is missing or invalid. */
class NiumConfigException extends \RuntimeException {}

/**
 * Resolves which Nium credentials/base URL to use based on NIUM_ENV, and
 * fails fast with a clear message if anything required is missing — rather
 * than a Nium call failing three layers deep with a cryptic 401.
 *
 * Deliberately NOT validated at bootstrap for every request (auth.php,
 * transactions.php etc. don't touch Nium and must keep working even before
 * .env is configured) — only when something actually needs to talk to Nium.
 */
final class NiumConfig {
    public readonly string $env;
    public readonly string $baseUrl;
    public readonly string $apiKey;
    public readonly string $clientHashId;
    public readonly string $webhookSecret;

    private static ?self $instance = null;

    private function __construct() {
        $this->env = strtolower((string) (getenv('NIUM_ENV') ?: 'sandbox'));
        if (!in_array($this->env, ['sandbox', 'production'], true)) {
            throw new NiumConfigException("NIUM_ENV must be 'sandbox' or 'production', got '{$this->env}'.");
        }

        $baseUrlVar = $this->env === 'production' ? 'NIUM_PRODUCTION_BASE_URL' : 'NIUM_SANDBOX_BASE_URL';
        $this->baseUrl = rtrim((string) getenv($baseUrlVar), '/');
        $this->apiKey = (string) getenv('NIUM_API_KEY');
        $this->clientHashId = (string) getenv('NIUM_CLIENT_HASH_ID');
        $this->webhookSecret = (string) getenv('NIUM_WEBHOOK_SECRET');

        $missing = [];
        if ($this->baseUrl === '') $missing[] = $baseUrlVar;
        if ($this->apiKey === '') $missing[] = 'NIUM_API_KEY';
        if ($this->clientHashId === '') $missing[] = 'NIUM_CLIENT_HASH_ID';
        if ($missing) {
            throw new NiumConfigException(
                'Missing required Nium configuration: ' . implode(', ', $missing) .
                '. Copy .env.example to .env (in the wambely_api folder) and fill these in.'
            );
        }
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    /** For tests/tooling that need to force a fresh read of the environment. */
    public static function reset(): void {
        self::$instance = null;
    }
}
