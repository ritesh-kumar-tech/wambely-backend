<?php
require __DIR__ . '/config.php';

$userId = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$currentToken = preg_match('/^Bearer\s+(.+)$/i', bearer_header(), $m) ? $m[1] : null;

if ($method === 'GET') {
    $stmt = db()->prepare(
        'SELECT token, device_name, last_active_at FROM auth_tokens WHERE user_id = ? AND revoked = 0 ORDER BY last_active_at DESC'
    );
    $stmt->execute([$userId]);
    $sessions = array_map(function ($row) use ($currentToken) {
        return [
            'id' => $row['token'],
            'deviceName' => $row['device_name'],
            'location' => 'Unknown',
            'lastActive' => str_replace(' ', 'T', $row['last_active_at']),
            'isCurrent' => $row['token'] === $currentToken,
        ];
    }, $stmt->fetchAll());
    respond(['sessions' => $sessions]);
} elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    db()->prepare('UPDATE auth_tokens SET revoked = 1 WHERE token = ? AND user_id = ?')->execute([$id, $userId]);
    respond(['ok' => true]);
} else {
    fail('Method not allowed.', 405);
}
