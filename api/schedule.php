<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$pdo = db();
$user = current_user();
if ($user === null) {
    json_err('未登录', 401);
}

$permissions = load_user_permissions($pdo, (int) ($user['id'] ?? 0));
if ($permissions === null) {
    json_err('账号不可用', 403);
}

if (!permissions_can_view_section($permissions, 'schedule')) {
    json_err('无权访问排班数据', 403);
}

switch ($method) {
    case 'GET':
        handle_schedule_get($pdo, $user, $permissions);
        break;
    case 'POST':
        $payload = read_json_payload();
        $action = strtolower((string) ($payload['action'] ?? ''));
        if ($action !== 'upsert_cell') {
            json_err('未知操作', 400);
        }
        handle_schedule_upsert($pdo, $user, $permissions, $payload);
        break;
    default:
        header('Allow: GET, POST');
        json_err('Method Not Allowed', 405);
}

function handle_schedule_get(PDO $pdo, array $user, array $permissions): void
{
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
    $startParam = isset($_GET['start']) ? (string) $_GET['start'] : '';
    $endParam = isset($_GET['end']) ? (string) $_GET['end'] : '';

    [$startDate, $endDate] = resolve_date_range($startParam, $endParam);

    $teams = fetch_accessible_teams($pdo, $permissions);
    if ($teamId !== null && $teamId > 0 && !team_in_list($teams, $teamId)) {
        json_err('无权访问该团队', 403);
    }

    if ($teamId === null || $teamId <= 0) {
        $teamId = $teams !== [] ? (int) $teams[0]['id'] : null;
    }

    if ($teamId === null) {
        json_ok([
            'teams' => $teams,
            'team_id' => null,
            'start' => $startDate,
            'end' => $endDate,
            'employees' => [],
            'cells' => new stdClass(),
            'can_edit' => false,
            'shift_options' => get_shift_options(),
            'user' => $user,
        ]);
    }

    $canEdit = $teamId !== null && permissions_can_edit_team($permissions, $teamId);

    $employees = fetch_team_employees($pdo, $teamId);
    $cells = fetch_schedule_cells($pdo, $teamId, $startDate, $endDate);

    json_ok([
        'teams' => $teams,
        'team_id' => $teamId,
        'start' => $startDate,
        'end' => $endDate,
        'employees' => $employees,
        'cells' => $cells,
        'can_edit' => $canEdit,
        'shift_options' => get_shift_options(),
        'user' => $user,
    ]);
}

function handle_schedule_upsert(PDO $pdo, array $user, array $permissions, array $payload): void
{
    $teamId = isset($payload['team_id']) ? (int) $payload['team_id'] : 0;
    if ($teamId <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    if (!permissions_can_access_team($permissions, $teamId)) {
        json_err('无权访问该团队', 403);
    }

    if (!permissions_can_edit_team($permissions, $teamId)) {
        json_err('无权编辑该团队排班', 403);
    }

    $day = isset($payload['day']) ? (string) $payload['day'] : '';
    $dayValue = normalize_day($day);
    if ($dayValue === null) {
        json_err('无效的日期', 422);
    }

    $employeeId = isset($payload['emp_id']) ? (int) $payload['emp_id'] : 0;
    if ($employeeId <= 0) {
        json_err('缺少有效的员工ID', 422);
    }

    if (!employee_belongs_to_team($pdo, $employeeId, $teamId)) {
        json_err('该员工不属于目标团队', 422);
    }

    $rawValue = $payload['value'] ?? '';
    $value = normalize_shift_value($rawValue);
    if ($value === null) {
        json_err('无效的班次值', 422);
    }

    $clientVersion = isset($payload['client_version']) ? (int) $payload['client_version'] : 0;
    if ($clientVersion < 0) {
        $clientVersion = 0;
    }

    $userId = (int) ($user['id'] ?? 0);
    $userDisplay = trim((string) ($user['display_name'] ?? ''));
    if ($userDisplay === '') {
        $userDisplay = (string) ($user['username'] ?? '');
    }

    if ($value === '') {
        $result = process_empty_value_update($pdo, $userId, $userDisplay, $teamId, $employeeId, $dayValue, $clientVersion);
    } else {
        $result = process_value_update($pdo, $userId, $userDisplay, $teamId, $employeeId, $dayValue, $value, $clientVersion);
    }

    if (($result['changed'] ?? false) === true) {
        $cellUser = $result['cell']['updated_by'] ?? null;
        $updatedById = null;
        $updatedByName = $userDisplay;
        if (is_array($cellUser)) {
            if (isset($cellUser['id'])) {
                $updatedById = (int) $cellUser['id'];
            }
            if (isset($cellUser['display_name']) && trim((string) $cellUser['display_name']) !== '') {
                $updatedByName = (string) $cellUser['display_name'];
            }
        }
        $event = [
            'team_id' => $teamId,
            'day' => $dayValue,
            'emp_id' => $employeeId,
            'value' => $result['cell']['value'],
            'version' => $result['cell']['version'],
            'updated_at' => $result['cell']['updated_at'],
            'updated_by' => $updatedById,
            'updated_by_name' => $updatedByName,
        ];
        $actorId = $updatedById;
        record_schedule_op($pdo, $teamId, [
            'type' => 'schedule_cell_update',
            'cell' => $event,
        ], $actorId !== null ? (int) $actorId : null);
        append_sse_event($event);
    }

    json_ok([
        'new_version' => $result['new_version'],
        'cell' => $result['cell'],
    ]);
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

function resolve_date_range(string $start, string $end): array
{
    $today = new DateTimeImmutable('today');
    $defaultStart = $today->modify('first day of this month');
    $defaultEnd = $defaultStart->modify('+29 days');

    $startDate = $start !== '' ? normalize_day($start) : $defaultStart->format('Y-m-d');
    $endDate = $end !== '' ? normalize_day($end) : $defaultEnd->format('Y-m-d');

    if ($startDate === null || $endDate === null) {
        json_err('日期格式应为 YYYY-MM-DD', 422);
    }

    $startObj = new DateTimeImmutable($startDate);
    $endObj = new DateTimeImmutable($endDate);

    if ($startObj > $endObj) {
        [$startObj, $endObj] = [$endObj, $startObj];
        [$startDate, $endDate] = [$startObj->format('Y-m-d'), $endObj->format('Y-m-d')];
    }

    $maxRange = 92;
    $diff = $startObj->diff($endObj)->days ?? 0;
    if ($diff + 1 > $maxRange) {
        json_err('日期范围过大，最多一次查询 ' . $maxRange . ' 天', 422);
    }

    return [$startDate, $endDate];
}

function normalize_day(string $value): ?string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
    if ($date === false) {
        return null;
    }

    return $date->format('Y-m-d');
}

function fetch_accessible_teams(PDO $pdo, array $permissions): array
{
    if (($permissions['is_admin'] ?? false) === true) {
        $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC, id ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $allowed = $permissions['allowed_teams'] ?? [];
    if ($allowed === null) {
        $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC, id ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($allowed === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
    $stmt = $pdo->prepare('SELECT id, name FROM teams WHERE id IN (' . $placeholders . ') ORDER BY name ASC, id ASC');
    $stmt->execute($allowed);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function team_in_list(array $teams, int $teamId): bool
{
    foreach ($teams as $team) {
        if ((int) ($team['id'] ?? 0) === $teamId) {
            return true;
        }
    }

    return false;
}

function fetch_team_employees(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare('SELECT id, name, display_name, active, sort_order FROM employees WHERE team_id = :team ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':team' => $teamId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'display_name' => (string) $row['display_name'],
            'active' => (int) $row['active'] === 1,
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    return $result;
}

function fetch_schedule_cells(PDO $pdo, int $teamId, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare('SELECT sc.id, sc.day, sc.emp_id, sc.value, sc.version, sc.updated_at, sc.updated_by, u.display_name AS updater_name FROM schedule_cells sc LEFT JOIN users u ON sc.updated_by = u.id WHERE sc.team_id = :team AND sc.day >= :start AND sc.day <= :end');
    $stmt->execute([
        ':team' => $teamId,
        ':start' => $startDate,
        ':end' => $endDate,
    ]);

    $grid = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $day = (string) $row['day'];
        $empId = (int) $row['emp_id'];
        if (!isset($grid[$day])) {
            $grid[$day] = [];
        }

        $grid[$day][$empId] = [
            'value' => $row['value'] !== null ? (string) $row['value'] : '',
            'version' => (int) $row['version'],
            'updated_at' => (string) $row['updated_at'],
            'updated_by' => $row['updated_by'] !== null ? [
                'id' => (int) $row['updated_by'],
                'display_name' => (string) ($row['updater_name'] ?? ''),
            ] : null,
        ];
    }

    return $grid;
}

function employee_belongs_to_team(PDO $pdo, int $employeeId, int $teamId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM employees WHERE id = :id AND team_id = :team LIMIT 1');
    $stmt->execute([
        ':id' => $employeeId,
        ':team' => $teamId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function normalize_shift_value($value): ?string
{
    if ($value === null) {
        return '';
    }

    $stringValue = trim((string) $value);
    if ($stringValue === '') {
        return '';
    }

    $options = get_shift_options();
    return in_array($stringValue, $options, true) ? $stringValue : null;
}

function find_schedule_cell(PDO $pdo, int $teamId, int $employeeId, string $day): ?array
{
    $stmt = $pdo->prepare('SELECT id, value, version FROM schedule_cells WHERE team_id = :team AND emp_id = :emp AND day = :day LIMIT 1');
    $stmt->execute([
        ':team' => $teamId,
        ':emp' => $employeeId,
        ':day' => $day,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return $row;
}

function process_empty_value_update(PDO $pdo, int $userId, string $userDisplay, int $teamId, int $employeeId, string $day, int $clientVersion): array
{
    $existing = find_schedule_cell($pdo, $teamId, $employeeId, $day);
    if ($existing === null) {
        return [
            'new_version' => 0,
            'changed' => false,
            'cell' => [
                'day' => $day,
                'emp_id' => $employeeId,
                'value' => '',
                'version' => 0,
                'updated_at' => null,
                'updated_by' => null,
            ],
        ];
    }

    $currentVersion = (int) $existing['version'];
    if ($clientVersion < $currentVersion) {
        json_err('存在更新冲突', 409, [
            'conflict' => true,
            'current_version' => $currentVersion,
            'current_value' => (string) ($existing['value'] ?? ''),
        ]);
    }

    $newVersion = $currentVersion + 1;
    $timestamp = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare('UPDATE schedule_cells SET value = NULL, version = :version, updated_at = :updated_at, updated_by = :updated_by WHERE id = :id');
    $stmt->bindValue(':version', $newVersion, PDO::PARAM_INT);
    $stmt->bindValue(':updated_at', $timestamp, PDO::PARAM_STR);
    if ($userId > 0) {
        $stmt->bindValue(':updated_by', $userId, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':updated_by', null, PDO::PARAM_NULL);
    }
    $stmt->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);
    $stmt->execute();

    return [
        'new_version' => $newVersion,
        'changed' => true,
        'cell' => [
            'day' => $day,
            'emp_id' => $employeeId,
            'value' => '',
            'version' => $newVersion,
            'updated_at' => $timestamp,
            'updated_by' => $userId > 0 ? [
                'id' => $userId,
                'display_name' => $userDisplay,
            ] : null,
        ],
    ];
}

function process_value_update(PDO $pdo, int $userId, string $userDisplay, int $teamId, int $employeeId, string $day, string $value, int $clientVersion): array
{
    $existing = find_schedule_cell($pdo, $teamId, $employeeId, $day);
    $timestamp = date('Y-m-d H:i:s');

    if ($existing !== null) {
        $currentVersion = (int) $existing['version'];
        if ($clientVersion < $currentVersion) {
            json_err('存在更新冲突', 409, [
                'conflict' => true,
                'current_version' => $currentVersion,
                'current_value' => (string) ($existing['value'] ?? ''),
            ]);
        }

        $newVersion = $currentVersion + 1;
        $stmt = $pdo->prepare('UPDATE schedule_cells SET value = :value, version = :version, updated_at = :updated_at, updated_by = :updated_by WHERE id = :id');
        $stmt->bindValue(':value', $value, PDO::PARAM_STR);
        $stmt->bindValue(':version', $newVersion, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', $timestamp, PDO::PARAM_STR);
        if ($userId > 0) {
            $stmt->bindValue(':updated_by', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':updated_by', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);
        $stmt->execute();

        return [
            'new_version' => $newVersion,
            'changed' => true,
            'cell' => [
                'day' => $day,
                'emp_id' => $employeeId,
                'value' => $value,
                'version' => $newVersion,
                'updated_at' => $timestamp,
                'updated_by' => $userId > 0 ? [
                    'id' => $userId,
                    'display_name' => $userDisplay,
                ] : null,
            ],
        ];
    }

    $stmt = $pdo->prepare('INSERT INTO schedule_cells (team_id, day, emp_id, value, version, updated_at, updated_by) VALUES (:team_id, :day, :emp_id, :value, :version, :updated_at, :updated_by)');
    $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
    $stmt->bindValue(':day', $day, PDO::PARAM_STR);
    $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
    $stmt->bindValue(':value', $value, PDO::PARAM_STR);
    $stmt->bindValue(':version', 1, PDO::PARAM_INT);
    $stmt->bindValue(':updated_at', $timestamp, PDO::PARAM_STR);
    if ($userId > 0) {
        $stmt->bindValue(':updated_by', $userId, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':updated_by', null, PDO::PARAM_NULL);
    }
    $stmt->execute();

    return [
        'new_version' => 1,
        'changed' => true,
        'cell' => [
            'day' => $day,
            'emp_id' => $employeeId,
            'value' => $value,
            'version' => 1,
            'updated_at' => $timestamp,
            'updated_by' => $userId > 0 ? [
                'id' => $userId,
                'display_name' => $userDisplay,
            ] : null,
        ],
    ];
}
