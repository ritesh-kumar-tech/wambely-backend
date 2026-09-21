<?php
// One-time CLI setup script — creates (or updates the password of) an
// admin panel login. Run from a terminal, not over HTTP:
//   php create_admin.php <username> <password>

require __DIR__ . '/admin/includes/db.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line, not a browser.\n");
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php create_admin.php <username> <password>\n");
    exit(1);
}

[, $username, $password] = $argv;

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = db()->prepare(
    'INSERT INTO admin_users (username, password_hash) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
);
$stmt->execute([$username, $hash]);

echo "Admin user '$username' is ready.\n";
