<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    enforce_view_access('people');
    handle_get();
    exit;
}

if ($method === 'POST') {
    enforce_view_access('people');
    $payload = read_json_payload();
    $action = strtolower((string) ($payload['action'] ?? ''));

    switch ($action) {
        case 'create_many':
            handle_create_many($payload);
            break;
        case 'update':
            handle_update_employee($payload);
            break;
        case 'delete':
            handle_delete_employee($payload);
            break;
        case 'reorder':
            handle_reorder($payload);
            break;
        default:
            json_err('未知操作', 400);
    }
    exit;
}

header('Allow: GET, POST');
json_err('Method Not Allowed', 405);

function respond_ok(array $data = []): void
{
    json_ok($data);
}

function respond_error(string $message, int $status = 400): void
{
    json_err($message, $status);
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


function handle_get(): void
{
    $context = auth_context();
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $permissions = $context['permissions'];

    $teams = fetch_accessible_teams($pdo, $permissions);
    $teamIds = array_map(static fn (array $team): int => $team['id'], $teams);

    $teamIdParam = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
    if ($teamIdParam !== null && $teamIdParam > 0 && !in_array($teamIdParam, $teamIds, true)) {
        permission_denied();
    }

    $teamId = $teamIdParam;
    if (($teamId === null || $teamId <= 0) && $teamIds !== []) {
        $teamId = $teamIds[0];
    }

    $employees = [];
    $canEdit = false;
    if ($teamId !== null && $teamId > 0) {
        ensure_team_access($permissions, $teamId);
        $employees = fetch_employees_by_team($pdo, $teamId);
        $canEdit = permissions_can_edit_team($permissions, $teamId);
    }

    respond_ok([
        'teams' => $teams,
        'team_id' => $teamId,
        'employees' => $employees,
        'can_edit' => $canEdit,
    ]);
}

function fetch_accessible_teams(PDO $pdo, array $permissions): array
{
    $allowed = $permissions['allowed_teams'] ?? null;

    if ($allowed === null) {
        $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC, id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($allowed === []) {
        $rows = [];
    } else {
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

function handle_create_many(array $payload): void
{
    $teamId = isset($payload['team_id']) ? (int) $payload['team_id'] : 0;
    if ($teamId <= 0) {
        respond_error('缺少有效的团队', 422);
    }

    $context = enforce_edit_access($teamId);

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

function handle_update_employee(array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    if ($id <= 0) {
        respond_error('缺少员工ID', 422);
    }

    $baseContext = auth_context();
    /** @var PDO $pdo */
    $pdo = $baseContext['pdo'];
    $employee = fetch_employee($pdo, $id);
    if ($employee === null) {
        respond_error('员工不存在', 404);
    }

    $teamId = (int) $employee['team_id'];
    $context = enforce_edit_access($teamId);
    /** @var PDO $pdo */
    $pdo = $context['pdo'];

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

    $sql = 'UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respond_ok();
}

function handle_delete_employee(array $payload): void
{
    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    if ($id <= 0) {
        respond_error('缺少员工ID', 422);
    }

    $baseContext = auth_context();
    /** @var PDO $pdo */
    $pdo = $baseContext['pdo'];
    $employee = fetch_employee($pdo, $id);
    if ($employee === null) {
        respond_error('员工不存在', 404);
    }

    $teamId = (int) $employee['team_id'];
    $context = enforce_edit_access($teamId);
    /** @var PDO $pdo */
    $pdo = $context['pdo'];

    $stmt = $pdo->prepare('DELETE FROM employees WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        respond_error('员工不存在', 404);
    }

    respond_ok();
}

function handle_reorder(array $payload): void
{
    $teamId = isset($payload['team_id']) ? (int) $payload['team_id'] : 0;
    if ($teamId <= 0) {
        respond_error('缺少有效的团队', 422);
    }

    $context = enforce_edit_access($teamId);
    /** @var PDO $pdo */
    $pdo = $context['pdo'];

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

    $existing = fetch_employees_by_team($pdo, $teamId);
    $existingIds = array_map(static fn (array $emp): int => $emp['id'], $existing);

    $missing = array_diff($existingIds, $ids);
    if ($missing !== []) {
        respond_error('排序数据缺少员工', 422);
    }

    $unknown = array_diff($ids, $existingIds);
    if ($unknown !== []) {
        respond_error('排序数据包含无效员工', 422);
    }

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

function fetch_employee(PDO $pdo, int $id): ?array
{
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

