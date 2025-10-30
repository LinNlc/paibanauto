<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handle_login();
        break;
    case 'logout':
        handle_logout();
        break;
    case 'me':
        handle_me();
        break;
    default:
        json_err('未知操作', 400);
}

function handle_login(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        json_err('Method Not Allowed', 405);
    }

    try {
        $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        json_err('无效的请求体', 400);
    }

    if (!is_array($payload)) {
        json_err('无效的请求体', 400);
    }

    $username = isset($payload['username']) ? trim((string)$payload['username']) : '';
    $password = isset($payload['password']) ? (string)$payload['password'] : '';

    if ($username === '' || $password === '') {
        json_err('账号或密码不能为空', 422);
    }

    if (is_login_rate_limited($username)) {
        json_err('尝试次数过多，请稍后再试', 429);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, username, display_name, password_hash, role, disabled FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['disabled'] === 1) {
        record_login_attempt($username, false);
        json_err('账号或密码错误', 401);
    }

    if (!password_verify($password, (string)$user['password_hash'])) {
        record_login_attempt($username, false);
        json_err('账号或密码错误', 401);
    }

    record_login_attempt($username, true);

    ensure_session_started();
    session_regenerate_id(true);

    $publicUser = [
        'id' => (int)$user['id'],
        'display_name' => (string)$user['display_name'],
        'role' => (string)$user['role'],
    ];

    set_current_user($publicUser);

    json_ok(['user' => $publicUser]);
}

function handle_logout(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        json_err('Method Not Allowed', 405);
    }

    ensure_session_started();
    clear_current_user();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        } else {
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
        }
        session_destroy();
    }

    json_ok();
}

function handle_me(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        json_err('Method Not Allowed', 405);
    }

    $user = current_user();
    if ($user === null) {
        json_err('未登录', 401);
    }

    json_ok(['user' => $user]);
}

function is_login_rate_limited(string $username): bool
{
    $attempts = get_login_attempts($username);
    $limit = 5;
    $window = 600;
    $now = time();

    $recentAttempts = array_filter($attempts, static function (int $timestamp) use ($now, $window): bool {
        return ($now - $timestamp) <= $window;
    });

    return count($recentAttempts) >= $limit;
}

function record_login_attempt(string $username, bool $success): void
{
    $attempts = get_login_attempts($username);
    $window = 600;
    $now = time();

    $attempts = array_filter($attempts, static function (int $timestamp) use ($now, $window): bool {
        return ($now - $timestamp) <= $window;
    });

    if ($success) {
        $attempts = [];
    } else {
        $attempts[] = $now;
    }

    store_login_attempts($username, $attempts, $window);
}

function get_login_attempts(string $username): array
{
    $key = 'login_fail:' . strtolower($username);

    if (function_exists('apcu_fetch')) {
        $result = apcu_fetch($key, $success);
        if ($success && is_array($result)) {
            return array_map('intval', $result);
        }
        return [];
    }

    $memoryCache = &login_memory_cache();
    if (isset($memoryCache[$key]) && is_array($memoryCache[$key])) {
        return array_map('intval', $memoryCache[$key]);
    }

    return [];
}

function store_login_attempts(string $username, array $attempts, int $ttl): void
{
    $key = 'login_fail:' . strtolower($username);

    if (function_exists('apcu_store')) {
        apcu_store($key, $attempts, $ttl);
        return;
    }

    $memoryCache = &login_memory_cache();
    $memoryCache[$key] = $attempts;
}

/**
 * @return array<string, array<int>>
 */
function &login_memory_cache(): array
{
    static $cache = [];
    return $cache;
}
