<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$pdo = db();
$user = current_user();
if ($user === null) {
    respond_error('未登录', 401);
}

$userAccess = fetch_user_access($pdo, (int) ($user['id'] ?? 0));
if ($userAccess === null) {
    respond_error('账号不可用', 403);
}

$isAdmin = $userAccess['role'] === 'admin';
$allowedViews = $userAccess['allowed_views'];
if (!$isAdmin && !in_array('people', $allowedViews, true)) {
    respond_error('无权限访问员工数据', 403);
}

$context = [
    'pdo' => $pdo,
    'is_admin' => $isAdmin,
    'allowed_team_ids' => $isAdmin ? null : $userAccess['allowed_teams'],
    'editable_team_ids' => $isAdmin ? null : $userAccess['editable_teams'],
];

if ($method === 'GET') {
    handle_get($context);
    exit;
}

if ($method === 'POST') {
    $payload = read_json_payload();
    $action = strtolower((string) ($payload['action'] ?? ''));

    switch ($action) {
        case 'create_many':
            handle_create_many($context, $payload);
            break;
        case 'update':
            handle_update_employee($context, $payload);
            break;
        case 'delete':
            handle_delete_employee($context, $payload);
            break;
        case 'reorder':
            handle_reorder($context, $payload);
            break;
        default:
            respond_error('未知操作', 400);
    }
    exit;
}

header('Allow: GET, POST');
respond_error('Method Not Allowed', 405);

function respond_ok(array $data = []): void
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function respond_error(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'msg' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
        respond_error('无效的JSON请求体', 400);
    }

    return is_array($decoded) ? $decoded : [];
}

function fetch_user_access(PDO $pdo, int $userId): ?array
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

    return [
        'id' => (int) $row['id'],
        'role' => (string) $row['role'],
        'allowed_teams' => normalize_int_array(decode_json_field($row['allowed_teams_json'] ?? '[]', [])),
        'allowed_views' => normalize_string_array(decode_json_field($row['allowed_views_json'] ?? '[]', [])),
        'editable_teams' => normalize_int_array(decode_json_field($row['editable_teams_json'] ?? '[]', [])),
    ];
}

function normalize_int_array($value): array
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

function normalize_string_array($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $str = (string) $item;
        if ($str === '') {
            continue;
        }
        if (!in_array($str, $result, true)) {
            $result[] = $str;
        }
    }

    return $result;
}

function handle_get(array $context): void
{
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $teams = fetch_accessible_teams($context);

    $teamIdParam = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
    $teamIds = array_map(static fn (array $team): int => $team['id'], $teams);

    if ($teamIdParam !== null && $teamIdParam > 0 && !in_array($teamIdParam, $teamIds, true)) {
        respond_error('无权访问该团队', 403);
    }

    $teamId = $teamIdParam;
    if (($teamId === null || $teamId <= 0) && $teamIds !== []) {
        $teamId = $teamIds[0];
    }

    $employees = [];
    if ($teamId !== null && $teamId > 0) {
        $employees = fetch_employees_by_team($pdo, $teamId);
    }

    $canEdit = $teamId !== null && $teamId > 0 && can_edit_team($context, $teamId);

    respond_ok([
        'teams' => $teams,
        'team_id' => $teamId,
        'employees' => $employees,
        'can_edit' => $canEdit,
    ]);
}

function fetch_accessible_teams(array $context): array
{
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $allowed = $context['allowed_team_ids'];

    if ($context['is_admin']) {
        $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC, id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif (is_array($allowed) && $allowed !== []) {
        $placeholders = [];
        $params = [];
        foreach ($allowed as $idx => $teamId) {
            $key = ':t' . $idx;
            $placeholders[] = $key;
            $params[$key] = $teamId;
        }
        $sql = 'SELECT id, name FROM teams WHERE id IN (' . implode(',', $placeholders) . ') ORDER BY name ASC, id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $rows = [];
    }

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }, $rows);
}

function fetch_employees_by_team(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare('SELECT id, team_id, name, display_name, active, sort_order FROM employees WHERE team_id = :team_id ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':team_id' => $teamId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'team_id' => (int) $row['team_id'],
            'name' => (string) $row['name'],
            'display_name' => (string) $row['display_name'],
            'active' => ((int) $row['active']) === 1,
            'sort_order' => (int) $row['sort_order'],
        ];
    }, $rows);
}

function handle_create_many(array $context, array $payload): void
{
    $teamId = isset($payload['team_id']) ? (int) $payload['team_id'] : 0;
    if ($teamId <= 0) {
        respond_error('缺少有效的团队', 422);
    }

    if (!can_view_team($context, $teamId) || !can_edit_team($context, $teamId)) {
        respond_error('无权在该团队创建员工', 403);
    }

    $namesRaw = isset($payload['names']) ? (string) $payload['names'] : '';
    $lines = preg_split('/\r?\n/', $namesRaw) ?: [];
    $filtered = [];
    foreach ($lines as $line) {
        $name = trim($line);
        if ($name !== '') {
            $filtered[] = $name;
        }
    }

    if ($filtered === []) {
        respond_error('请提供至少一名员工', 422);
    }

    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $pdo->beginTransaction();
    try {
        $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) AS max_order FROM employees WHERE team_id = :team_id');
        $stmtMax->execute([':team_id' => $teamId]);
        $maxRow = $stmtMax->fetch(PDO::FETCH_ASSOC);
        $nextOrder = (int) ($maxRow['max_order'] ?? 0);

        $stmt = $pdo->prepare('INSERT INTO employees (team_id, name, display_name, active, sort_order) VALUES (:team_id, :name, :display_name, :active, :sort_order)');

        foreach ($filtered as $name) {
            $nextOrder++;
            $stmt->execute([
                ':team_id' => $teamId,
                ':name' => $name,
                ':display_name' => $name,
                ':active' => 1,
                ':sort_order' => $nextOrder,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond_error('批量新增失败，请稍后再试', 500);
    }

    respond_ok();
}

function handle_update_employee(array $context, array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    if ($id <= 0) {
        respond_error('缺少员工ID', 422);
    }

    $employee = fetch_employee($context, $id);
    if ($employee === null) {
        respond_error('员工不存在', 404);
    }

    $teamId = (int) $employee['team_id'];
    if (!can_view_team($context, $teamId) || !can_edit_team($context, $teamId)) {
        respond_error('无权修改该员工', 403);
    }

    $fields = [];
    $params = [':id' => $id];

    if (array_key_exists('name', $payload)) {
        $name = trim((string) $payload['name']);
        if ($name === '') {
            respond_error('姓名不能为空', 422);
        }
        $fields[] = 'name = :name';
        $params[':name'] = $name;
    }

    if (array_key_exists('display_name', $payload)) {
        $displayName = trim((string) $payload['display_name']);
        if ($displayName === '') {
            respond_error('显示名不能为空', 422);
        }
        $fields[] = 'display_name = :display_name';
        $params[':display_name'] = $displayName;
    }

    if (array_key_exists('active', $payload)) {
        $active = filter_var($payload['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            $active = (bool) $payload['active'];
        }
        $fields[] = 'active = :active';
        $params[':active'] = $active ? 1 : 0;
    }

    if ($fields === []) {
        respond_error('没有可更新的字段', 422);
    }

    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $sql = 'UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respond_ok();
}

function handle_delete_employee(array $context, array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    if ($id <= 0) {
        respond_error('缺少员工ID', 422);
    }

    $employee = fetch_employee($context, $id);
    if ($employee === null) {
        respond_error('员工不存在', 404);
    }

    $teamId = (int) $employee['team_id'];
    if (!can_view_team($context, $teamId) || !can_edit_team($context, $teamId)) {
        respond_error('无权删除该员工', 403);
    }

    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $stmt = $pdo->prepare('DELETE FROM employees WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        respond_error('员工不存在', 404);
    }

    respond_ok();
}

function handle_reorder(array $context, array $payload): void
{
    $teamId = isset($payload['team_id']) ? (int) $payload['team_id'] : 0;
    if ($teamId <= 0) {
        respond_error('缺少有效的团队', 422);
    }

    if (!can_view_team($context, $teamId) || !can_edit_team($context, $teamId)) {
        respond_error('无权排序该团队员工', 403);
    }

    $order = $payload['order'] ?? [];
    if (!is_array($order) || $order === []) {
        respond_error('排序数据无效', 422);
    }

    $ids = [];
    foreach ($order as $item) {
        $id = (int) $item;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    if ($ids === []) {
        respond_error('排序数据无效', 422);
    }

    if (count($ids) !== count(array_unique($ids))) {
        respond_error('排序数据包含重复员工', 422);
    }

    $existing = fetch_employees_by_team($context['pdo'], $teamId);
    $existingIds = array_map(static fn (array $emp): int => $emp['id'], $existing);

    $missing = array_diff($existingIds, $ids);
    if ($missing !== []) {
        respond_error('排序数据缺少员工', 422);
    }

    $unknown = array_diff($ids, $existingIds);
    if ($unknown !== []) {
        respond_error('排序数据包含无效员工', 422);
    }

    $pdo = $context['pdo'];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE employees SET sort_order = :sort_order WHERE id = :id');
        $orderValue = 0;
        foreach ($ids as $id) {
            $orderValue++;
            $stmt->execute([
                ':sort_order' => $orderValue,
                ':id' => $id,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond_error('排序更新失败', 500);
    }

    respond_ok();
}

function fetch_employee(array $context, int $id): ?array
{
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $stmt = $pdo->prepare('SELECT id, team_id, name, display_name, active, sort_order FROM employees WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'team_id' => (int) $row['team_id'],
        'name' => (string) $row['name'],
        'display_name' => (string) $row['display_name'],
        'active' => ((int) $row['active']) === 1,
        'sort_order' => (int) $row['sort_order'],
    ];
}

function can_view_team(array $context, int $teamId): bool
{
    if ($context['is_admin']) {
        return true;
    }

    $allowed = $context['allowed_team_ids'];
    if (!is_array($allowed) || $allowed === []) {
        return false;
    }

    return in_array($teamId, $allowed, true);
}

function can_edit_team(array $context, int $teamId): bool
{
    if ($context['is_admin']) {
        return true;
    }

    $editable = $context['editable_team_ids'];
    if (!is_array($editable) || $editable === []) {
        return false;
    }

    return in_array($teamId, $editable, true);
}
