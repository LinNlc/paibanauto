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

/**
 * @param mixed $value
 * @return array<int>
 */
function normalize_id_list($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $id = (int) $item;
        if ($id <= 0) {
            continue;
        }
        $result[$id] = $id;
    }

    if ($result === []) {
        return [];
    }

    ksort($result);

    return array_values($result);
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function normalize_string_list($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $str = trim((string) $item);
        if ($str === '' || isset($result[$str])) {
            continue;
        }
        $result[$str] = $str;
    }

    return array_values($result);
}

function get_shift_options(): array
{
    $config = app_config();
    $options = $config['shift_options'] ?? [];
    if (!is_array($options)) {
        return [];
    }

    $normalized = [];
    foreach ($options as $option) {
        $value = (string) $option;
        if ($value === '') {
            continue;
        }
        $normalized[] = $value;
    }

    return $normalized;
}

function load_user_permissions(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, role, disabled, allowed_teams_json, allowed_views_json, editable_teams_json FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['disabled'] === 1) {
        return null;
    }

    $role = (string) $row['role'];
    $isAdmin = $role === 'admin';
    $allowedTeams = $isAdmin ? null : normalize_id_list(decode_json_field($row['allowed_teams_json'] ?? '[]', []));
    $editableTeams = $isAdmin ? null : normalize_id_list(decode_json_field($row['editable_teams_json'] ?? '[]', []));
    $allowedViews = $isAdmin ? ['*'] : normalize_string_list(decode_json_field($row['allowed_views_json'] ?? '[]', []));

    return [
        'id' => (int) $row['id'],
        'role' => $role,
        'is_admin' => $isAdmin,
        'allowed_teams' => $allowedTeams,
        'editable_teams' => $editableTeams,
        'allowed_views' => $allowedViews,
    ];
}

function permissions_can_view_section(array $permissions, string $section): bool
{
    if (($permissions['is_admin'] ?? false) === true) {
        return true;
    }

    $allowedViews = $permissions['allowed_views'] ?? [];
    if (!is_array($allowedViews)) {
        return false;
    }

    if (in_array('*', $allowedViews, true)) {
        return true;
    }

    return in_array($section, $allowedViews, true);
}

function permissions_can_access_team(array $permissions, int $teamId): bool
{
    if (($permissions['is_admin'] ?? false) === true) {
        return true;
    }

    $allowed = $permissions['allowed_teams'] ?? [];
    if ($allowed === null) {
        return true;
    }

    return in_array($teamId, $allowed, true);
}

function permissions_can_edit_team(array $permissions, int $teamId): bool
{
    if (($permissions['is_admin'] ?? false) === true) {
        return true;
    }

    $editable = $permissions['editable_teams'] ?? [];
    if ($editable === null) {
        return true;
    }

    return in_array($teamId, $editable, true);
}
