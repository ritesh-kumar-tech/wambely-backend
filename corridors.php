<?php
require __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'requirements') {
    require_auth();
    $countryCode = strtoupper((string) ($_GET['countryCode'] ?? ''));
    respond(['corridor' => [
        'countryCode' => $countryCode,
        'fields' => corridor_requirements($countryCode),
    ]]);
} else {
    fail('Unsupported request.', 405);
}
