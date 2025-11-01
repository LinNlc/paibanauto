<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET') {
    header('Allow: GET');
    json_err('Method Not Allowed', 405);
}

$context = enforce_view_access('stats');
/** @var PDO $pdo */
$pdo = $context['pdo'];
$permissions = $context['permissions'];

$action = (string) ($_GET['action'] ?? '');
if ($action === '') {
    json_err('缺少 action 参数', 422);
}

switch ($action) {
    case 'by_person':
        handle_by_person($pdo, $permissions);
        break;
    case 'coverage_by_day':
        handle_coverage_by_day($pdo, $permissions);
        break;
    default:
        json_err('未知操作', 400);
}

function handle_by_person(PDO $pdo, array $permissions): void
{
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
    $startParam = isset($_GET['start']) ? (string) $_GET['start'] : '';
    $endParam = isset($_GET['end']) ? (string) $_GET['end'] : '';

    [$startDate, $endDate] = resolve_date_range($startParam, $endParam);
    $rangeDays = calculate_range_days($startDate, $endDate);
    $monthFactor = $rangeDays > 0 ? $rangeDays / 30.0 : 1.0;

    $teamRows = fetch_accessible_teams($pdo, $permissions);
    $teams = normalize_team_rows($teamRows);

    if ($teamId !== null && $teamId > 0 && !team_in_list($teams, $teamId)) {
        permission_denied();
    }

    if ($teamId === null || $teamId <= 0) {
        $teamId = $teams !== [] ? (int) $teams[0]['id'] : null;
    }

    $config = app_config();
    $targetWhite = (int) ($config['target_white_per_month'] ?? 8);

    if ($teamId === null) {
        json_ok([
            'teams' => $teams,
            'team_id' => null,
            'start' => $startDate,
            'end' => $endDate,
            'range_days' => $rangeDays,
            'month_factor' => $monthFactor,
            'shift_options' => get_shift_options(),
            'target_white_per_month' => $targetWhite,
            'records' => [],
        ]);
        return;
    }

    $records = fetch_person_stats($pdo, $teamId, $startDate, $endDate, $monthFactor, $targetWhite);

    json_ok([
        'teams' => $teams,
        'team_id' => $teamId,
        'start' => $startDate,
        'end' => $endDate,
        'range_days' => $rangeDays,
        'month_factor' => $monthFactor,
        'shift_options' => get_shift_options(),
        'target_white_per_month' => $targetWhite,
        'records' => $records,
    ]);
}

function handle_coverage_by_day(PDO $pdo, array $permissions): void
{
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
    $startParam = isset($_GET['start']) ? (string) $_GET['start'] : '';
    $endParam = isset($_GET['end']) ? (string) $_GET['end'] : '';

    [$startDate, $endDate] = resolve_date_range($startParam, $endParam);
    $rangeDays = calculate_range_days($startDate, $endDate);

    $teamRows = fetch_accessible_teams($pdo, $permissions);
    $teams = normalize_team_rows($teamRows);

    if ($teamId !== null && $teamId > 0 && !team_in_list($teams, $teamId)) {
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
            'range_days' => $rangeDays,
            'shift_options' => get_shift_options(),
            'daily' => [],
        ]);
        return;
    }

    $daily = fetch_coverage_stats($pdo, $teamId, $startDate, $endDate);

    json_ok([
        'teams' => $teams,
        'team_id' => $teamId,
        'start' => $startDate,
        'end' => $endDate,
        'range_days' => $rangeDays,
        'shift_options' => get_shift_options(),
        'daily' => $daily,
    ]);
}

function resolve_date_range(string $start, string $end): array
{
    $today = new DateTimeImmutable('today');
    $defaultStart = $today->modify('first day of this month');
    $defaultEnd = $defaultStart->modify('+29 days');

    $startDate = $start !== '' ? normalize_day($start) : $defaultStart->format('Y-m-d');
    $endDate = $end !== '' ? normalize_day($end) : $defaultEnd->format('Y-m-d');

    if ($startDate === null || $endDate === null) {
        json_err('无效的日期范围', 422);
    }

    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
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

function calculate_range_days(string $start, string $end): int
{
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    $diff = $startDate->diff($endDate);

    return (int) $diff->days + 1;
}

function fetch_accessible_teams(PDO $pdo, array $permissions): array
{
    if (($permissions['is_admin'] ?? false) === true) {
        $stmt = $pdo->query('SELECT id, name, settings_json FROM teams ORDER BY name ASC, id ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $allowed = $permissions['allowed_teams'] ?? [];
    if ($allowed === null) {
        $stmt = $pdo->query('SELECT id, name, settings_json FROM teams ORDER BY name ASC, id ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($allowed === [] || !is_array($allowed)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
    $stmt = $pdo->prepare('SELECT id, name, settings_json FROM teams WHERE id IN (' . $placeholders . ') ORDER BY name ASC, id ASC');
    $stmt->execute($allowed);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function normalize_team_rows(array $rows): array
{
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'settings' => decode_team_settings($row['settings_json'] ?? '{}'),
        ];
    }

    return $result;
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

function fetch_person_stats(PDO $pdo, int $teamId, string $startDate, string $endDate, float $monthFactor, int $targetWhite): array
{
    $sql = <<<SQL
SELECT e.id,
       e.name,
       e.display_name,
       e.active,
       e.sort_order,
       SUM(CASE WHEN sc.value IS NOT NULL AND sc.value != '' AND sc.value != '休息' THEN 1 ELSE 0 END) AS work_days,
       SUM(CASE WHEN sc.value = '白' THEN 1 ELSE 0 END) AS white_days,
        SUM(CASE WHEN sc.value = '中1' THEN 1 ELSE 0 END) AS mid1_days,
        SUM(CASE WHEN sc.value = '中2' THEN 1 ELSE 0 END) AS mid2_days,
        SUM(CASE WHEN sc.value = '夜' THEN 1 ELSE 0 END) AS night_days,
        SUM(CASE WHEN sc.value = '休息' THEN 1 ELSE 0 END) AS rest_days
  FROM employees e
  LEFT JOIN schedule_cells sc
    ON sc.emp_id = e.id
   AND sc.team_id = :team
   AND sc.day >= :start
   AND sc.day <= :end
 WHERE e.team_id = :team
 GROUP BY e.id, e.name, e.display_name, e.active, e.sort_order
 ORDER BY e.sort_order ASC, e.id ASC
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':team' => $teamId,
        ':start' => $startDate,
        ':end' => $endDate,
    ]);

    $records = [];
    $safeMonthFactor = $monthFactor > 0 ? $monthFactor : 1.0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $whiteDays = (int) ($row['white_days'] ?? 0);
        $mid1Days = (int) ($row['mid1_days'] ?? 0);
        $mid2Days = (int) ($row['mid2_days'] ?? 0);
        $nightDays = (int) ($row['night_days'] ?? 0);
        $restDays = (int) ($row['rest_days'] ?? 0);
        $workDays = (int) ($row['work_days'] ?? 0);

        $monthlyWhite = round($whiteDays / $safeMonthFactor, 2);
        $monthlyMid1 = round($mid1Days / $safeMonthFactor, 2);
        $monthlyMid2 = round($mid2Days / $safeMonthFactor, 2);
        $monthlyNight = round($nightDays / $safeMonthFactor, 2);

        $records[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'display_name' => (string) $row['display_name'],
            'active' => (int) $row['active'] === 1,
            'work_days' => $workDays,
            'rest_days' => $restDays,
            'shift_counts' => [
                '白' => $whiteDays,
                '中1' => $mid1Days,
                '中2' => $mid2Days,
                '夜' => $nightDays,
                '休息' => $restDays,
            ],
            'monthly_average' => [
                '白' => $monthlyWhite,
                '中1' => $monthlyMid1,
                '中2' => $monthlyMid2,
                '夜' => $monthlyNight,
            ],
            'white_target_diff' => round($monthlyWhite - $targetWhite, 2),
        ];
    }

    return $records;
}

function fetch_coverage_stats(PDO $pdo, int $teamId, string $startDate, string $endDate): array
{
    $sql = <<<SQL
SELECT sc.day AS day,
       SUM(CASE WHEN sc.value IS NOT NULL AND sc.value != '' AND sc.value != '休息' THEN 1 ELSE 0 END) AS on_duty,
       SUM(CASE WHEN sc.value = '白' THEN 1 ELSE 0 END) AS white_days,
       SUM(CASE WHEN sc.value = '中1' THEN 1 ELSE 0 END) AS mid1_days,
       SUM(CASE WHEN sc.value = '中2' THEN 1 ELSE 0 END) AS mid2_days,
       SUM(CASE WHEN sc.value = '夜' THEN 1 ELSE 0 END) AS night_days
  FROM schedule_cells sc
 WHERE sc.team_id = :team
   AND sc.day >= :start
   AND sc.day <= :end
 GROUP BY sc.day
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':team' => $teamId,
        ':start' => $startDate,
        ':end' => $endDate,
    ]);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $day = (string) $row['day'];
        $map[$day] = [
            'on_duty' => (int) ($row['on_duty'] ?? 0),
            'white' => (int) ($row['white_days'] ?? 0),
            'mid1' => (int) ($row['mid1_days'] ?? 0),
            'mid2' => (int) ($row['mid2_days'] ?? 0),
            'night' => (int) ($row['night_days'] ?? 0),
        ];
    }

    $results = [];
    $current = new DateTimeImmutable($startDate);
    $endDateObj = new DateTimeImmutable($endDate);

    while ($current <= $endDateObj) {
        $day = $current->format('Y-m-d');
        $counts = $map[$day] ?? [
            'on_duty' => 0,
            'white' => 0,
            'mid1' => 0,
            'mid2' => 0,
            'night' => 0,
        ];

        $results[] = [
            'day' => $day,
            'weekday' => weekday_name($current),
            'on_duty' => $counts['on_duty'],
            'shift_counts' => [
                '白' => $counts['white'],
                '中1' => $counts['mid1'],
                '中2' => $counts['mid2'],
                '夜' => $counts['night'],
            ],
        ];

        $current = $current->modify('+1 day');
    }

    return $results;
}

function weekday_name(DateTimeImmutable $date): string
{
    $map = [
        0 => '周日',
        1 => '周一',
        2 => '周二',
        3 => '周三',
        4 => '周四',
        5 => '周五',
        6 => '周六',
    ];

    return $map[(int) $date->format('w')] ?? '';
}
