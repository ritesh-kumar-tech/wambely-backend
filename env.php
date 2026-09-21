<?php
// Loads KEY=VALUE pairs from .env (gitignored, never committed) into
// getenv()/$_ENV — this is how Nium credentials and production DB
// credentials are configured. See .env.example for the full list. No
// framework here, so this is a deliberately tiny parser rather than
// pulling in a Composer dependency. Runs BEFORE the DB_* consts below so
// production values in .env can override the local-dev defaults.
(function (): void {
    $path = __DIR__ . '/.env';
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === $value[-1] && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
})();

// DB credentials for both the app-facing API (config.php) and the admin
// panel (admin/includes/db.php). Defaults match a fresh local WAMP setup;
// a real deployment's .env sets DB_HOST/DB_NAME/DB_USER/DB_PASS to override these.
function db_host(): string { return (string) (getenv('DB_HOST') ?: 'localhost'); }
function db_name(): string { return (string) (getenv('DB_NAME') ?: 'wambely_api'); }
function db_user(): string { return (string) (getenv('DB_USER') ?: 'root'); }
function db_pass(): string { return (string) (getenv('DB_PASS') ?: ''); }
