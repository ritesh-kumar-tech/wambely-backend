<?php

namespace Nium;

/** Thrown for any non-2xx response from the Nium API, or a transport failure after retries. */
class NiumApiException extends \RuntimeException {
    /** @param array<string, mixed> $responseBody */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $responseBody = [],
    ) {
        parent::__construct($message);
    }
}

/**
 * The one place that builds a Nium HTTP request. Every Nium*Service calls
 * through here so auth headers, retries, timeouts, and logging only exist
 * in one place — nothing else should curl_init() a Nium URL directly.
 */
final class NiumClient {
    private const TIMEOUT_SECONDS = 20;
    private const MAX_RETRIES = 2;

    private readonly NiumConfig $config;

    public function __construct(?NiumConfig $config = null) {
        $this->config = $config ?? NiumConfig::instance();
    }

    /** @param array<string, scalar> $query */
    public function get(string $path, array $query = []): array {
        return $this->request('GET', $path, query: $query);
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array {
        return $this->request('POST', $path, body: $body, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string, mixed> $body */
    public function put(string $path, array $body = []): array {
        return $this->request('PUT', $path, body: $body);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        ?string $idempotencyKey = null,
    ): array {
        $url = $this->config->baseUrl . $path;
        if ($query) $url .= '?' . http_build_query($query);

        $headers = [
            'x-api-key: ' . $this->config->apiKey,
            'clientHashId: ' . $this->config->clientHashId,
            'Content-Type: application/json',
            'Accept: application/json',
            'x-request-id: ' . bin2hex(random_bytes(16)),
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES + 1; $attempt++) {
            $startedAt = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_POSTFIELDS => $method === 'GET' ? null : json_encode($body),
            ]);
            $raw = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($curlErrno !== 0) {
                self::log($method, $path, 0, $durationMs, "transport_error:$curlError");
                $lastError = new NiumApiException("Could not reach Nium: $curlError", 0);
                if ($attempt <= self::MAX_RETRIES) { usleep(300_000 * $attempt); continue; }
                throw $lastError;
            }

            $decoded = json_decode((string) $raw, true);
            $decoded = is_array($decoded) ? $decoded : [];

            self::log($method, $path, $statusCode, $durationMs, null);

            if ($statusCode >= 200 && $statusCode < 300) {
                return $decoded;
            }

            if ($statusCode >= 500 && $attempt <= self::MAX_RETRIES) {
                $lastError = new NiumApiException("Nium returned HTTP $statusCode", $statusCode, $decoded);
                usleep(300_000 * $attempt);
                continue;
            }

            throw new NiumApiException(
                (string) ($decoded['message'] ?? $decoded['errorMessage'] ?? "Nium returned HTTP $statusCode"),
                $statusCode,
                $decoded
            );
        }

        throw $lastError ?? new NiumApiException('Nium request failed after retries.', 0);
    }

    /**
     * Endpoint + status + duration only — never the API key, never a full
     * response body (which can carry customer PII / bank details).
     */
    private static function log(string $method, string $path, int $status, int $durationMs, ?string $error): void {
        error_log(sprintf(
            '[nium] %s %s -> %d (%dms)%s',
            $method,
            $path,
            $status,
            $durationMs,
            $error ? " ERROR=$error" : ''
        ));
    }
}
