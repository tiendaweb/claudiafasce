<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/template-manager.php';
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/url.php';

require_super_admin();

$status = '';
$error = '';

function sa_all_tenants(): array
{
    $base = __DIR__ . '/data/tenants';
    $tenants = [];
    if (is_dir($base)) {
        $entries = scandir($base);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (preg_match('/^[a-z0-9\-]+$/', $entry) === 1 && is_dir($base . '/' . $entry)) {
                    $tenants[] = $entry;
                }
            }
        }
    }

    foreach (read_subscriptions() as $sub) {
        $tenant = sanitize_tenant_id((string) ($sub['tenant_id'] ?? ''));
        if ($tenant !== '' && !in_array($tenant, $tenants, true)) {
            $tenants[] = $tenant;
        }
    }

    sort($tenants);

    return $tenants;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'plan_delete') {
        $id = sanitize_tenant_id((string) ($_POST['id'] ?? ''));
        $plans = array_values(array_filter(read_plans(), static fn (array $p): bool => (string) ($p['id'] ?? '') !== $id));
        if (!save_plans($plans)) {
            $error = 'No se pudo eliminar plan.';
        } else {
            $status = 'Plan eliminado.';
        }
    }

    if ($action === 'global_user_delete') {
        $id = (string) ($_POST['id'] ?? '');
        $users = array_values(array_filter(global_users(), static fn (array $u): bool => (string) ($u['id'] ?? '') !== $id));
        if (!save_global_users($users)) {
            $error = 'No se pudo eliminar usuario global.';
        } else {
            $status = 'Usuario global eliminado.';
        }
    }

    if ($action === 'tenant_delete') {
        $tenantId = sanitize_tenant_id((string) ($_POST['tenant_id'] ?? ''));
        if ($tenantId === '' || $tenantId === DEFAULT_TENANT_ID) {
            $error = 'Tenant inválido.';
        } else {
            $ok = true;
            $paths = [tenant_data_dir($tenantId), tenant_uploads_dir($tenantId)];
            foreach ($paths as $path) {
                if (!is_dir($path)) {
                    continue;
                }
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $file) {
                    $ok = $file->isDir() ? @rmdir($file->getPathname()) && $ok : @unlink($file->getPathname()) && $ok;
                }
                $ok = @rmdir($path) && $ok;
            }
            if (!$ok) {
                $error = 'No se pudo eliminar tenant completamente.';
            } else {
                $status = 'Tenant eliminado.';
            }
        }
    }

    if ($action === 'global_template_delete') {
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $templates = array_values(array_filter(read_global_template_registry(), static fn (string $s): bool => $s !== $slug));
        if (!save_global_template_registry($templates)) {
            $error = 'No se pudo eliminar plantilla global.';
        } else {
            $status = 'Plantilla global eliminada.';
        }
    }

    if ($action === 'plan_save') {
        $id = sanitize_tenant_id((string) ($_POST['id'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $price = (float) ($_POST['price_monthly'] ?? 0);
        if ($id === '' || $name === '') {
            $error = 'Plan inválido.';
        } else {
            $plans = read_plans();
            $found = false;
            foreach ($plans as &$plan) {
                if ((string) ($plan['id'] ?? '') === $id) {
                    $plan['name'] = $name;
                    $plan['price_monthly'] = max(0, $price);
                    $found = true;
                }
            }
            unset($plan);
            if (!$found) {
                $plans[] = ['id' => $id, 'name' => $name, 'description' => '', 'price_monthly' => max(0, $price), 'is_default' => false];
            }
            $status = save_plans($plans) ? 'Plan guardado.' : '';
            if ($status === '') {
                $error = 'No se pudo guardar el plan.';
            }
        }
    }

    if ($action === 'global_user_save') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || strlen($password) < 10) {
            $error = 'Usuario global inválido.';
        } else {
            $users = global_users();
            $users[] = ['id' => uniqid('g_', true), 'username' => $username, 'name' => 'Super Admin', 'role' => ROLE_SUPER_ADMIN, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)];
            if (!save_global_users($users)) {
                $error = 'No se pudo guardar usuario global.';
            } else {
                $status = 'Usuario global creado.';
            }
        }
    }

    if ($action === 'tenant_create') {
        $tenantId = sanitize_tenant_id((string) ($_POST['tenant_id'] ?? ''));
        if ($tenantId === '' || $tenantId === DEFAULT_TENANT_ID || is_dir(tenant_data_dir($tenantId))) {
            $error = 'Tenant inválido o existente.';
        } else {
            $ok = ensure_tenant_directories($tenantId) && save_users([], $tenantId) && save_template_registry(['artistas'], $tenantId);
            if (!$ok) {
                $error = 'No se pudo crear tenant.';
            } else {
                $status = 'Tenant creado.';
            }
        }
    }

    if ($action === 'global_template_save') {
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if (!is_valid_template_slug($slug)) {
            $error = 'Slug de plantilla inválido.';
        } else {
            $templates = read_global_template_registry();
            $templates[] = $slug;
            if (!save_global_template_registry($templates)) {
                $error = 'No se pudo guardar plantilla global.';
            } else {
                $status = 'Plantilla global registrada.';
            }
        }
    }
}

$plans = read_plans();
$gUsers = global_users();
$tenants = sa_all_tenants();
$templates = read_global_template_registry();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Super Admin</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-950 text-slate-100 p-6">
<h1 class="text-3xl font-bold mb-6">Super Admin</h1>
<?php if ($status !== ''): ?><p class="text-green-300 mb-4"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="text-red-300 mb-4"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<div class="grid gap-6 md:grid-cols-2">
<section class="p-4 border border-white/20 rounded-xl"><h2 class="font-semibold mb-3">Gestión de planes</h2><ul class="text-sm mb-3"><?php foreach ($plans as $p): ?><li><?= htmlspecialchars((string) $p['id']) ?> - <?= htmlspecialchars((string) $p['name']) ?> <form method='post' class='inline'><input type='hidden' name='action' value='plan_delete'><input type='hidden' name='id' value='<?= htmlspecialchars((string) $p['id']) ?>'><button class='text-red-300'>x</button></form></li><?php endforeach; ?></ul><form method="post" class="space-y-2"><input type="hidden" name="action" value="plan_save"><input class="w-full bg-white/5 p-2" name="id" placeholder="id"><input class="w-full bg-white/5 p-2" name="name" placeholder="nombre"><input class="w-full bg-white/5 p-2" name="price_monthly" type="number" min="0" step="0.01" placeholder="precio"><button class="bg-cyan-300 text-slate-900 px-3 py-2 rounded">Guardar plan</button></form></section>
<section class="p-4 border border-white/20 rounded-xl"><h2 class="font-semibold mb-3">Usuarios globales</h2><ul class="text-sm mb-3"><?php foreach ($gUsers as $u): ?><li><?= htmlspecialchars((string) $u['username']) ?> (<?= htmlspecialchars((string) $u['role']) ?>) <form method='post' class='inline'><input type='hidden' name='action' value='global_user_delete'><input type='hidden' name='id' value='<?= htmlspecialchars((string) $u['id']) ?>'><button class='text-red-300'>x</button></form></li><?php endforeach; ?></ul><form method="post" class="space-y-2"><input type="hidden" name="action" value="global_user_save"><input class="w-full bg-white/5 p-2" name="username" placeholder="usuario"><input class="w-full bg-white/5 p-2" name="password" type="password" placeholder="contraseña"><button class="bg-cyan-300 text-slate-900 px-3 py-2 rounded">Crear super-admin</button></form></section>
<section class="p-4 border border-white/20 rounded-xl"><h2 class="font-semibold mb-3">Sitios / tenants</h2><ul class="text-sm mb-3"><?php foreach ($tenants as $t): ?><li><?= htmlspecialchars($t) ?> <form method='post' class='inline'><input type='hidden' name='action' value='tenant_delete'><input type='hidden' name='tenant_id' value='<?= htmlspecialchars($t) ?>'><button class='text-red-300'>x</button></form></li><?php endforeach; ?></ul><form method="post" class="space-y-2"><input type="hidden" name="action" value="tenant_create"><input class="w-full bg-white/5 p-2" name="tenant_id" placeholder="tenant-id"><button class="bg-cyan-300 text-slate-900 px-3 py-2 rounded">Crear tenant</button></form></section>
<section class="p-4 border border-white/20 rounded-xl"><h2 class="font-semibold mb-3">Plantillas globales</h2><ul class="text-sm mb-3"><?php foreach ($templates as $t): ?><li><?= htmlspecialchars($t) ?> <form method='post' class='inline'><input type='hidden' name='action' value='global_template_delete'><input type='hidden' name='slug' value='<?= htmlspecialchars($t) ?>'><button class='text-red-300'>x</button></form></li><?php endforeach; ?></ul><form method="post" class="space-y-2"><input type="hidden" name="action" value="global_template_save"><input class="w-full bg-white/5 p-2" name="slug" placeholder="slug"><button class="bg-cyan-300 text-slate-900 px-3 py-2 rounded">Agregar plantilla global</button></form></section>
</div>
<p class="mt-6"><a href="<?= htmlspecialchars(url_for('/logout'), ENT_QUOTES, 'UTF-8') ?>" class="underline">Cerrar sesión</a></p>
</body></html>
