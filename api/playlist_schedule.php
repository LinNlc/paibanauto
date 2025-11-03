<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $action = strtolower((string) ($_GET['action'] ?? ''));
    if ($action === 'export') {
        handle_playlist_export();
        return;
    }
    if ($action === 'template') {
        handle_playlist_template();
        return;
    }
    handle_playlist_get();
    return;
}

if ($method === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'import') {
        handle_playlist_import();
        return;
    }

    $payload = read_json_payload();
    $action = strtolower((string) ($payload['action'] ?? ''));
    switch ($action) {
        case 'update_cell':
            handle_playlist_update_cell($payload);
            return;
        case 'auto_fill':
            handle_playlist_auto_fill($payload);
            return;
        default:
            json_err('未知操作', 400);
    }
}

header('Allow: GET, POST');
json_err('Method Not Allowed', 405);

function handle_playlist_get(): void
{
    $context = enforce_view_access('playlistschedule');
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $user = $context['user'];
    $permissions = $context['permissions'];

    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
    $startParam = isset($_GET['start']) ? (string) $_GET['start'] : '';
    $endParam = isset($_GET['end']) ? (string) $_GET['end'] : '';

    [$startDate, $endDate] = playlist_resolve_date_range($startParam, $endParam);

    $teams = playlist_fetch_accessible_teams($pdo, $permissions);

    if ($teamId !== null && $teamId > 0 && !playlist_team_in_list($teams, $teamId)) {
        permission_denied();
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
            'user' => $user,
            'features' => playlist_feature_payload($permissions),
            'on_duty' => new stdClass(),
            'playlist_settings' => playlist_default_settings(),
        ]);
    }

    ensure_team_access($permissions, $teamId);

    $employees = fetch_team_employees($pdo, $teamId);
    $cells = playlist_fetch_cells($pdo, $teamId, $startDate, $endDate);
    $playlistSettings = playlist_load_settings($pdo, $teamId);

    $grid = [];
    foreach ($cells as $cell) {
        $day = $cell['day'];
        $shift = $cell['shift'];
        if (!isset($grid[$day])) {
            $grid[$day] = [];
        }
        $grid[$day][$shift] = [
            'emp_id' => $cell['emp_id'],
            'emp_name' => $cell['emp_name'],
            'emp_display' => $cell['emp_display'],
            'version' => $cell['version'],
            'updated_at' => $cell['updated_at'],
            'updated_by' => $cell['updated_by'],
        ];
    }

    json_ok([
        'teams' => $teams,
        'team_id' => $teamId,
        'start' => $startDate,
        'end' => $endDate,
        'employees' => $employees,
        'cells' => $grid,
        'can_edit' => permissions_can_edit_team($permissions, $teamId),
        'user' => $user,
        'features' => playlist_feature_payload($permissions),
        'on_duty' => playlist_fetch_on_duty($pdo, $teamId, $startDate, $endDate),
        'playlist_settings' => $playlistSettings,
    ]);
}

function handle_playlist_update_cell(array $payload): void
{
    $teamId = isset($payload['team_id']) ? (int) $payload['team_id'] : 0;
    $dayValue = isset($payload['day']) ? (string) $payload['day'] : '';
    $shiftValue = isset($payload['shift']) ? (string) $payload['shift'] : '';
    $empId = isset($payload['emp_id']) ? (int) $payload['emp_id'] : 0;
    $clientVersion = isset($payload['client_version']) ? (int) $payload['client_version'] : 0;

    if ($teamId <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    $context = enforce_edit_access($teamId);
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $user = $context['user'];

    $day = playlist_normalize_day($dayValue);
    if ($day === null) {
        json_err('无效的日期', 422);
    }

    $shift = normalize_playlist_shift($shiftValue);
    if ($shift === null) {
        json_err('无效的班次', 422);
    }

    $employeeId = $empId > 0 ? $empId : null;
    $employeeName = '';
    if ($employeeId !== null) {
        if (!playlist_employee_belongs_to_team($pdo, $employeeId, $teamId)) {
            json_err('该员工不属于目标团队', 422);
        }
        $map = playlist_employee_map($pdo, $teamId);
        if (!isset($map[$employeeId])) {
            json_err('员工不存在', 404);
        }
        $employeeName = $map[$employeeId]['label'];
        $onDutyMap = playlist_fetch_on_duty($pdo, $teamId, $day, $day);
        $onDutyDay = $onDutyMap[$day] ?? [];
        $shiftRoster = [];
        if (is_array($onDutyDay)) {
            if (isset($onDutyDay[$shift]) && is_array($onDutyDay[$shift])) {
                $shiftRoster = $onDutyDay[$shift];
            } elseif (isset($onDutyDay['all']) && is_array($onDutyDay['all'])) {
                $shiftRoster = $onDutyDay['all'];
            }
        }
        if (!in_array($employeeId, $shiftRoster, true)) {
            json_err('该员工当天未在排班日历上班，无法分配任务', 422);
        }
    }

    $userId = (int) ($user['id'] ?? 0);

    if ($employeeId === null) {
        $result = playlist_clear_cell($pdo, $teamId, $day, $shift, $userId, $clientVersion);
    } else {
        $result = playlist_store_cell($pdo, $teamId, $day, $shift, $employeeId, $employeeName, $userId, $clientVersion);
    }

    $updated = playlist_fetch_cells($pdo, $teamId, $day, $day);
    $payload = null;
    foreach ($updated as $cell) {
        if ($cell['day'] === $day && $cell['shift'] === $shift) {
            $payload = $cell;
            break;
        }
    }

    json_ok([
        'changed' => $result['changed'],
        'cell' => $payload,
    ]);
}

function handle_playlist_auto_fill(array $payload): void
{
    $teamId = isset($payload['team_id']) ? (int) $payload['team_id'] : 0;
    if ($teamId <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    $context = enforce_edit_access($teamId);
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $user = $context['user'];
    $permissions = $context['permissions'];

    if (!permissions_has_feature($permissions, 'playlistAutoFill')) {
        permission_denied();
    }

    $startParam = isset($payload['start']) ? (string) $payload['start'] : '';
    $endParam = isset($payload['end']) ? (string) $payload['end'] : '';
    [$startDate, $endDate] = playlist_resolve_date_range($startParam, $endParam);

    $whiteDuration = playlist_normalize_fraction($payload['white_duration'] ?? null);
    $midDuration = playlist_normalize_fraction($payload['mid_duration'] ?? null);
    $maxDiff = playlist_normalize_fraction($payload['max_diff'] ?? null);

    if ($whiteDuration < 0.0 || $whiteDuration > 1.0 || $midDuration < 0.0 || $midDuration > 1.0) {
        json_err('班次时长需在 0 至 1 之间', 422);
    }

    if ($maxDiff < 0.0 || $maxDiff > 1.0) {
        json_err('最大差值需在 0 至 1 之间', 422);
    }
    $empList = normalize_id_list($payload['emp_ids'] ?? []);

    if ($empList === []) {
        json_err('请至少选择一名员工', 422);
    }

    $map = playlist_employee_map($pdo, $teamId);
    $employees = [];
    foreach ($empList as $id) {
        if (!isset($map[$id])) {
            json_err('员工不存在或不可用', 422);
        }
        $employees[] = $map[$id];
    }

    $settingsPayload = [
        'white_duration' => $whiteDuration,
        'mid_duration' => $midDuration,
        'max_diff' => $maxDiff,
    ];
    $normalizedSettings = normalize_playlist_settings($settingsPayload);
    playlist_save_settings($pdo, $teamId, $normalizedSettings);
    $whiteDuration = $normalizedSettings['white_duration'];
    $midDuration = $normalizedSettings['mid_duration'];
    $maxDiff = $normalizedSettings['max_diff'];

    $days = playlist_generate_days($startDate, $endDate);

    $onDutyMap = playlist_fetch_on_duty($pdo, $teamId, $startDate, $endDate);
    $whiteAvailability = [];
    $midAvailability = [];
    $missingDays = [];
    $hasAnyAvailability = false;

    foreach ($days as $day) {
        $roster = $onDutyMap[$day] ?? [];

        $whiteRosterRaw = [];
        $midRosterRaw = [];
        $allRosterRaw = [];

        if (is_array($roster) && (isset($roster['white']) || isset($roster['mid']) || isset($roster['all']))) {
            $whiteRosterRaw = is_array($roster['white'] ?? null) ? $roster['white'] : [];
            $midRosterRaw = is_array($roster['mid'] ?? null) ? $roster['mid'] : [];
            $allRosterRaw = is_array($roster['all'] ?? null) ? $roster['all'] : array_merge($whiteRosterRaw, $midRosterRaw);
        } elseif (is_array($roster)) {
            $allRosterRaw = $roster;
            $whiteRosterRaw = $roster;
            $midRosterRaw = $roster;
        }

        $normalizeIds = static function (array $source): array {
            $result = [];
            foreach ($source as $value) {
                $id = (int) $value;
                if ($id > 0) {
                    $result[$id] = $id;
                }
            }
            return array_values($result);
        };

        $filterSelected = static function (array $source) use ($empList): array {
            $allowed = [];
            foreach ($source as $value) {
                $id = (int) $value;
                if ($id > 0 && in_array($id, $empList, true)) {
                    $allowed[$id] = $id;
                }
            }
            return array_values($allowed);
        };

        $whiteRosterAll = $normalizeIds($whiteRosterRaw);
        $midRosterAll = $normalizeIds($midRosterRaw);
        $allRosterAll = $normalizeIds($allRosterRaw);

        if ($allRosterAll === []) {
            $missingDays[] = $day;
        }

        $whiteAllowed = $filterSelected($whiteRosterAll);
        $midAllowed = $filterSelected($midRosterAll);

        if ($whiteAllowed !== []) {
            $whiteAvailability[$day] = $whiteAllowed;
            $hasAnyAvailability = true;
        }

        if ($midAllowed !== []) {
            $midAvailability[$day] = $midAllowed;
            $hasAnyAvailability = true;
        }
    }

    if (!$hasAnyAvailability) {
        json_err('所选时间段内排班日历为空，存在空班次，请先在排班日历维护班次', 422);
    }

    $whiteAssignments = playlist_generate_assignments($days, $employees, max($whiteDuration, 0.0), $maxDiff, [], $whiteAvailability);
    $midAssignments = playlist_generate_assignments($days, $employees, max($midDuration, 0.0), $maxDiff, $whiteAssignments, $midAvailability);

    $emptyShiftWarnings = [];
    foreach ($days as $day) {
        if (isset($whiteAvailability[$day]) && !isset($whiteAssignments[$day])) {
            $emptyShiftWarnings[] = $day . ' 白班';
        }
        if (isset($midAvailability[$day]) && !isset($midAssignments[$day])) {
            $emptyShiftWarnings[] = $day . ' 中班';
        }
    }

    $pdo->beginTransaction();
    try {
        $userId = (int) ($user['id'] ?? 0);
        foreach ($days as $day) {
            $whiteEmp = $whiteAssignments[$day] ?? null;
            $midEmp = $midAssignments[$day] ?? null;

            if ($whiteEmp !== null && isset($map[$whiteEmp])) {
                $info = $map[$whiteEmp];
                playlist_store_cell($pdo, $teamId, $day, 'white', $info['id'], $info['label'], $userId, 0, true);
            } else {
                playlist_store_cell($pdo, $teamId, $day, 'white', null, '', $userId, 0, true);
            }
            if ($midEmp !== null && isset($map[$midEmp])) {
                $info = $map[$midEmp];
                playlist_store_cell($pdo, $teamId, $day, 'mid', $info['id'], $info['label'], $userId, 0, true);
            } else {
                playlist_store_cell($pdo, $teamId, $day, 'mid', null, '', $userId, 0, true);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $cells = playlist_fetch_cells($pdo, $teamId, $startDate, $endDate);
    $grid = [];
    foreach ($cells as $cell) {
        $day = $cell['day'];
        if (!isset($grid[$day])) {
            $grid[$day] = [];
        }
        $grid[$day][$cell['shift']] = [
            'emp_id' => $cell['emp_id'],
            'emp_name' => $cell['emp_name'],
            'emp_display' => $cell['emp_display'],
            'version' => $cell['version'],
            'updated_at' => $cell['updated_at'],
            'updated_by' => $cell['updated_by'],
        ];
    }

    json_ok([
        'cells' => $grid,
        'on_duty' => playlist_fetch_on_duty($pdo, $teamId, $startDate, $endDate),
        'playlist_settings' => $normalizedSettings,
        'warnings' => [
            'missing_days' => array_values($missingDays),
            'empty_shifts' => array_values($emptyShiftWarnings),
        ],
    ]);
}

function handle_playlist_import(): void
{
    $teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
    if ($teamId <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    $context = enforce_edit_access($teamId);
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $user = $context['user'];
    $permissions = $context['permissions'];

    if (!permissions_has_feature($permissions, 'playlistImportExport')) {
        permission_denied();
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        json_err('请上传文件', 422);
    }

    $file = $_FILES['file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        json_err('文件上传失败', 422);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        json_err('文件无效', 422);
    }

    $rows = playlist_read_xlsx($tmpPath);
    if ($rows === []) {
        json_err('Excel 内容为空', 422);
    }

    $map = playlist_employee_map($pdo, $teamId);
    if ($map === []) {
        json_err('请先维护团队人员', 422);
    }

    $nameIndex = [];
    foreach ($map as $employee) {
        $label = trim((string) $employee['label']);
        if ($label !== '') {
            $nameIndex[mb_strtolower($label)] = $employee['id'];
        }
        $name = trim((string) $employee['name']);
        if ($name !== '') {
            $nameIndex[mb_strtolower($name)] = $employee['id'];
        }
        $display = trim((string) $employee['display_name']);
        if ($display !== '') {
            $nameIndex[mb_strtolower($display)] = $employee['id'];
        }
    }

    $assignments = [];
    foreach ($rows as $idx => $row) {
        if ($idx === 0) {
            continue; // header
        }
        $dayCell = $row[0] ?? '';
        $day = playlist_extract_day($dayCell);
        if ($day === null) {
            continue;
        }
        $whiteName = trim((string) ($row[2] ?? ''));
        $midName = trim((string) ($row[3] ?? ''));
        if ($whiteName === '' && $midName === '') {
            continue;
        }
        if (!isset($assignments[$day])) {
            $assignments[$day] = [];
        }
        if ($whiteName !== '') {
            $whiteId = $nameIndex[mb_strtolower($whiteName)] ?? null;
            if ($whiteId === null) {
                json_err('无法匹配白班人员：' . $whiteName, 422);
            }
            $assignments[$day]['white'] = $whiteId;
        }
        if ($midName !== '') {
            $midId = $nameIndex[mb_strtolower($midName)] ?? null;
            if ($midId === null) {
                json_err('无法匹配中班人员：' . $midName, 422);
            }
            $assignments[$day]['mid'] = $midId;
        }
    }

    if ($assignments === []) {
        json_err('未识别到任何班次', 422);
    }

    $days = array_keys($assignments);
    sort($days);
    $startDate = $days[0];
    $endDate = $days[count($days) - 1];

    $pdo->beginTransaction();
    try {
        $userId = (int) ($user['id'] ?? 0);
        foreach ($assignments as $day => $shiftMap) {
            foreach ($shiftMap as $shift => $empId) {
                if (!isset($map[$empId])) {
                    continue;
                }
                $label = $map[$empId]['label'];
                playlist_store_cell($pdo, $teamId, $day, $shift, $empId, $label, $userId, 0, true);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $cells = playlist_fetch_cells($pdo, $teamId, $startDate, $endDate);
    $grid = [];
    foreach ($cells as $cell) {
        $day = $cell['day'];
        if (!isset($grid[$day])) {
            $grid[$day] = [];
        }
        $grid[$day][$cell['shift']] = [
            'emp_id' => $cell['emp_id'],
            'emp_name' => $cell['emp_name'],
            'emp_display' => $cell['emp_display'],
            'version' => $cell['version'],
            'updated_at' => $cell['updated_at'],
            'updated_by' => $cell['updated_by'],
        ];
    }

    json_ok([
        'start' => $startDate,
        'end' => $endDate,
        'cells' => $grid,
        'on_duty' => playlist_fetch_on_duty($pdo, $teamId, $startDate, $endDate),
        'playlist_settings' => playlist_load_settings($pdo, $teamId),
    ]);
}

function handle_playlist_export(): void
{
    $context = enforce_view_access('playlistschedule');
    /** @var PDO $pdo */
    $pdo = $context['pdo'];
    $permissions = $context['permissions'];

    if (!permissions_has_feature($permissions, 'playlistImportExport')) {
        permission_denied();
    }

    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
    if ($teamId <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    ensure_team_access($permissions, $teamId);

    $startParam = isset($_GET['start']) ? (string) $_GET['start'] : '';
    $endParam = isset($_GET['end']) ? (string) $_GET['end'] : '';
    [$startDate, $endDate] = playlist_resolve_date_range($startParam, $endParam);

    $cells = playlist_fetch_cells($pdo, $teamId, $startDate, $endDate);

    $byDay = [];
    foreach ($cells as $cell) {
        $day = $cell['day'];
        if (!isset($byDay[$day])) {
            $byDay[$day] = [];
        }
        $byDay[$day][$cell['shift']] = $cell['emp_display'];
    }

    $days = playlist_generate_days($startDate, $endDate);
    $rows = [];
    $rows[] = ['日期', '星期', '白班', '中班'];
    foreach ($days as $day) {
        $week = playlist_weekday_label($day);
        $white = $byDay[$day]['white'] ?? '';
        $mid = $byDay[$day]['mid'] ?? '';
        $rows[] = [$day, $week, $white, $mid];
    }

    $fileName = 'playlist-schedule-' . $startDate . '-to-' . $endDate . '.xlsx';
    playlist_output_xlsx($rows, $fileName);
}

function handle_playlist_template(): void
{
    $context = auth_context();
    $permissions = $context['permissions'];
    if (!permissions_has_feature($permissions, 'playlistImportExport')) {
        permission_denied();
    }

    $rows = [
        ['日期', '星期', '白班', '中班'],
        ['2024-06-01 星期六', '', '示例：张三', '示例：李四'],
    ];

    playlist_output_xlsx($rows, 'playlist-template.xlsx');
}

function playlist_resolve_date_range(string $start, string $end): array
{
    $today = new DateTimeImmutable('today');
    $defaultStart = $today->modify('first day of this month');
    $defaultEnd = $defaultStart->modify('+29 days');

    $startDate = $start !== '' ? playlist_normalize_day($start) : $defaultStart->format('Y-m-d');
    $endDate = $end !== '' ? playlist_normalize_day($end) : $defaultEnd->format('Y-m-d');

    if ($startDate === null || $endDate === null) {
        json_err('日期格式应为 YYYY-MM-DD', 422);
    }

    $startObj = new DateTimeImmutable($startDate);
    $endObj = new DateTimeImmutable($endDate);

    if ($startObj > $endObj) {
        [$startObj, $endObj] = [$endObj, $startObj];
        [$startDate, $endDate] = [$startObj->format('Y-m-d'), $endObj->format('Y-m-d')];
    }

    $diff = $startObj->diff($endObj)->days ?? 0;
    if ($diff + 1 > 180) {
        json_err('日期范围最多 180 天', 422);
    }

    return [$startDate, $endDate];
}

function playlist_normalize_day(string $value): ?string
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

function playlist_fetch_accessible_teams(PDO $pdo, array $permissions): array
{
    if (($permissions['is_admin'] ?? false) === true) {
        $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC, id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }, $rows);
    }

    $allowed = $permissions['allowed_teams'] ?? [];
    if ($allowed === null) {
        $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC, id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }, $rows);
    }

    if ($allowed === [] || !is_array($allowed)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
    $stmt = $pdo->prepare('SELECT id, name FROM teams WHERE id IN (' . $placeholders . ') ORDER BY name ASC, id ASC');
    $stmt->execute($allowed);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }, $rows);
}

function playlist_team_in_list(array $teams, int $teamId): bool
{
    foreach ($teams as $team) {
        if ((int) ($team['id'] ?? 0) === $teamId) {
            return true;
        }
    }
    return false;
}

function playlist_fetch_on_duty(PDO $pdo, int $teamId, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare('SELECT day, emp_id, value FROM schedule_cells WHERE team_id = :team AND day >= :start AND day <= :end');
    $stmt->execute([
        ':team' => $teamId,
        ':start' => $startDate,
        ':end' => $endDate,
    ]);

    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $day = (string) ($row['day'] ?? '');
        $empId = isset($row['emp_id']) ? (int) $row['emp_id'] : 0;
        if ($day === '' || $empId <= 0) {
            continue;
        }
        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '' || $value === '休息') {
            continue;
        }
        if (!isset($result[$day])) {
            $result[$day] = [
                'all' => [],
                'white' => [],
                'mid' => [],
            ];
        }
        if (!in_array($empId, $result[$day]['all'], true)) {
            $result[$day]['all'][] = $empId;
        }

        $shift = playlist_detect_shift_from_value($value);
        if ($shift !== null && isset($result[$day][$shift]) && !in_array($empId, $result[$day][$shift], true)) {
            $result[$day][$shift][] = $empId;
        }
    }

    foreach ($result as $day => $roster) {
        $white = array_values(array_unique($roster['white'] ?? []));
        $mid = array_values(array_unique($roster['mid'] ?? []));
        $all = $roster['all'] ?? [];
        if ($all === []) {
            $all = array_values(array_unique(array_merge($white, $mid)));
        } else {
            $all = array_values(array_unique($all));
        }

        $result[$day] = [
            'all' => $all,
            'white' => $white,
            'mid' => $mid,
        ];

        if ($result[$day]['all'] === [] && $result[$day]['white'] === [] && $result[$day]['mid'] === []) {
            unset($result[$day]);
        }
    }

    return $result;
}

function playlist_employee_belongs_to_team(PDO $pdo, int $employeeId, int $teamId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM employees WHERE id = :id AND team_id = :team LIMIT 1');
    $stmt->execute([
        ':id' => $employeeId,
        ':team' => $teamId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function playlist_feature_payload(array $permissions): array
{
    return [
        'floatingBall' => permissions_has_feature($permissions, 'playlistFloatingBall'),
        'importExport' => permissions_has_feature($permissions, 'playlistImportExport'),
        'autoFill' => permissions_has_feature($permissions, 'playlistAutoFill'),
    ];
}

function playlist_generate_days(string $startDate, string $endDate): array
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    $days = [];
    for ($current = $start; $current <= $end; $current = $current->modify('+1 day')) {
        $days[] = $current->format('Y-m-d');
    }
    return $days;
}

function playlist_generate_assignments(
    array $days,
    array $employees,
    float $duration,
    float $maxDiff,
    array $avoidSameDay,
    array $availabilityByDay = []
): array
{
    $order = [];
    foreach ($employees as $index => $emp) {
        $order[(int) $emp['id']] = $index;
    }
    $counts = [];
    $streak = [];
    foreach ($employees as $emp) {
        $counts[(int) $emp['id']] = 0;
        $streak[(int) $emp['id']] = 0;
    }

    $assignments = [];
    $lastEmp = null;

    foreach ($days as $day) {
        $forbidden = [];
        if (isset($avoidSameDay[$day])) {
            $forbidden[] = $avoidSameDay[$day];
        }

        $allowed = null;
        if ($availabilityByDay !== [] && !array_key_exists($day, $availabilityByDay)) {
            continue;
        }
        if (array_key_exists($day, $availabilityByDay)) {
            $allowedList = $availabilityByDay[$day];
            if (!is_array($allowedList) || $allowedList === []) {
                continue;
            }
            $allowed = [];
            foreach ($allowedList as $value) {
                $id = (int) $value;
                if (isset($order[$id])) {
                    $allowed[$id] = true;
                }
            }
            if ($allowed === []) {
                continue;
            }
        }

        $candidates = $employees;
        if ($allowed !== null) {
            $candidates = array_values(array_filter($employees, static function ($emp) use ($allowed) {
                $empId = (int) ($emp['id'] ?? 0);
                return isset($allowed[$empId]);
            }));
        }

        if ($candidates === []) {
            continue;
        }

        usort($candidates, static function ($a, $b) use ($counts, $order): int {
            $aId = (int) $a['id'];
            $bId = (int) $b['id'];
            $diff = ($counts[$aId] ?? 0) - ($counts[$bId] ?? 0);
            if ($diff !== 0) {
                return $diff;
            }
            return ($order[$aId] ?? 0) <=> ($order[$bId] ?? 0);
        });

        $chosen = null;
        foreach ($candidates as $candidate) {
            $candidateId = (int) $candidate['id'];
            if (in_array($candidateId, $forbidden, true)) {
                continue;
            }
            if ($candidateId === $lastEmp && $streak[$candidateId] >= $duration) {
                continue;
            }
            $simCounts = $counts;
            $simCounts[$candidateId] = ($simCounts[$candidateId] ?? 0) + 1;
            $min = min($simCounts);
            $max = max($simCounts);
            if ($maxDiff >= 0 && $max - $min > $maxDiff) {
                continue;
            }
            $chosen = $candidateId;
            break;
        }

        if ($chosen === null && $candidates !== []) {
            foreach ($candidates as $candidate) {
                $candidateId = (int) $candidate['id'];
                if (in_array($candidateId, $forbidden, true)) {
                    continue;
                }
                if ($candidateId === $lastEmp && $streak[$candidateId] >= $duration) {
                    continue;
                }
                $chosen = $candidateId;
                break;
            }
        }

        if ($chosen === null && $candidates !== []) {
            $chosen = (int) $candidates[0]['id'];
        }

        if ($chosen !== null) {
            $assignments[$day] = $chosen;
            $counts[$chosen] = ($counts[$chosen] ?? 0) + 1;
            if ($chosen === $lastEmp) {
                $streak[$chosen] = ($streak[$chosen] ?? 0) + 1;
            } else {
                $streak[$chosen] = 1;
                if ($lastEmp !== null) {
                    $streak[$lastEmp] = 0;
                }
                $lastEmp = $chosen;
            }
        }
    }

    return $assignments;
}

function playlist_weekday_label(string $day): string
{
    $date = new DateTimeImmutable($day);
    $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
    $index = (int) $date->format('w');
    return '星期' . $weekdays[$index];
}

function playlist_extract_day(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
        return $matches[1];
    }

    return playlist_normalize_day($value);
}

function playlist_read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        json_err('无法读取 Excel 文件', 422);
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        json_err('缺少工作表数据', 422);
    }

    $rows = [];
    $xml = simplexml_load_string($sheetXml);
    if ($xml === false) {
        $zip->close();
        json_err('解析 Excel 失败', 422);
    }

    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $text = '';
            if (isset($cell->is->t)) {
                $text = (string) $cell->is->t;
            } elseif (isset($cell->v)) {
                $text = (string) $cell->v;
            }
            $ref = isset($cell['r']) ? (string) $cell['r'] : '';
            $colIndex = playlist_column_index($ref);
            if ($colIndex <= 0) {
                $colIndex = count($cells) + 1;
            }
            while (count($cells) < $colIndex - 1) {
                $cells[] = '';
            }
            $cells[$colIndex - 1] = $text;
        }
        $rows[] = $cells;
    }

    $zip->close();
    return $rows;
}

function playlist_output_xlsx(array $rows, string $fileName): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'playlist');
    if ($tmp === false) {
        json_err('无法创建临时文件', 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        json_err('创建 Excel 失败', 500);
    }

    $zip->addFromString('[Content_Types].xml', playlist_content_types_xml());
    $zip->addFromString('_rels/.rels', playlist_root_rels_xml());
    $zip->addFromString('xl/workbook.xml', playlist_workbook_xml());
    $zip->addFromString('xl/_rels/workbook.xml.rels', playlist_workbook_rels_xml());
    $zip->addFromString('xl/styles.xml', playlist_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', playlist_sheet_xml($rows));
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function playlist_content_types_xml(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;
}

function playlist_root_rels_xml(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
}

function playlist_workbook_xml(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Schedule" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML;
}

function playlist_workbook_rels_xml(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
}

function playlist_styles_xml(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="1"><font><name val="微软雅黑"/><family val="2"/></font></fonts>
    <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
    <borders count="1"><border/></borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>
</styleSheet>
XML;
}

function playlist_sheet_xml(array $rows): string
{
    $xmlRows = [];
    foreach ($rows as $rowIndex => $columns) {
        $cells = [];
        foreach ($columns as $colIndex => $value) {
            $cellRef = playlist_column_name($colIndex + 1) . ($rowIndex + 1);
            $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $cells[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
        }
        $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $content = implode('', $xmlRows);

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        $content
    </sheetData>
</worksheet>
XML;
}

function playlist_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function playlist_column_index(string $ref): int
{
    if ($ref === '') {
        return 0;
    }

    if (preg_match('/^([A-Z]+)/i', $ref, $matches)) {
        $letters = strtoupper($matches[1]);
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index;
    }

    return 0;
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
