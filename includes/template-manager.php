<?php

declare(strict_types=1);

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/json-store.php';

function templates_dir_path(): string
{
    return dirname(__DIR__) . '/templates';
}

function global_template_registry_path(): string
{
    return dirname(__DIR__) . '/data/templates-index.json';
}

function template_registry_path(?string $tenantId = null): string
{
    $tenantId = sanitize_tenant_id($tenantId ?? resolve_tenant_id());

    return tenant_file_path($tenantId, 'templates-index.json');
}

function normalize_template_slugs(array $slugs): array
{
    $valid = [];
    foreach ($slugs as $slug) {
        if (is_string($slug) && preg_match('/^[a-z0-9\-]+$/', $slug) === 1) {
            $valid[] = $slug;
        }
    }

    $valid = array_values(array_unique($valid));
    sort($valid);

    return $valid;
}

function read_global_template_registry(): array
{
    $decoded = read_json_file(global_template_registry_path(), []);

    return is_array($decoded) ? normalize_template_slugs($decoded) : [];
}

function save_global_template_registry(array $slugs): bool
{
    return write_json_file_atomic(global_template_registry_path(), normalize_template_slugs($slugs));
}

function read_template_registry(?string $tenantId = null): array
{
    $tenantId = sanitize_tenant_id($tenantId ?? resolve_tenant_id());
    run_initial_tenant_migration($tenantId);

    $decoded = read_json_file(template_registry_path($tenantId), []);

    return is_array($decoded) ? normalize_template_slugs($decoded) : [];
}

function save_template_registry(array $slugs, ?string $tenantId = null): bool
{
    $tenantId = sanitize_tenant_id($tenantId ?? resolve_tenant_id());
    if (!ensure_tenant_directories($tenantId)) {
        return false;
    }

    return write_json_file_atomic(template_registry_path($tenantId), normalize_template_slugs($slugs));
}

function register_template_slug(string $slug, ?string $tenantId = null): bool
{
    if (preg_match('/^[a-z0-9\-]+$/', $slug) !== 1) {
        return false;
    }

    $registry = read_template_registry($tenantId);
    $registry[] = $slug;

    return save_template_registry($registry, $tenantId);
}

function unregister_template_slug(string $slug, ?string $tenantId = null): bool
{
    if (!is_valid_template_slug($slug)) {
        return false;
    }

    $registry = array_values(array_filter(read_template_registry($tenantId), static fn (string $s): bool => $s !== $slug));

    return save_template_registry($registry, $tenantId);
}

function delete_template_directory(string $slug): bool
{
    return is_valid_template_slug($slug);
}

function list_available_templates(?string $tenantId = null): array
{
    $templates = [];
    $templatesDir = templates_dir_path();
    if (is_dir($templatesDir)) {
        $entries = scandir($templatesDir);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (!is_valid_template_slug($entry)) {
                    continue;
                }
                if (is_file($templatesDir . '/' . $entry . '/index.php')) {
                    $templates[] = $entry;
                }
            }
        }
    }

    $templates = array_merge($templates, read_global_template_registry(), read_template_registry($tenantId));
    $templates = normalize_template_slugs($templates);

    return $templates;
}

function is_valid_template_slug(string $slug): bool
{
    return preg_match('/^[a-z0-9\-]+$/', $slug) === 1;
}

function template_index_path(string $slug): ?string
{
    if (!is_valid_template_slug($slug)) {
        return null;
    }

    $path = templates_dir_path() . '/' . $slug . '/index.php';

    return is_file($path) ? $path : null;
}

function resolve_active_template(array $content, string $fallback = 'artistas', ?string $tenantId = null): string
{
    $available = list_available_templates($tenantId);
    if ($available === []) {
        return $fallback;
    }

    if (!in_array($fallback, $available, true)) {
        $fallback = $available[0];
    }

    $configured = $content['site']['template'] ?? null;
    if (!is_string($configured) || $configured === '' || !in_array($configured, $available, true)) {
        return $fallback;
    }

    return $configured;
}
