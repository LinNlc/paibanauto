<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/_lib.php';

const AUTO_PHASE_INIT = 'init';
const AUTO_PHASE_SEED_REST = 'seed_rest';
const AUTO_PHASE_FIX_ON_DUTY = 'fix_on_duty';
const AUTO_PHASE_ASSIGN_MID1 = 'assign_mid1';
const AUTO_PHASE_IMPROVE = 'improve';
const AUTO_PHASE_FINALIZE = 'finalize';

const AUTO_STATUS_QUEUED = 'queued';
const AUTO_STATUS_RUNNING = 'running';
const AUTO_STATUS_DONE = 'done';
const AUTO_STATUS_FAILED = 'failed';

const AUTO_SHIFT_REST = '休息';
const AUTO_SHIFT_WHITE = '白';
const AUTO_SHIFT_MID1 = '中1';

const AUTO_REST_WORKDAYS = 'workdays';
const AUTO_REST_WEEKEND = 'weekend';

/**
 * Ensure auto module tables exist.
 */
function auto_ensure_schema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS auto_rules (
        team_id INTEGER PRIMARY KEY,
        rules_json TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS rest_cycle_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        year INTEGER NOT NULL,
        quarter INTEGER NOT NULL,
        emp_id INTEGER NOT NULL,
        rest_type TEXT NOT NULL,
        UNIQUE(team_id, year, quarter, emp_id),
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(emp_id) REFERENCES employees(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS shift_debt_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        year INTEGER NOT NULL,
        quarter INTEGER NOT NULL,
        emp_id INTEGER NOT NULL,
        shift TEXT NOT NULL,
        debt_days REAL NOT NULL DEFAULT 0,
        UNIQUE(team_id, year, quarter, emp_id, shift),
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(emp_id) REFERENCES employees(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS auto_jobs (
        job_id TEXT PRIMARY KEY,
        team_id INTEGER NOT NULL,
        params_json TEXT NOT NULL,
        status TEXT NOT NULL,
        phase TEXT NOT NULL DEFAULT \'\',
        progress REAL NOT NULL DEFAULT 0,
        score REAL,
        eta TEXT DEFAULT \'\',
        note TEXT DEFAULT \'\',
        events_json TEXT DEFAULT \'[]\',
        result_json TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auto_jobs_team ON auto_jobs(team_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rest_cycle_ledger_team ON rest_cycle_ledger(team_id, year, quarter)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shift_debt_ledger_team ON shift_debt_ledger(team_id, year, quarter)');

    $initialized = true;
}

function auto_generate_job_id(): string
{
    return bin2hex(random_bytes(16));
}

function auto_normalize_day(string $value): ?string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
    if ($dt === false) {
        return null;
    }
    return $dt->format('Y-m-d');
}

function auto_create_job(PDO $pdo, int $teamId, array $params): string
{
    auto_ensure_schema($pdo);
    $jobId = auto_generate_job_id();
    $stmt = $pdo->prepare('INSERT INTO auto_jobs(job_id, team_id, params_json, status, phase, progress, created_at, updated_at) VALUES (:job_id, :team_id, :params_json, :status, :phase, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
    $stmt->execute([
        ':job_id' => $jobId,
        ':team_id' => $teamId,
        ':params_json' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ':status' => AUTO_STATUS_QUEUED,
        ':phase' => AUTO_PHASE_INIT,
    ]);

    return $jobId;
}

function auto_fetch_job(PDO $pdo, string $jobId): ?array
{
    auto_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM auto_jobs WHERE job_id = :job_id');
    $stmt->execute([':job_id' => $jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    $row['progress'] = isset($row['progress']) ? (float) $row['progress'] : 0.0;
    $row['score'] = isset($row['score']) ? ($row['score'] !== null ? (float) $row['score'] : null) : null;
    $row['events'] = decode_json_field($row['events_json'] ?? '[]', []);
    $row['result'] = decode_json_field($row['result_json'] ?? null, null);

    return $row;
}

function auto_update_job(PDO $pdo, string $jobId, array $fields, ?array $event = null): void
{
    auto_ensure_schema($pdo);
    $sets = [];
    $params = [':job_id' => $jobId];

    foreach ($fields as $key => $value) {
        $sets[] = "$key = :$key";
        $params[":" . $key] = $value;
    }

    if ($event !== null) {
        $existing = auto_fetch_job($pdo, $jobId);
        if ($existing === null) {
            return;
        }
        $events = is_array($existing['events']) ? $existing['events'] : [];
        $event['ts'] = date('c');
        $events[] = $event;
        $fields['events_json'] = json_encode($events, JSON_UNESCAPED_UNICODE);
        $params[':events_json'] = $fields['events_json'];
        $sets[] = 'events_json = :events_json';
    }

    $sets[] = 'updated_at = CURRENT_TIMESTAMP';

    $sql = 'UPDATE auto_jobs SET ' . implode(', ', $sets) . ' WHERE job_id = :job_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function auto_update_job_progress(PDO $pdo, string $jobId, string $phase, float $progress, ?float $score, string $note, ?string $eta = null, array $extra = []): void
{
    $fields = [
        'phase' => $phase,
        'progress' => $progress,
        'note' => $note,
    ];
    if ($score !== null) {
        $fields['score'] = $score;
    }
    if ($eta !== null) {
        $fields['eta'] = $eta;
    }

    $event = array_merge([
        'phase' => $phase,
        'progress' => $progress,
        'score' => $score,
        'note' => $note,
        'eta' => $eta,
    ], $extra);

    auto_update_job($pdo, $jobId, $fields, $event);
}

function auto_mark_job_failed(PDO $pdo, string $jobId, string $message): void
{
    auto_update_job($pdo, $jobId, [
        'status' => AUTO_STATUS_FAILED,
        'note' => $message,
    ], [
        'phase' => AUTO_PHASE_FINALIZE,
        'progress' => 1.0,
        'score' => null,
        'note' => $message,
    ]);
}

function auto_mark_job_done(PDO $pdo, string $jobId, array $result, float $score, array $violations): void
{
    auto_update_job($pdo, $jobId, [
        'status' => AUTO_STATUS_DONE,
        'phase' => AUTO_PHASE_FINALIZE,
        'progress' => 1.0,
        'score' => $score,
        'note' => $violations !== [] ? '存在需人工关注的问题' : '完成',
        'result_json' => json_encode([
            'result' => $result,
            'violations' => $violations,
        ], JSON_UNESCAPED_UNICODE),
    ], [
        'phase' => AUTO_PHASE_FINALIZE,
        'progress' => 1.0,
        'score' => $score,
        'note' => $violations !== [] ? '完成但存在提醒' : '完成',
    ]);
}

function auto_spawn_job_runner(string $jobId): void
{
    $php = PHP_BINARY;
    $script = __DIR__ . '/bin/run_job.php';
    if (!is_file($script)) {
        return;
    }
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId);
    if (stripos(PHP_OS, 'WIN') === 0) {
        pclose(popen('start /b ' . $cmd, 'r'));
    } else {
        $cmd .= ' > /dev/null 2>&1 &';
        exec($cmd);
    }
}

function auto_decode_params(array $params): array
{
    $teamId = (int) ($params['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_err('缺少有效的团队', 422);
    }

    $start = auto_normalize_day((string) ($params['start_date'] ?? ''));
    $end = auto_normalize_day((string) ($params['end_date'] ?? ''));
    if ($start === null || $end === null) {
        json_err('日期格式应为 YYYY-MM-DD', 422);
    }
    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }

    $minOnDuty = (int) ($params['min_on_duty'] ?? 1);
    if ($minOnDuty < 1) {
        $minOnDuty = 1;
    }

    $thinkMinutes = (int) ($params['think_minutes'] ?? 5);
    if ($thinkMinutes < 1) {
        $thinkMinutes = 1;
    }
    if ($thinkMinutes > 120) {
        $thinkMinutes = 120;
    }

    $historyMin = (int) ($params['history_days_min'] ?? 30);
    $historyMax = (int) ($params['history_days_max'] ?? 90);
    if ($historyMin < 1) {
        $historyMin = 30;
    }
    if ($historyMax < $historyMin) {
        $historyMax = $historyMin;
    }
    if ($historyMax > 120) {
        $historyMax = 120;
    }

    $targetRatio = $params['target_ratio'] ?? [];
    $whiteRatio = (float) ($targetRatio['白'] ?? 0.7);
    $midRatio = (float) ($targetRatio['中1'] ?? 0.3);
    $totalRatio = $whiteRatio + $midRatio;
    if ($totalRatio <= 0) {
        $whiteRatio = 0.7;
        $midRatio = 0.3;
        $totalRatio = 1.0;
    }
    $whiteRatio = $whiteRatio / $totalRatio;
    $midRatio = $midRatio / $totalRatio;

    $holidayValues = [];
    if (isset($params['holidays']) && is_array($params['holidays'])) {
        foreach ($params['holidays'] as $value) {
            $day = auto_normalize_day((string) $value);
            if ($day !== null) {
                $holidayValues[$day] = true;
            }
        }
    }

    $employees = [];
    if (isset($params['employees']) && is_array($params['employees'])) {
        foreach ($params['employees'] as $emp) {
            if (is_array($emp)) {
                $id = (int) ($emp['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $employees[$id] = [
                    'id' => $id,
                    'label' => (string) ($emp['label'] ?? ($emp['name'] ?? '')),
                ];
            } elseif (is_numeric($emp)) {
                $id = (int) $emp;
                if ($id > 0) {
                    $employees[$id] = ['id' => $id, 'label' => ''];
                }
            }
        }
    }

    return [
        'team_id' => $teamId,
        'start' => $start,
        'end' => $end,
        'min_on_duty' => $minOnDuty,
        'think_minutes' => $thinkMinutes,
        'history_days_min' => $historyMin,
        'history_days_max' => $historyMax,
        'target_ratio' => [
            '白' => $whiteRatio,
            '中1' => $midRatio,
        ],
        'holidays' => array_keys($holidayValues),
        'employees' => array_values($employees),
        'weights' => auto_default_weights(),
    ];
}

function auto_default_weights(): array
{
    return [
        'ratio' => 0.35,
        'fairness' => 0.25,
        'recency' => 0.2,
        'concentration' => 0.2,
    ];
}

function auto_quarter_from_date(string $day): array
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $day);
    if ($dt === false) {
        return ['year' => (int) date('Y'), 'quarter' => (int) ceil((int) date('n') / 3)];
    }
    $month = (int) $dt->format('n');
    $quarter = (int) ceil($month / 3);
    return [
        'year' => (int) $dt->format('Y'),
        'quarter' => $quarter,
    ];
}

function auto_week_start(DateTimeImmutable $date): DateTimeImmutable
{
    $dow = (int) $date->format('N');
    if ($dow === 1) {
        return $date;
    }
    return $date->modify('-' . ($dow - 1) . ' days');
}

function auto_build_weeks(string $start, string $end, array $holidays): array
{
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    if ($startDt === false || $endDt === false) {
        return [];
    }
    if ($endDt < $startDt) {
        [$startDt, $endDt] = [$endDt, $startDt];
    }
    $holidaySet = [];
    foreach ($holidays as $h) {
        $holidaySet[$h] = true;
    }
    $weeks = [];
    $weekStart = auto_week_start($startDt);
    while ($weekStart <= $endDt) {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->modify('+' . $i . ' days');
            $dayStr = $day->format('Y-m-d');
            $days[] = [
                'date' => $dayStr,
                'weekday' => (int) $day->format('N'),
                'in_range' => ($day >= $startDt && $day <= $endDt),
                'holiday' => isset($holidaySet[$dayStr]),
            ];
        }
        $weeks[] = [
            'start' => $weekStart->format('Y-m-d'),
            'days' => $days,
        ];
        $weekStart = $weekStart->modify('+1 week');
    }
    return $weeks;
}

function auto_allowed_rest_pairs(string $restType): array
{
    if ($restType === AUTO_REST_WEEKEND) {
        return [
            [5, 6],
            [6, 7],
        ];
    }
    return [
        [1, 2],
        [2, 3],
        [3, 4],
    ];
}

function auto_day_key(string $date): string
{
    return $date;
}

function auto_load_team_employees(PDO $pdo, int $teamId, array $selected): array
{
    $map = [];
    $stmt = $pdo->prepare('SELECT id, name, display_name FROM employees WHERE team_id = :team ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':team' => $teamId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $map[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'display_name' => (string) $row['display_name'],
        ];
    }
    if ($selected === []) {
        return array_values($map);
    }
    $result = [];
    foreach ($selected as $sel) {
        $id = (int) ($sel['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (isset($map[$id])) {
            $result[] = $map[$id];
        }
    }
    return $result;
}

function auto_load_history(PDO $pdo, int $teamId, array $employeeIds, string $start, int $historyMin, int $historyMax): array
{
    if ($employeeIds === []) {
        return [
            'cells' => [],
            'days' => [],
        ];
    }
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    if ($startDt === false) {
        return [
            'cells' => [],
            'days' => [],
        ];
    }
    $span = $historyMax;
    if ($span < $historyMin) {
        $span = $historyMin;
    }
    $historyStart = $startDt->modify('-' . $span . ' days');
    $historyEnd = $startDt->modify('-1 day');
    $placeholders = [];
    $params = [
        ':team' => $teamId,
        ':start' => $historyStart->format('Y-m-d'),
        ':end' => $historyEnd->format('Y-m-d'),
    ];
    foreach ($employeeIds as $idx => $empId) {
        $ph = ':emp' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $empId;
    }
    $sql = 'SELECT emp_id, day, value FROM schedule_cells WHERE team_id = :team AND day BETWEEN :start AND :end AND emp_id IN (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cells = [];
    foreach ($rows as $row) {
        $emp = (int) $row['emp_id'];
        $day = (string) $row['day'];
        $value = (string) ($row['value'] ?? '');
        $cells[$emp][$day] = $value;
    }
    $days = [];
    for ($date = $historyStart; $date <= $historyEnd; $date = $date->modify('+1 day')) {
        $days[] = $date->format('Y-m-d');
    }
    return [
        'cells' => $cells,
        'days' => $days,
    ];
}

function auto_fetch_existing(PDO $pdo, int $teamId, array $employeeIds, string $start, string $end): array
{
    if ($employeeIds === []) {
        return [];
    }
    $placeholders = [];
    $params = [
        ':team' => $teamId,
        ':start' => $start,
        ':end' => $end,
    ];
    foreach ($employeeIds as $idx => $empId) {
        $ph = ':emp' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $empId;
    }
    $sql = 'SELECT emp_id, day, value FROM schedule_cells WHERE team_id = :team AND day BETWEEN :start AND :end AND emp_id IN (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cells = [];
    foreach ($rows as $row) {
        $emp = (int) $row['emp_id'];
        $day = (string) $row['day'];
        $value = (string) ($row['value'] ?? '');
        $cells[$emp][$day] = $value;
    }
    return $cells;
}

function auto_quarter_requirements(PDO $pdo, int $teamId, array $employeeIds, string $start): array
{
    $info = auto_quarter_from_date($start);
    $year = $info['year'];
    $quarter = $info['quarter'];

    $result = [];
    if ($employeeIds === []) {
        return $result;
    }

    $placeholders = [];
    $params = [
        ':team' => $teamId,
        ':year' => $year,
    ];
    foreach ($employeeIds as $idx => $empId) {
        $ph = ':emp' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $empId;
    }
    $sql = 'SELECT emp_id, rest_type, quarter FROM rest_cycle_ledger WHERE team_id = :team AND year = :year AND emp_id IN (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $history = [];
    foreach ($rows as $row) {
        $emp = (int) $row['emp_id'];
        $type = (string) $row['rest_type'];
        $q = (int) $row['quarter'];
        $history[$emp][$q] = $type;
    }

    foreach ($employeeIds as $empId) {
        $counts = [AUTO_REST_WORKDAYS => 0, AUTO_REST_WEEKEND => 0];
        if (isset($history[$empId])) {
            foreach ($history[$empId] as $q => $type) {
                if ($q < $quarter && isset($counts[$type])) {
                    $counts[$type]++;
                }
            }
        }
        $workNeed = max(0, 2 - $counts[AUTO_REST_WORKDAYS]);
        $weekNeed = max(0, 2 - $counts[AUTO_REST_WEEKEND]);
        if ($workNeed === 0 && $weekNeed === 0) {
            $preferred = $counts[AUTO_REST_WORKDAYS] <= $counts[AUTO_REST_WEEKEND] ? AUTO_REST_WORKDAYS : AUTO_REST_WEEKEND;
        } elseif ($workNeed >= $weekNeed) {
            $preferred = $workNeed > 0 ? AUTO_REST_WORKDAYS : AUTO_REST_WEEKEND;
        } else {
            $preferred = AUTO_REST_WEEKEND;
        }
        $result[$empId] = [
            'required' => $preferred,
            'history' => $history[$empId] ?? [],
        ];
    }
    return $result;
}

function auto_save_rest_cycle(PDO $pdo, int $teamId, string $start, array $restAssignments): void
{
    $info = auto_quarter_from_date($start);
    $year = $info['year'];
    $quarter = $info['quarter'];
    $stmt = $pdo->prepare('INSERT INTO rest_cycle_ledger(team_id, year, quarter, emp_id, rest_type) VALUES (:team, :year, :quarter, :emp, :type)
        ON CONFLICT(team_id, year, quarter, emp_id) DO UPDATE SET rest_type = excluded.rest_type');
    foreach ($restAssignments as $empId => $type) {
        $stmt->execute([
            ':team' => $teamId,
            ':year' => $year,
            ':quarter' => $quarter,
            ':emp' => $empId,
            ':type' => $type,
        ]);
    }
}

function auto_save_shift_debt(PDO $pdo, int $teamId, string $start, array $debt): void
{
    if ($debt === []) {
        return;
    }
    $info = auto_quarter_from_date($start);
    $year = $info['year'];
    $quarter = $info['quarter'];
    $stmt = $pdo->prepare('INSERT INTO shift_debt_ledger(team_id, year, quarter, emp_id, shift, debt_days)
        VALUES (:team, :year, :quarter, :emp, :shift, :days)
        ON CONFLICT(team_id, year, quarter, emp_id, shift) DO UPDATE SET debt_days = excluded.debt_days');
    foreach ($debt as $empId => $shifts) {
        foreach ($shifts as $shift => $days) {
            $stmt->execute([
                ':team' => $teamId,
                ':year' => $year,
                ':quarter' => $quarter,
                ':emp' => $empId,
                ':shift' => $shift,
                ':days' => $days,
            ]);
        }
    }
}

function auto_apply_diff(PDO $pdo, int $teamId, int $userId, array $ops): array
{
    $pdo->beginTransaction();
    try {
        $versions = [];
        foreach ($ops as $op) {
            $empId = (int) ($op['emp_id'] ?? 0);
            $day = (string) ($op['day'] ?? '');
            if ($empId <= 0 || $day === '') {
                continue;
            }
            $newValue = (string) ($op['to'] ?? '');
            $current = (string) ($op['from'] ?? '');
            $stmt = $pdo->prepare('SELECT id, value, version FROM schedule_cells WHERE team_id = :team AND emp_id = :emp AND day = :day ORDER BY id ASC LIMIT 1');
            $stmt->execute([
                ':team' => $teamId,
                ':emp' => $empId,
                ':day' => $day,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $insert = $pdo->prepare('INSERT INTO schedule_cells(team_id, day, emp_id, value, version, updated_at, updated_by) VALUES (:team, :day, :emp, :value, 1, CURRENT_TIMESTAMP, :user)');
                $insert->execute([
                    ':team' => $teamId,
                    ':day' => $day,
                    ':emp' => $empId,
                    ':value' => $newValue,
                    ':user' => $userId > 0 ? $userId : null,
                ]);
                $versions[] = ['day' => $day, 'emp_id' => $empId, 'version' => 1];
                continue;
            }
            if ($current !== '' && $current !== (string) ($row['value'] ?? '')) {
                // concurrent update, skip but record conflict
                continue;
            }
            $update = $pdo->prepare('UPDATE schedule_cells SET value = :value, version = :version, updated_at = CURRENT_TIMESTAMP, updated_by = :user WHERE id = :id');
            $update->execute([
                ':value' => $newValue,
                ':version' => (int) $row['version'] + 1,
                ':user' => $userId > 0 ? $userId : null,
                ':id' => (int) $row['id'],
            ]);
            $versions[] = ['day' => $day, 'emp_id' => $empId, 'version' => (int) $row['version'] + 1];
        }
        $pdo->commit();
        return $versions;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function auto_format_eta(int $seconds): string
{
    if ($seconds <= 0) {
        return '';
    }
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $minutes = intdiv($seconds, 60);
    $secs = $seconds % 60;
    if ($minutes < 60) {
        return $minutes . 'm' . ($secs > 0 ? $secs . 's' : '');
    }
    $hours = intdiv($minutes, 60);
    $minutes %= 60;
    return $hours . 'h' . ($minutes > 0 ? $minutes . 'm' : '');
}

