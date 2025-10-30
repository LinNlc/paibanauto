<?php

declare(strict_types=1);

require __DIR__ . '/../_lib.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch (strtoupper($method)) {
    case 'GET':
        handle_list_users();
        break;
    case 'POST':
        handle_mutate_user();
        break;
    default:
        header('Allow: GET, POST');
        json_err('Method Not Allowed', 405);
}

function handle_list_users(): void
{
    $pdo = db();
    $stmt = $pdo->query('SELECT id, username, display_name, role, allowed_teams_json, allowed_views_json, editable_teams_json, disabled, created_at FROM users ORDER BY id ASC');
    $users = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $users[] = format_user_row($row);
    }

    json_ok(['users' => $users]);
}

function handle_mutate_user(): void
{
    $payload = read_json_payload();
    $action = isset($payload['action']) ? strtolower((string) $payload['action']) : '';

    switch ($action) {
        case 'create':
            handle_create_user($payload);
            return;
        case 'update':
            handle_update_user($payload);
            return;
        case 'delete':
            handle_delete_user($payload);
            return;
        case 'reset_password':
            handle_reset_password($payload);
            return;
        default:
            json_err('未知操作', 400);
    }
}

function handle_create_user(array $payload): void
{
    $username = isset($payload['username']) ? trim((string) $payload['username']) : '';
    $displayName = isset($payload['display_name']) ? trim((string) $payload['display_name']) : '';
    $role = isset($payload['role']) ? strtolower((string) $payload['role']) : '';
    $password = isset($payload['password']) ? (string) $payload['password'] : '';

    if ($username === '' || $displayName === '' || $password === '') {
        json_err('用户名、显示名和密码均不能为空', 422);
    }

    if (!is_valid_role($role)) {
        json_err('无效的角色', 422);
    }

    $allowedTeams = normalize_team_ids($payload['allowed_teams'] ?? []);
    $editableTeams = normalize_team_ids($payload['editable_teams'] ?? []);
    $allowedViews = normalize_views($payload['allowed_views'] ?? []);
    $disabled = normalize_bool($payload['disabled'] ?? false);

    $pdo = db();

    try {
        $stmt = $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role, allowed_teams_json, allowed_views_json, editable_teams_json, disabled) VALUES (:username, :display_name, :password_hash, :role, :allowed_teams, :allowed_views, :editable_teams, :disabled)');
        $stmt->execute([
            ':username' => $username,
            ':display_name' => $displayName,
            ':password_hash' => hash_password($password),
            ':role' => $role,
            ':allowed_teams' => encode_json_field($allowedTeams),
            ':allowed_views' => encode_json_field($allowedViews),
            ':editable_teams' => encode_json_field($editableTeams),
            ':disabled' => $disabled ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_err('用户名已存在', 409);
        }
        json_err('数据库错误', 500);
    }

    $id = (int) $pdo->lastInsertId();
    $user = fetch_user_by_id($id);
    json_ok(['user' => $user]);
}

function handle_update_user(array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    if ($id <= 0) {
        json_err('缺少有效的用户ID', 422);
    }

    // Ensure user exists before updating to differentiate between no-change and missing record
    fetch_user_by_id($id);

    $fields = [];
    $params = [':id' => $id];

    if (array_key_exists('username', $payload)) {
        $username = trim((string) $payload['username']);
        if ($username === '') {
            json_err('用户名不能为空', 422);
        }
        $fields[] = 'username = :username';
        $params[':username'] = $username;
    }

    if (array_key_exists('display_name', $payload)) {
        $displayName = trim((string) $payload['display_name']);
        if ($displayName === '') {
            json_err('显示名不能为空', 422);
        }
        $fields[] = 'display_name = :display_name';
        $params[':display_name'] = $displayName;
    }

    if (array_key_exists('role', $payload)) {
        $role = strtolower((string) $payload['role']);
        if (!is_valid_role($role)) {
            json_err('无效的角色', 422);
        }
        $fields[] = 'role = :role';
        $params[':role'] = $role;
    }

    if (array_key_exists('allowed_teams', $payload)) {
        $allowedTeams = normalize_team_ids($payload['allowed_teams']);
        $fields[] = 'allowed_teams_json = :allowed_teams';
        $params[':allowed_teams'] = encode_json_field($allowedTeams);
    }

    if (array_key_exists('editable_teams', $payload)) {
        $editableTeams = normalize_team_ids($payload['editable_teams']);
        $fields[] = 'editable_teams_json = :editable_teams';
        $params[':editable_teams'] = encode_json_field($editableTeams);
    }

    if (array_key_exists('allowed_views', $payload)) {
        $allowedViews = normalize_views($payload['allowed_views']);
        $fields[] = 'allowed_views_json = :allowed_views';
        $params[':allowed_views'] = encode_json_field($allowedViews);
    }

    if (array_key_exists('disabled', $payload)) {
        $disabled = normalize_bool($payload['disabled']);
        $fields[] = 'disabled = :disabled';
        $params[':disabled'] = $disabled ? 1 : 0;
    }

    if ($fields === []) {
        json_err('没有可更新的字段', 422);
    }

    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $pdo = db();

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_err('用户名已存在', 409);
        }
        json_err('数据库错误', 500);
    }

    $user = fetch_user_by_id($id);
    json_ok(['user' => $user]);
}

function handle_delete_user(array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    if ($id <= 0) {
        json_err('缺少有效的用户ID', 422);
    }

    $current = current_user();
    if ($current && (int) ($current['id'] ?? 0) === $id) {
        json_err('不能删除当前登录用户', 400);
    }

    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_err('用户不存在', 404);
    }

    json_ok();
}

function handle_reset_password(array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    $password = isset($payload['password']) ? (string) $payload['password'] : '';

    if ($id <= 0 || $password === '') {
        json_err('缺少有效的用户ID或密码', 422);
    }

    // Ensure the user exists before attempting update
    fetch_user_by_id($id);

    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $stmt->execute([
        ':password_hash' => hash_password($password),
        ':id' => $id,
    ]);

    json_ok();
}

function fetch_user_by_id(int $id): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, username, display_name, role, allowed_teams_json, allowed_views_json, editable_teams_json, disabled, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_err('用户不存在', 404);
    }

    return format_user_row($row);
}

function format_user_row(array $row): array
{
    $allowedTeams = normalize_team_ids(decode_json_field($row['allowed_teams_json'] ?? '[]', []));
    $editableTeams = normalize_team_ids(decode_json_field($row['editable_teams_json'] ?? '[]', []));
    $allowedViews = normalize_views(decode_json_field($row['allowed_views_json'] ?? '[]', []));

    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'display_name' => (string) $row['display_name'],
        'role' => (string) $row['role'],
        'allowed_teams' => $allowedTeams,
        'editable_teams' => $editableTeams,
        'allowed_views' => $allowedViews,
        'disabled' => ((int) $row['disabled']) === 1,
        'created_at' => (string) $row['created_at'],
    ];
}

function normalize_team_ids($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $id = (int) $item;
        if ($id > 0) {
            $result[$id] = $id;
        }
    }

    sort($result);

    return array_values($result);
}

function normalize_views($value): array
{
    $allowed = ['people', 'schedule', 'stats'];
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $view = (string) $item;
        if (in_array($view, $allowed, true) && !in_array($view, $result, true)) {
            $result[] = $view;
        }
    }

    return $result;
}

function normalize_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_string($value)) {
        $value = strtolower($value);
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    return (bool) $value;
}

function is_valid_role(string $role): bool
{
    return in_array($role, ['admin', 'editor', 'readonly'], true);
}

function read_json_payload(): array
{
    $content = file_get_contents('php://input');
    if ($content === false || $content === '') {
        return [];
    }

    try {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        json_err('无效的JSON请求体', 400);
    }

    return is_array($decoded) ? $decoded : [];
}
