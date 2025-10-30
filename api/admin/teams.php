<?php

declare(strict_types=1);

require __DIR__ . '/../_lib.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch (strtoupper($method)) {
    case 'GET':
        handle_list_teams();
        break;
    case 'POST':
        handle_mutate_team();
        break;
    default:
        header('Allow: GET, POST');
        json_err('Method Not Allowed', 405);
}

function handle_list_teams(): void
{
    $pdo = db();
    $stmt = $pdo->query('SELECT id, name, settings_json FROM teams ORDER BY id ASC');
    $teams = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $teams[] = format_team_row($row);
    }

    json_ok(['teams' => $teams]);
}

function handle_mutate_team(): void
{
    $payload = read_json_payload();
    $action = isset($payload['action']) ? strtolower((string) $payload['action']) : '';

    switch ($action) {
        case 'create':
            handle_create_team($payload);
            return;
        case 'update':
            handle_update_team($payload);
            return;
        case 'delete':
            handle_delete_team($payload);
            return;
        default:
            json_err('未知操作', 400);
    }
}

function handle_create_team(array $payload): void
{
    $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
    if ($name === '') {
        json_err('团队名称不能为空', 422);
    }

    $settings = isset($payload['settings']) && is_array($payload['settings'])
        ? $payload['settings']
        : [];

    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO teams (name, settings_json) VALUES (:name, :settings)');
    $stmt->execute([
        ':name' => $name,
        ':settings' => encode_json_field($settings),
    ]);

    $id = (int) $pdo->lastInsertId();
    $row = fetch_team_by_id($id);
    json_ok(['team' => $row]);
}

function handle_update_team(array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    if ($id <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    $fields = [];
    $params = [':id' => $id];

    if (array_key_exists('name', $payload)) {
        $name = trim((string) $payload['name']);
        if ($name === '') {
            json_err('团队名称不能为空', 422);
        }
        $fields[] = 'name = :name';
        $params[':name'] = $name;
    }

    if (array_key_exists('settings', $payload)) {
        $settings = is_array($payload['settings']) ? $payload['settings'] : [];
        $fields[] = 'settings_json = :settings';
        $params[':settings'] = encode_json_field($settings);
    }

    if ($fields === []) {
        json_err('没有可更新的字段', 422);
    }

    $pdo = db();
    $stmt = $pdo->prepare('UPDATE teams SET ' . implode(', ', $fields) . ' WHERE id = :id');
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        // Confirm existence
        fetch_team_by_id($id); // Will throw if not exists
    }

    $row = fetch_team_by_id($id);
    json_ok(['team' => $row]);
}

function handle_delete_team(array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    if ($id <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM teams WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_err('团队不存在', 404);
    }

    json_ok();
}

function fetch_team_by_id(int $id): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, name, settings_json FROM teams WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_err('团队不存在', 404);
    }

    return format_team_row($row);
}

function format_team_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'settings' => decode_team_settings($row['settings_json'] ?? '{}'),
    ];
}

function decode_team_settings(?string $json): array
{
    $settings = decode_json_field($json, []);
    return is_array($settings) ? $settings : [];
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
