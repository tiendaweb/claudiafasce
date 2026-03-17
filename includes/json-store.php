<?php

declare(strict_types=1);

function read_json_file(string $path, $fallback)
{
    if (!is_file($path)) {
        return $fallback;
    }

    $raw = @file_get_contents($path);
    $decoded = json_decode($raw ?: 'null', true);

    return $decoded ?? $fallback;
}

function write_json_file_atomic(string $path, $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $tmp = $path . '.tmp.' . uniqid('', true);
    $lockPath = $path . '.lock';
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false) {
        return false;
    }

    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        return false;
    }

    $tmpHandle = @fopen($tmp, 'wb');
    if ($tmpHandle === false) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return false;
    }

    $ok = fwrite($tmpHandle, $json . PHP_EOL) !== false;
    fflush($tmpHandle);
    fclose($tmpHandle);

    if (!$ok || !@rename($tmp, $path)) {
        @unlink($tmp);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return false;
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    return true;
}
