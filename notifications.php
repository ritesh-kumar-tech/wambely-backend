<?php
require __DIR__ . '/config.php';

$userId = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    list_notifications($userId);
} elseif ($method === 'POST' && $action === 'mark_read') {
    mark_read($userId);
} elseif ($method === 'POST' && $action === 'mark_all_read') {
    mark_all_read($userId);
} else {
    fail('Unsupported request.', 405);
}

function notification_to_json(array $n): array {
    return [
        'id' => $n['id'],
        'kind' => $n['kind'],
        'title' => $n['title'],
        'body' => $n['body'],
        'createdAt' => str_replace(' ', 'T', $n['created_at']),
        'read' => (bool) $n['is_read'],
    ];
}

function list_notifications(string $userId): void {
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$userId]);
    respond(['notifications' => array_map('notification_to_json', $stmt->fetchAll())]);
}

function mark_read(string $userId): void {
    $id = (string) ($_GET['id'] ?? '');
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    respond(['ok' => true]);
}

function mark_all_read(string $userId): void {
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
    respond(['ok' => true]);
}
