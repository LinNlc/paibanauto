<?php

declare(strict_types=1);

function app_config(): array
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/../config/app.php';
    }
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $dsn = 'sqlite:' . $config['db_path'];
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function json_ok(array $data = []): void
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'ok' => false,
        'error' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_session_started(): void
{
    static $started = false;
    if ($started) {
        return;
    }

    $config = app_config();
    $sessionName = (string)($config['session_name'] ?? 'paibanauto');
    if (session_status() === PHP_SESSION_NONE) {
        session_name($sessionName);
        $isSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    $started = true;
}

function current_user(): ?array
{
    ensure_session_started();
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        return null;
    }

    return $user;
}

function set_current_user(array $user): void
{
    ensure_session_started();
    $_SESSION['user'] = $user;
}

function clear_current_user(): void
{
    ensure_session_started();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        json_err('未登录', 401);
    }

    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (($user['role'] ?? '') !== 'admin') {
        json_err('无权限', 403);
    }

    return $user;
}

/**
 * @template T
 * @param T $default
 * @return T|array<mixed>
 */
function decode_json_field(?string $json, $default)
{
    if ($json === null || $json === '') {
        return $default;
    }

    $data = json_decode($json, true);
    if ($data === null || $data === false) {
        return $default;
    }

    return is_array($data) ? $data : $default;
}

function encode_json_field($value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        json_err('JSON编码失败', 500);
    }

    return $encoded;
}

function hash_password(string $password): string
{
    $config = app_config();
    $options = [];
    if (isset($config['password_cost'])) {
        $cost = (int) $config['password_cost'];
        if ($cost >= 4) {
            $options['cost'] = $cost;
        }
    }

    if ($options !== []) {
        return password_hash($password, PASSWORD_DEFAULT, $options);
    }

    return password_hash($password, PASSWORD_DEFAULT);
}
