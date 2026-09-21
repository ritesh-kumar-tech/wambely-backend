<?php
require __DIR__ . '/config.php';

$userId = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    get_preferences($userId);
} elseif ($method === 'PUT') {
    update_preferences($userId);
} else {
    fail('Unsupported request.', 405);
}

function preferences_to_json(array $u): array {
    return [
        'transferUpdates' => (bool) $u['notify_transfer_updates'],
        'securityAlerts' => (bool) $u['notify_security_alerts'],
        'rateAlerts' => (bool) $u['notify_rate_alerts'],
        'promotions' => (bool) $u['notify_promotions'],
    ];
}

function get_preferences(string $userId): void {
    $stmt = db()->prepare(
        'SELECT notify_transfer_updates, notify_security_alerts, notify_rate_alerts, notify_promotions
         FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) fail('User not found.', 404);
    respond(['preferences' => preferences_to_json($row)]);
}

function update_preferences(string $userId): void {
    $body = json_input();
    db()->prepare(
        'UPDATE users SET notify_transfer_updates = ?, notify_security_alerts = ?, notify_rate_alerts = ?, notify_promotions = ? WHERE id = ?'
    )->execute([
        !empty($body['transferUpdates']) ? 1 : 0,
        !empty($body['securityAlerts']) ? 1 : 0,
        !empty($body['rateAlerts']) ? 1 : 0,
        !empty($body['promotions']) ? 1 : 0,
        $userId,
    ]);
    get_preferences($userId);
}
