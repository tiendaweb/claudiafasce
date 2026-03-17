<?php

declare(strict_types=1);

require_once __DIR__ . '/url.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/json-store.php';

const ROLE_SUPER_ADMIN = 'super_admin';
const ROLE_TENANT_ADMIN = 'tenant_admin';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function users_path(?string $tenantId = null): string
{
    $tenantId = sanitize_tenant_id($tenantId ?? resolve_tenant_id());

    return tenant_file_path($tenantId, 'users.json');
}

function global_users_path(): string
{
    return dirname(__DIR__) . '/data/users.json';
}

function normalize_user_record(array $user, string $defaultRole, ?string $tenantId = null): array
{
    $id = (string) ($user['id'] ?? uniqid('u_', true));
    $username = trim((string) ($user['username'] ?? ''));

    return [
        'id' => $id,
        'username' => $username,
        'name' => trim((string) ($user['name'] ?? '')),
        'password_hash' => (string) ($user['password_hash'] ?? ''),
        'role' => ($user['role'] ?? $defaultRole) === ROLE_SUPER_ADMIN ? ROLE_SUPER_ADMIN : ROLE_TENANT_ADMIN,
        'tenant_id' => $tenantId,
    ];
}

function all_users(?string $tenantId = null): array
{
    $tenantId = sanitize_tenant_id($tenantId ?? resolve_tenant_id());
    run_initial_tenant_migration($tenantId);

    $decoded = read_json_file(users_path($tenantId), []);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_map(
        static fn (array $u): array => normalize_user_record($u, ROLE_TENANT_ADMIN, $tenantId),
        array_filter($decoded, 'is_array')
    ));
}

function global_users(): array
{
    $decoded = read_json_file(global_users_path(), []);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_map(
        static fn (array $u): array => normalize_user_record($u, ROLE_SUPER_ADMIN, null),
        array_filter($decoded, 'is_array')
    ));
}

function has_users(?string $tenantId = null): bool
{
    return count(all_users($tenantId)) > 0;
}

function has_global_users(): bool
{
    return count(global_users()) > 0;
}

function save_users(array $users, ?string $tenantId = null): bool
{
    $tenantId = sanitize_tenant_id($tenantId ?? resolve_tenant_id());
    if (!ensure_tenant_directories($tenantId)) {
        return false;
    }

    $normalized = array_values(array_map(
        static fn (array $u): array => normalize_user_record($u, ROLE_TENANT_ADMIN, $tenantId),
        array_filter($users, 'is_array')
    ));

    return write_json_file_atomic(users_path($tenantId), $normalized);
}

function save_global_users(array $users): bool
{
    $normalized = array_values(array_map(
        static fn (array $u): array => normalize_user_record($u, ROLE_SUPER_ADMIN, null),
        array_filter($users, 'is_array')
    ));

    return write_json_file_atomic(global_users_path(), $normalized);
}

function find_user_by_username(string $username, ?string $tenantId = null): ?array
{
    foreach (all_users($tenantId) as $user) {
        if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
            return $user;
        }
    }

    return null;
}

function find_global_user_by_username(string $username): ?array
{
    foreach (global_users() as $user) {
        if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
            return $user;
        }
    }

    return null;
}

function current_user(): ?array
{
    $userId = (string) ($_SESSION['user_id'] ?? '');
    $scope = (string) ($_SESSION['auth_scope'] ?? 'tenant');
    if ($userId === '') {
        return null;
    }

    $users = $scope === 'global' ? global_users() : all_users((string) ($_SESSION['tenant_id'] ?? resolve_tenant_id()));
    foreach ($users as $user) {
        if ((string) ($user['id'] ?? '') === $userId) {
            return $user;
        }
    }

    return null;
}

function is_super_admin(): bool
{
    $user = current_user();

    return $user !== null && (string) ($user['role'] ?? '') === ROLE_SUPER_ADMIN;
}

function require_auth(?string $tenantId = null): void
{
    $user = current_user();
    if ($user === null) {
        header('Location: ' . url_for('/login'));
        exit;
    }

    $scope = (string) ($_SESSION['auth_scope'] ?? 'tenant');
    if ($tenantId !== null) {
        if ($scope !== 'tenant') {
            http_response_code(403);
            exit('Acceso denegado para este tenant.');
        }

        $resolvedTenant = sanitize_tenant_id($tenantId);
        $sessionTenant = sanitize_tenant_id((string) ($_SESSION['tenant_id'] ?? ''));
        if ($resolvedTenant !== $sessionTenant) {
            http_response_code(403);
            exit('Acceso denegado para este tenant.');
        }
    }
}

function require_super_admin(): void
{
    require_auth();
    if (!is_super_admin()) {
        http_response_code(403);
        exit('Requiere rol super-admin.');
    }
}
