<?php

declare(strict_types=1);

function base_url(): string
{
    $projectRoot = realpath(dirname(__DIR__));
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($projectRoot !== false && $documentRoot !== false) {
        $normalizedProjectRoot = str_replace('\\', '/', $projectRoot);
        $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

        if ($normalizedDocumentRoot !== '' && str_starts_with($normalizedProjectRoot, $normalizedDocumentRoot)) {
            $relativePath = substr($normalizedProjectRoot, strlen($normalizedDocumentRoot));
            $relativePath = $relativePath === false ? '' : $relativePath;

            if ($relativePath === '' || $relativePath === '/') {
                return '';
            }

            return '/' . trim($relativePath, '/');
        }
    }

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName === '') {
        return '';
    }

    $scriptDir = dirname($scriptName);
    if ($scriptDir === '/' || $scriptDir === '.') {
        return '';
    }

    return '/' . trim($scriptDir, '/');
}

function url_for(string $path = ''): string
{
    $normalizedPath = ltrim($path, '/');
    $base = base_url();

    if ($normalizedPath === '') {
        return $base === '' ? '/' : $base . '/';
    }

    if ($base === '') {
        return '/' . $normalizedPath;
    }

    return $base . '/' . $normalizedPath;
}
