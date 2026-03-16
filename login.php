<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    header('Location: /admin.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $user = find_user_by_username($username);

    if ($user !== null && isset($user['password_hash']) && password_verify($password, (string) $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        header('Location: /admin.php');
        exit;
    }

    $error = 'Usuario o contraseña inválidos.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; max-width: 480px; }
        form { display: grid; gap: 0.75rem; }
        input, button { padding: 0.6rem; font-size: 1rem; }
        .error { color: #b00020; }
    </style>
</head>
<body>
    <h1>Iniciar sesión</h1>
    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="/login.php">
        <label>
            Usuario
            <input type="text" name="username" required value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label>
            Contraseña
            <input type="password" name="password" required>
        </label>
        <button type="submit">Entrar</button>
    </form>
</body>
</html>
