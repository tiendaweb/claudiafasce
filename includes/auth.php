<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function users_path(): string
{
    return dirname(__DIR__) . '/data/users.json';
}

function all_users(): array
{
    $path = users_path();
    if (!file_exists($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $decoded = json_decode($raw ?: '[]', true);

    return is_array($decoded) ? $decoded : [];
}

function find_user_by_username(string $username): ?array
{
    foreach (all_users() as $user) {
        if (isset($user['username']) && strcasecmp((string) $user['username'], $username) === 0) {
            return $user;
        }
    }

    return null;
}

function current_user(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId === null) {
        return null;
    }

    foreach (all_users() as $user) {
        if (isset($user['id']) && (string) $user['id'] === (string) $userId) {
            return $user;
        }
    }

    return null;
}

function require_auth(): void
{
    if (current_user() !== null) {
        return;
    }

    header('Location: /login');
    exit;
}
