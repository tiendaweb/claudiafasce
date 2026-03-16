<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_auth();

header('Content-Type: application/json; charset=utf-8');

function log_save_error(string $message): void
{
    $logDir = __DIR__ . '/../logs';
    $logFile = $logDir . '/app.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $entry = sprintf("[%s] save-content: %s\n", date('c'), $message);
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function validate_payload_schema(array $data): bool
{
    $requiredTopLevel = [
        'site' => 'array',
        'hero' => 'array',
        'stats' => 'array',
        'tabs' => 'array',
        'backgrounds' => 'array',
    ];

    foreach ($requiredTopLevel as $key => $type) {
        if (!array_key_exists($key, $data) || gettype($data[$key]) !== $type) {
            return false;
        }
    }

    $requiredSite = ['lang', 'title', 'name', 'tagline', 'availability', 'nav'];
    foreach ($requiredSite as $key) {
        if (!array_key_exists($key, $data['site'])) {
            return false;
        }
    }

    if (!is_array($data['site']['nav'])) {
        return false;
    }

    $requiredHero = ['headline_prefix', 'headline_highlight', 'headline_suffix', 'description'];
    foreach ($requiredHero as $key) {
        if (!array_key_exists($key, $data['hero']) || !is_string($data['hero'][$key])) {
            return false;
        }
    }

    return true;
}

function save_content_file(array $data): bool
{
    $target = __DIR__ . '/../data/content.json';
    $targetDir = dirname($target);
    $lockPath = $targetDir . '/content.json.lock';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        log_save_error('Falló json_encode para el contenido.');
        return false;
    }

    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        log_save_error('No se pudo abrir el archivo de lock.');
        return false;
    }

    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        log_save_error('No se pudo adquirir lock exclusivo.');
        return false;
    }

    $tempPath = tempnam($targetDir, 'content_');
    if ($tempPath === false) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        log_save_error('No se pudo crear archivo temporal.');
        return false;
    }

    $writeOk = file_put_contents($tempPath, $json . PHP_EOL) !== false;
    if ($writeOk) {
        $writeOk = rename($tempPath, $target);
    }

    if (!$writeOk && file_exists($tempPath)) {
        @unlink($tempPath);
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    if (!$writeOk) {
        log_save_error('Falló la escritura atómica de content.json.');
    }

    return $writeOk;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$payload = file_get_contents('php://input');
$data = json_decode($payload ?: '', true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

if (!validate_payload_schema($data)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Payload inválido']);
    exit;
}

if (!save_content_file($data)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar']);
    exit;
}

echo json_encode(['ok' => true]);
