<?php
/**
 * Autenticación del panel: sesión, login/logout, rate-limit y CSRF.
 */

require_once __DIR__ . '/../lib.php';

session_name('LIBROSESSID');
session_start();

/** ¿Hay sesión de administrador activa? */
function is_logged_in(): bool
{
    return !empty($_SESSION['admin']);
}

/** Exige sesión para las APIs; corta con 401 si no la hay. */
function require_login(): void
{
    if (!is_logged_in()) {
        json_response(['error' => 'No autorizado'], 401);
    }
}

/** Exige token CSRF válido en peticiones que modifican datos. */
function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $token)) {
        json_response(['error' => 'Token CSRF inválido'], 403);
    }
}

/** Token CSRF de la sesión (se crea si no existe). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Rate-limit de login por sesión + archivo (para hosting compartido sin BD). */
function login_locked(): bool
{
    $file = sys_get_temp_dir() . '/libro-login-' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli');
    if (!is_file($file)) {
        return false;
    }
    $data = json_decode((string) file_get_contents($file), true) ?: [];
    $attempts = $data['attempts'] ?? 0;
    $last = $data['last'] ?? 0;
    if (time() - $last > LOGIN_LOCKOUT_SECONDS) {
        @unlink($file);
        return false;
    }
    return $attempts >= LOGIN_MAX_ATTEMPTS;
}

function register_login_attempt(bool $success): void
{
    $file = sys_get_temp_dir() . '/libro-login-' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli');
    if ($success) {
        @unlink($file);
        return;
    }
    $data = json_decode(is_file($file) ? (string) file_get_contents($file) : '', true) ?: [];
    $data['attempts'] = ($data['attempts'] ?? 0) + 1;
    $data['last'] = time();
    @file_put_contents($file, json_encode($data));
}

/** Verifica la contraseña contra config.php. */
function check_password(string $password): bool
{
    if (ADMIN_PASSWORD_HASH !== '') {
        return password_verify($password, ADMIN_PASSWORD_HASH);
    }
    return hash_equals(ADMIN_PASSWORD, $password);
}
