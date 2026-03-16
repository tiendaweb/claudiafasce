<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$payload = file_get_contents('php://input');
$data = json_decode($payload ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

$target = __DIR__ . '/../data/content.json';
if (file_put_contents($target, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL) === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);
