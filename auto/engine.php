<?php

declare(strict_types=1);

require_once __DIR__ . '/_lib.php';

function auto_execute_job(PDO $pdo, string $jobId): void
{
    $job = auto_fetch_job($pdo, $jobId);
    if ($job === null) {
        return;
    }

    $params = json_decode($job['params_json'] ?? '[]', true);
    if (!is_array($params)) {
        auto_mark_job_failed($pdo, $jobId, '任务参数损坏');
        return;
    }

    $teamId = (int) ($params['team_id'] ?? 0);
    if ($teamId <= 0) {
        auto_mark_job_failed($pdo, $jobId, '缺少有效的团队');
        return;
    }

    auto_update_job($pdo, $jobId, [
        'status' => AUTO_STATUS_RUNNING,
        'note' => '准备数据',
    ]);

    try {
        $result = auto_run_generation($pdo, $jobId, $params);
        auto_mark_job_done($pdo, $jobId, $result['result'], $result['score'], $result['violations']);
    } catch (Throwable $e) {
        auto_mark_job_failed($pdo, $jobId, $e->getMessage());
    }
}

function auto_run_generation(PDO $pdo, string $jobId, array $params): array
{
    auto_update_job_progress($pdo, $jobId, AUTO_PHASE_INIT, 0.05, null, '载入团队与员工');

    $teamId = (int) $params['team_id'];
    $employees = auto_load_team_employees($pdo, $teamId, $params['employees'] ?? []);
    if ($employees === []) {
        throw new RuntimeException('团队没有可排班的员工');
    }
    $employeeIds = array_map(static fn($emp) => (int) $emp['id'], $employees);

    $calendar = auto_prepare_calendar($params['start'], $params['end'], $params['holidays']);
    $history = auto_load_history($pdo, $teamId, $employeeIds, $params['start'], (int) $params['history_days_min'], (int) $params['history_days_max']);
    $existing = auto_fetch_existing($pdo, $teamId, $employeeIds, $params['start'], $params['end']);
    $requirements = auto_quarter_requirements($pdo, $teamId, $employeeIds, $params['start']);

    $snapshot = auto_build_employee_snapshot($employees, $history, $calendar, $params, $requirements);

    auto_update_job_progress($pdo, $jobId, AUTO_PHASE_SEED_REST, 0.15, null, '铺设休息周期');
    $restPlan = auto_assign_rest_cycles($calendar, $snapshot, $params['min_on_duty'], $requirements, $params['holidays']);

    $violations = $restPlan['violations'];

    auto_update_job_progress($pdo, $jobId, AUTO_PHASE_FIX_ON_DUTY, 0.35, null, '检查在岗约束');
    $additionalViolations = auto_validate_min_on_duty($calendar, $restPlan['schedule'], (int) $params['min_on_duty']);
    $violations = array_merge($violations, $additionalViolations);

    auto_update_job_progress($pdo, $jobId, AUTO_PHASE_ASSIGN_MID1, 0.5, null, '按比例分配中班');

    $searchResult = auto_allocate_mid1_with_search(
        $calendar,
        $snapshot,
        $restPlan,
        $params,
        $existing,
        $jobId,
        $pdo
    );

    $violations = array_merge($violations, $searchResult['violations']);

    $resultPayload = [
        'grid' => $searchResult['grid'],
        'diff_ops' => $searchResult['diff_ops'],
        'metrics' => $searchResult['metrics'],
        'rest_cycle' => $restPlan['rest_types'],
        'shift_debt' => $snapshot['shift_debt'],
        'auto_applied' => false,
        'apply_versions' => [],
    ];

    $score = $searchResult['metrics']['score'];

    if (!empty($params['apply'])) {
        $applyResult = auto_apply_if_permitted($pdo, $teamId, (int) ($params['created_by'] ?? 0), $params['start'], $searchResult['diff_ops'], $restPlan['rest_types'], $snapshot['shift_debt']);
        if ($applyResult['applied']) {
            $resultPayload['auto_applied'] = true;
            $resultPayload['apply_versions'] = $applyResult['versions'];
        } else {
            $violations[] = $applyResult['violation'];
        }
    }

    auto_update_job_progress($pdo, $jobId, AUTO_PHASE_FINALIZE, 0.95, $score, '整理结果');

    $resultPayload['violations'] = $violations;

    return [
        'result' => $resultPayload,
        'score' => $score,
        'violations' => $violations,
    ];
}

function auto_allocate_mid1_with_search(array $calendar, array $snapshot, array $restPlan, array $params, array $existing, string $jobId, PDO $pdo): array
{
    $workingMap = auto_prepare_working_map($calendar, $restPlan['schedule']);
    $ratioMid = (float) ($params['target_ratio']['中1'] ?? 0.3);
    $targets = auto_compute_mid1_targets($workingMap, $ratioMid);
    $weights = $params['weights'] ?? auto_default_weights();

    $initialPlan = auto_assign_mid1_plan($calendar, $snapshot, $workingMap, $targets, 12345);
    $initialMetrics = auto_calculate_metrics($calendar, $initialPlan, $weights, $ratioMid);

    $bestPlan = $initialPlan;
    $bestMetrics = $initialMetrics;

    auto_update_job_progress($pdo, $jobId, AUTO_PHASE_ASSIGN_MID1, 0.65, $bestMetrics['score'], '初始方案完成');

    $thinkSeconds = max(1, (int) ($params['think_minutes'] ?? 5)) * 60;
    $deadline = microtime(true) + $thinkSeconds;
    $lastProgressUpdate = microtime(true);
    $iterations = 0;

    while (microtime(true) < $deadline) {
        $seed = random_int(1, PHP_INT_MAX);
        $candidatePlan = auto_assign_mid1_plan($calendar, $snapshot, $workingMap, $targets, $seed);
        $candidateMetrics = auto_calculate_metrics($calendar, $candidatePlan, $weights, $ratioMid);
        if ($candidateMetrics['score'] > $bestMetrics['score']) {
            $bestPlan = $candidatePlan;
            $bestMetrics = $candidateMetrics;
        }
        $iterations++;
        $now = microtime(true);
        if ($now - $lastProgressUpdate >= 0.2) {
            $elapsed = $thinkSeconds - ($deadline - $now);
            $progress = 0.65 + min(0.2, ($elapsed / $thinkSeconds) * 0.2);
            auto_update_job_progress($pdo, $jobId, AUTO_PHASE_IMPROVE, min(0.9, $progress), $bestMetrics['score'], '优化中', auto_format_eta((int) max(0, $deadline - $now)), [
                'iterations' => $iterations,
            ]);
            $lastProgressUpdate = $now;
        }
    }

    auto_update_job_progress($pdo, $jobId, AUTO_PHASE_IMPROVE, 0.9, $bestMetrics['score'], '优化完成');

    $grid = auto_build_grid($calendar, $snapshot, $restPlan, $bestPlan, $targets, $params['target_ratio']);
    $diffOps = auto_build_diff_ops($calendar, $snapshot, $restPlan, $bestPlan, $existing);

    return [
        'grid' => $grid,
        'diff_ops' => $diffOps,
        'metrics' => $bestMetrics,
        'violations' => $bestMetrics['warnings'],
    ];
}

function auto_build_grid(array $calendar, array $snapshot, array $restPlan, array $plan, array $targets, array $targetRatio): array
{
    $days = [];
    foreach ($calendar['days'] as $dayInfo) {
        $days[] = [
            'date' => $dayInfo['date'],
            'weekday' => $dayInfo['weekday'],
            'holiday' => (bool) ($dayInfo['holiday'] ?? false),
        ];
    }

    $rows = [];
    foreach ($snapshot['employees'] as $empId => $empInfo) {
        $cells = [];
        $restCount = 0;
        $mid1Count = $plan['mid1_counts'][$empId] ?? 0;
        $whiteCount = $plan['white_counts'][$empId] ?? 0;
        foreach ($calendar['days'] as $dayInfo) {
            $date = $dayInfo['date'];
            $value = AUTO_SHIFT_WHITE;
            if (($restPlan['schedule'][$empId][$date] ?? null) === AUTO_SHIFT_REST) {
                $value = AUTO_SHIFT_REST;
                $restCount++;
            } elseif (($plan['assignments'][$date][$empId] ?? null) === AUTO_SHIFT_MID1) {
                $value = AUTO_SHIFT_MID1;
            }
            $cells[] = [
                'date' => $date,
                'value' => $value,
                'weekday' => $dayInfo['weekday'],
                'holiday' => (bool) ($dayInfo['holiday'] ?? false),
            ];
        }
        $label = trim((string) ($empInfo['display_name'] ?? ''));
        if ($label === '') {
            $label = (string) ($empInfo['name'] ?? '');
        }
        $rows[] = [
            'emp_id' => $empId,
            'name' => (string) ($empInfo['name'] ?? ''),
            'display_name' => (string) ($empInfo['display_name'] ?? ''),
            'label' => $label,
            'required_rest' => $snapshot['states'][$empId]['required_rest'] ?? AUTO_REST_WORKDAYS,
            'cells' => $cells,
            'counts' => [
                'mid1' => $mid1Count,
                'white' => $whiteCount,
                'rest' => $restCount,
            ],
        ];
    }

    $dayTargets = [];
    foreach ($targets as $date => $info) {
        $dayTargets[$date] = $info['target'];
    }

    $mid1Total = array_sum($plan['mid1_counts']);
    $whiteTotal = array_sum($plan['white_counts']);
    $workTotal = $mid1Total + $whiteTotal;
    $actualRatio = $workTotal > 0 ? $mid1Total / $workTotal : 0.0;

    return [
        'days' => $days,
        'rows' => $rows,
        'summary' => [
            'target_ratio' => $targetRatio,
            'actual_ratio' => $actualRatio,
            'mid1_total' => $mid1Total,
            'work_total' => $workTotal,
            'day_targets' => $dayTargets,
        ],
    ];
}

function auto_build_diff_ops(array $calendar, array $snapshot, array $restPlan, array $plan, array $existing): array
{
    $ops = [];
    foreach ($snapshot['employees'] as $empId => $empInfo) {
        foreach ($calendar['days'] as $dayInfo) {
            $date = $dayInfo['date'];
            $newValue = AUTO_SHIFT_WHITE;
            if (($restPlan['schedule'][$empId][$date] ?? null) === AUTO_SHIFT_REST) {
                $newValue = AUTO_SHIFT_REST;
            } elseif (($plan['assignments'][$date][$empId] ?? null) === AUTO_SHIFT_MID1) {
                $newValue = AUTO_SHIFT_MID1;
            }
            $oldValue = (string) ($existing[$empId][$date] ?? '');
            if ($oldValue === $newValue) {
                continue;
            }
            $ops[] = [
                'emp_id' => $empId,
                'day' => $date,
                'from' => $oldValue,
                'to' => $newValue,
            ];
        }
    }
    return $ops;
}

function auto_apply_if_permitted(PDO $pdo, int $teamId, int $userId, string $start, array $diffOps, array $restTypes, array $shiftDebt): array
{
    if ($diffOps === []) {
        auto_save_rest_cycle($pdo, $teamId, $start, $restTypes);
        auto_save_shift_debt($pdo, $teamId, $start, $shiftDebt);
        return ['applied' => true, 'versions' => []];
    }
    if ($userId <= 0) {
        return [
            'applied' => false,
            'versions' => [],
            'violation' => [
                'code' => 'apply_forbidden',
                'message' => '缺少有效的操作人，无法自动应用',
            ],
        ];
    }
    $permissions = load_user_permissions($pdo, $userId);
    if ($permissions === null || !permissions_can_edit_team($permissions, $teamId)) {
        return [
            'applied' => false,
            'versions' => [],
            'violation' => [
                'code' => 'apply_forbidden',
                'message' => '当前账号没有自动写入该团队的权限',
            ],
        ];
    }

    $versions = auto_apply_diff($pdo, $teamId, $userId, $diffOps);
    auto_save_rest_cycle($pdo, $teamId, $start, $restTypes);
    auto_save_shift_debt($pdo, $teamId, $start, $shiftDebt);

    return [
        'applied' => true,
        'versions' => $versions,
    ];
}




function auto_prepare_calendar(string $start, string $end, array $holidays): array
{
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    if ($startDt === false || $endDt === false) {
        throw new RuntimeException('日期格式错误');
    }
    if ($endDt < $startDt) {
        [$startDt, $endDt] = [$endDt, $startDt];
    }
    $holidaySet = [];
    foreach ($holidays as $h) {
        $holidaySet[$h] = true;
    }
    $days = [];
    $dayIndex = [];
    $cursor = $startDt;
    $index = 0;
    while ($cursor <= $endDt) {
        $date = $cursor->format('Y-m-d');
        $days[] = [
            'date' => $date,
            'weekday' => (int) $cursor->format('N'),
            'holiday' => isset($holidaySet[$date]),
        ];
        $dayIndex[$date] = $index;
        $cursor = $cursor->modify('+1 day');
        $index++;
    }
    $weeks = auto_build_weeks($start, $end, $holidays);
    return [
        'days' => $days,
        'index' => $dayIndex,
        'weeks' => $weeks,
        'holiday_set' => $holidaySet,
        'start' => $start,
        'end' => $end,
    ];
}

function auto_build_employee_snapshot(array $employees, array $history, array $calendar, array $params, array $requirements): array
{
    $historyCells = $history['cells'];
    $historyDays = $history['days'];
    $holidaySet = $calendar['holiday_set'];
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $params['start']);
    if ($startDt === false) {
        throw new RuntimeException('开始日期无效');
    }
    $monthStart = $startDt->modify('first day of this month');
    $quarterInfo = auto_quarter_from_date($params['start']);
    $quarterMonthStart = (($quarterInfo['quarter'] - 1) * 3) + 1;
    $quarterStart = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $quarterInfo['year'], $quarterMonthStart));
    $prevQuarter = $quarterInfo['quarter'] - 1;
    $prevQuarterYear = $quarterInfo['year'];
    if ($prevQuarter < 1) {
        $prevQuarter = 4;
        $prevQuarterYear--;
    }
    $prevQuarterMonthStart = (($prevQuarter - 1) * 3) + 1;
    $prevQuarterStart = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $prevQuarterYear, $prevQuarterMonthStart));
    $prevQuarterEnd = $prevQuarterStart->modify('+3 months')->modify('-1 day');

    $snapshot = [
        'states' => [],
        'shift_debt' => [],
        'employees' => [],
    ];

    foreach ($employees as $emp) {
        $empId = (int) $emp['id'];
        $cells = $historyCells[$empId] ?? [];
        $lastGap = count($historyDays) + 30;
        $gapCounter = 0;
        for ($i = count($historyDays) - 1; $i >= 0; $i--) {
            $day = $historyDays[$i];
            $value = $cells[$day] ?? '';
            if ($value === AUTO_SHIFT_MID1) {
                $lastGap = $gapCounter + 1;
                break;
            }
            $gapCounter++;
        }
        $lastType = 'work';
        if ($historyDays !== []) {
            $lastDay = end($historyDays);
            $lastValue = $cells[$lastDay] ?? '';
            if ($lastValue === AUTO_SHIFT_REST) {
                $lastType = 'rest';
            }
        }
        $restStreak = 0;
        $workStreak = 0;
        if ($lastType === 'rest') {
            for ($i = count($historyDays) - 1; $i >= 0; $i--) {
                $day = $historyDays[$i];
                $value = $cells[$day] ?? '';
                if ($value === AUTO_SHIFT_REST) {
                    $restStreak++;
                } else {
                    break;
                }
            }
        } else {
            for ($i = count($historyDays) - 1; $i >= 0; $i--) {
                $day = $historyDays[$i];
                $value = $cells[$day] ?? '';
                if ($value === AUTO_SHIFT_REST) {
                    break;
                }
                $workStreak++;
            }
        }
        $monthMid1 = 0;
        $quarterMid1 = 0;
        $shiftDebt = ['中2' => 0.0, '夜' => 0.0];
        foreach ($historyDays as $day) {
            $value = $cells[$day] ?? '';
            if ($value === AUTO_SHIFT_MID1) {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d', $day);
                if ($dt !== false) {
                    if ($dt >= $monthStart && $dt < $startDt) {
                        $monthMid1++;
                    }
                    if ($dt >= $quarterStart && $dt < $startDt) {
                        $quarterMid1++;
                    }
                }
            }
            if ($value === '中2' || $value === '夜') {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d', $day);
                if ($dt !== false && $dt >= $prevQuarterStart && $dt <= $prevQuarterEnd) {
                    $shiftDebt[$value] += 1.0;
                }
            }
        }
        $requiredType = $requirements[$empId]['required'] ?? AUTO_REST_WORKDAYS;
        $snapshot['states'][$empId] = [
            'work_streak' => $workStreak,
            'rest_streak' => $restStreak,
            'mid1_gap' => $lastGap,
            'month_mid1' => $monthMid1,
            'quarter_mid1' => $quarterMid1,
            'total_mid1' => $monthMid1 + $quarterMid1,
            'required_rest' => $requiredType,
            'history_rest' => $requirements[$empId]['history'] ?? [],
        ];
        $snapshot['shift_debt'][$empId] = $shiftDebt;
        $snapshot['employees'][$empId] = $emp;
    }

    return $snapshot;
}

function auto_assign_rest_cycles(array $calendar, array $snapshot, int $minOnDuty, array $requirements, array $holidays): array
{
    $employees = array_keys($snapshot['states']);
    $weeks = $calendar['weeks'];
    $holidaySet = $calendar['holiday_set'];
    $totalEmp = count($employees);
    $capacityByDay = [];
    foreach ($calendar['days'] as $day) {
        $capacityByDay[$day['date']] = max(0, $totalEmp - $minOnDuty);
    }
    $restSchedule = [];
    $restTypes = [];
    $violations = [];

    foreach ($employees as $empId) {
        $restSchedule[$empId] = [];
    }

    foreach ($employees as $empId) {
        $state = $snapshot['states'][$empId];
        $restType = $state['required_rest'];
        $options = auto_allowed_rest_pairs($restType);
        $restTypes[$empId] = $restType;
        $weekIndex = 0;
        foreach ($weeks as $week) {
            $chosen = null;
            $chosenAssignments = [];
            $chosenState = $state;
            $bestPenalty = PHP_INT_MAX;
            $validFound = false;
            foreach ($options as $option) {
                $simulation = auto_simulate_week_option($state, $week['days'], $option);
                if ($simulation === null) {
                    continue;
                }
                $assignments = $simulation['assignments'];
                $overload = false;
                $penalty = 0;
                foreach ($week['days'] as $dayInfo) {
                    $date = $dayInfo['date'];
                    if (!$dayInfo['in_range']) {
                        continue;
                    }
                    if (($assignments[$date] ?? null) === AUTO_SHIFT_REST) {
                        $validFound = true;
                        if (($capacityByDay[$date] ?? 0) <= 0) {
                            $overload = true;
                            break;
                        }
                        $penalty += $capacityByDay[$date];
                    }
                }
                if ($overload) {
                    continue;
                }
                if ($penalty < $bestPenalty) {
                    $bestPenalty = $penalty;
                    $chosen = $option;
                    $chosenAssignments = $assignments;
                    $chosenState = $simulation['state'];
                }
            }
            if ($chosen === null) {
                if ($validFound) {
                    $violations[] = [
                        'code' => 'min_on_duty_conflict',
                        'message' => sprintf('员工 %d 的第 %d 周无法满足最少在岗人数', $empId, $weekIndex + 1),
                    ];
                } else {
                    $violations[] = [
                        'code' => 'rest_cycle_conflict',
                        'message' => sprintf('员工 %d 在第 %d 周无法满足休息硬约束', $empId, $weekIndex + 1),
                    ];
                }
                $simulation = auto_simulate_week_option($state, $week['days'], $options[0] ?? [1, 2]);
                if ($simulation !== null) {
                    $chosenAssignments = $simulation['assignments'];
                    $chosenState = $simulation['state'];
                }
            } else {
                foreach ($week['days'] as $dayInfo) {
                    $date = $dayInfo['date'];
                    if (!$dayInfo['in_range']) {
                        continue;
                    }
                    if (($chosenAssignments[$date] ?? null) === AUTO_SHIFT_REST) {
                        $capacityByDay[$date] = max(0, ($capacityByDay[$date] ?? 0) - 1);
                    }
                }
            }
            foreach ($week['days'] as $dayInfo) {
                $date = $dayInfo['date'];
                if (!$dayInfo['in_range']) {
                    continue;
                }
                if (($chosenAssignments[$date] ?? null) === AUTO_SHIFT_REST) {
                    $restSchedule[$empId][$date] = AUTO_SHIFT_REST;
                }
            }
            $state = $chosenState;
            $weekIndex++;
        }
    }

    return [
        'schedule' => $restSchedule,
        'rest_types' => $restTypes,
        'violations' => $violations,
    ];
}

function auto_validate_min_on_duty(array $calendar, array $restSchedule, int $minOnDuty): array
{
    $violations = [];
    $totalEmp = count($restSchedule);
    if ($totalEmp === 0) {
        return $violations;
    }
    foreach ($calendar['days'] as $dayInfo) {
        $date = $dayInfo['date'];
        $restCount = 0;
        foreach ($restSchedule as $schedule) {
            if (($schedule[$date] ?? null) === AUTO_SHIFT_REST) {
                $restCount++;
            }
        }
        $onDuty = $totalEmp - $restCount;
        if ($onDuty < $minOnDuty) {
            $violations[] = [
                'code' => 'min_on_duty_shortage',
                'message' => sprintf('%s 在岗人数 %d 低于下限 %d', $date, $onDuty, $minOnDuty),
                'day' => $date,
                'on_duty' => $onDuty,
                'required' => $minOnDuty,
            ];
        }
    }
    return $violations;
}

function auto_prepare_working_map(array $calendar, array $restSchedule): array
{
    $map = [];
    foreach ($calendar['days'] as $dayInfo) {
        $date = $dayInfo['date'];
        $map[$date] = [];
        foreach ($restSchedule as $empId => $schedule) {
            if (($schedule[$date] ?? null) !== AUTO_SHIFT_REST) {
                $map[$date][] = $empId;
            }
        }
    }
    return $map;
}

function auto_compute_mid1_targets(array $workingMap, float $ratio): array
{
    $targets = [];
    $fractions = [];
    $sumBase = 0;
    $totalSlots = 0;
    foreach ($workingMap as $date => $employees) {
        $slots = count($employees);
        $totalSlots += $slots;
        $ideal = $slots * $ratio;
        $base = (int) floor($ideal);
        if ($base > $slots) {
            $base = $slots;
        }
        $fraction = $ideal - $base;
        $targets[$date] = [
            'target' => $base,
            'slots' => $slots,
            'fraction' => $fraction,
        ];
        $fractions[$date] = $fraction;
        $sumBase += $base;
    }
    if ($totalSlots === 0) {
        return $targets;
    }
    $targetTotal = (int) round($totalSlots * $ratio);
    $remaining = $targetTotal - $sumBase;
    if ($remaining > 0) {
        uasort($fractions, static function ($a, $b) {
            if ($a === $b) {
                return 0;
            }
            return $a > $b ? -1 : 1;
        });
        while ($remaining > 0) {
            $assigned = false;
            foreach ($fractions as $date => $fraction) {
                if ($targets[$date]['target'] < $targets[$date]['slots']) {
                    $targets[$date]['target']++;
                    $remaining--;
                    $assigned = true;
                    if ($remaining === 0) {
                        break;
                    }
                }
            }
            if (!$assigned) {
                break;
            }
        }
    } elseif ($remaining < 0) {
        asort($fractions);
        while ($remaining < 0) {
            $reduced = false;
            foreach ($fractions as $date => $fraction) {
                if ($targets[$date]['target'] > 0) {
                    $targets[$date]['target']--;
                    $remaining++;
                    $reduced = true;
                    if ($remaining === 0) {
                        break;
                    }
                }
            }
            if (!$reduced) {
                break;
            }
        }
    }

    return $targets;
}

function auto_assign_mid1_plan(array $calendar, array $snapshot, array $workingMap, array $targets, int $seed): array
{
    $states = [];
    foreach ($snapshot['states'] as $empId => $state) {
        $states[$empId] = [
            'gap' => max(0, (int) ($state['mid1_gap'] ?? 0)),
            'month_mid1' => (int) ($state['month_mid1'] ?? 0),
            'quarter_mid1' => (int) ($state['quarter_mid1'] ?? 0),
            'total_mid1' => (int) ($state['quarter_mid1'] ?? 0),
        ];
    }

    $assignments = [];
    $mid1Counts = [];
    $whiteCounts = [];
    $dayMid1Counts = [];
    $gapSamples = [];
    mt_srand($seed);

    foreach ($calendar['days'] as $dayInfo) {
        $date = $dayInfo['date'];
        foreach ($states as $empId => &$state) {
            $state['gap'] = ($state['gap'] ?? 0) + 1;
        }
        unset($state);

        $workers = $workingMap[$date] ?? [];
        $target = $targets[$date]['target'] ?? 0;
        if ($workers === []) {
            $assignments[$date] = [];
            $dayMid1Counts[$date] = 0;
            continue;
        }

        $priorities = [];
        foreach ($workers as $empId) {
            $st = $states[$empId] ?? ['gap' => 0, 'month_mid1' => 0, 'quarter_mid1' => 0, 'total_mid1' => 0];
            $priorities[$empId] = [
                'gap' => $st['gap'] ?? 0,
                'month' => $st['month_mid1'] ?? 0,
                'quarter' => $st['quarter_mid1'] ?? 0,
                'total' => $st['total_mid1'] ?? 0,
                'rand' => mt_rand() / max(1, mt_getrandmax()),
            ];
        }

        usort($workers, static function ($a, $b) use ($priorities) {
            return auto_compare_priority($priorities[$a], $priorities[$b]);
        });

        $assignedMid1 = array_slice($workers, 0, min($target, count($workers)));
        $assignedMid1 = array_values($assignedMid1);
        $assignedSet = array_flip($assignedMid1);
        $dayAssignments = [];
        foreach ($workers as $empId) {
            if (isset($assignedSet[$empId])) {
                $gapSamples[] = $states[$empId]['gap'] ?? 0;
                $states[$empId]['gap'] = 0;
                $states[$empId]['month_mid1'] = ($states[$empId]['month_mid1'] ?? 0) + 1;
                $states[$empId]['quarter_mid1'] = ($states[$empId]['quarter_mid1'] ?? 0) + 1;
                $states[$empId]['total_mid1'] = ($states[$empId]['total_mid1'] ?? 0) + 1;
                $mid1Counts[$empId] = ($mid1Counts[$empId] ?? 0) + 1;
                $dayAssignments[$empId] = AUTO_SHIFT_MID1;
            } else {
                $whiteCounts[$empId] = ($whiteCounts[$empId] ?? 0) + 1;
                $dayAssignments[$empId] = AUTO_SHIFT_WHITE;
            }
        }
        $assignments[$date] = $dayAssignments;
        $dayMid1Counts[$date] = count($assignedMid1);
    }

    return [
        'assignments' => $assignments,
        'states' => $states,
        'mid1_counts' => $mid1Counts,
        'white_counts' => $whiteCounts,
        'day_mid1_counts' => $dayMid1Counts,
        'gap_samples' => $gapSamples,
    ];
}

function auto_calculate_metrics(array $calendar, array $plan, array $weights, float $targetRatio): array
{
    $mid1Total = array_sum($plan['mid1_counts']);
    $whiteTotal = array_sum($plan['white_counts']);
    $workTotal = $mid1Total + $whiteTotal;
    $actualRatio = $workTotal > 0 ? $mid1Total / $workTotal : 0.0;
    $ratioScore = 1.0 - min(1.0, abs($actualRatio - $targetRatio));

    $quarterCounts = [];
    foreach ($plan['states'] as $empId => $state) {
        $quarterCounts[] = (int) ($state['quarter_mid1'] ?? 0);
    }
    $fairnessScore = 1.0;
    if ($quarterCounts !== []) {
        $mean = array_sum($quarterCounts) / count($quarterCounts);
        $variance = 0.0;
        foreach ($quarterCounts as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= max(1, count($quarterCounts));
        $std = sqrt($variance);
        $fairnessScore = 1.0 - min(1.0, $std / ($mean + 1.0));
    }

    $gapSamples = $plan['gap_samples'];
    $avgGap = $gapSamples !== [] ? array_sum($gapSamples) / count($gapSamples) : 0.0;
    $recencyScore = $avgGap > 0 ? min(1.0, $avgGap / 10.0) : 0.5;

    $dayCounts = array_values($plan['day_mid1_counts']);
    $concentrationScore = 1.0;
    if ($dayCounts !== []) {
        $mean = array_sum($dayCounts) / count($dayCounts);
        $variance = 0.0;
        foreach ($dayCounts as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= max(1, count($dayCounts));
        $std = sqrt($variance);
        $concentrationScore = 1.0 - min(1.0, $std / ($mean + 1.0));
    }

    $score = ($weights['ratio'] ?? 0.35) * $ratioScore
        + ($weights['fairness'] ?? 0.25) * $fairnessScore
        + ($weights['recency'] ?? 0.2) * $recencyScore
        + ($weights['concentration'] ?? 0.2) * $concentrationScore;

    $warnings = [];
    if (abs($actualRatio - $targetRatio) > 0.1) {
        $warnings[] = [
            'code' => 'ratio_drift',
            'message' => sprintf('整体中班比例 %.1f%% 偏离目标 %.1f%%', $actualRatio * 100, $targetRatio * 100),
        ];
    }

    return [
        'score' => $score,
        'components' => [
            'ratio' => $ratioScore,
            'fairness' => $fairnessScore,
            'recency' => $recencyScore,
            'concentration' => $concentrationScore,
        ],
        'actual_ratio' => $actualRatio,
        'work_total' => $workTotal,
        'mid1_total' => $mid1Total,
        'warnings' => $warnings,
    ];
}





function auto_simulate_week_option(array $state, array $days, array $option): ?array
{
    $newState = $state;
    $assignments = [];
    $restLookup = [];
    foreach ($option as $weekday) {
        $restLookup[(int) $weekday] = true;
    }

    foreach ($days as $dayInfo) {
        $date = $dayInfo['date'];
        $weekday = (int) $dayInfo['weekday'];
        $holiday = (bool) ($dayInfo['holiday'] ?? false);
        $isRest = isset($restLookup[$weekday]);
        if ($isRest) {
            $newState['rest_streak'] = ($newState['rest_streak'] ?? 0) + 1;
            $newState['work_streak'] = 0;
            if ($newState['rest_streak'] > 3) {
                return null;
            }
            $assignments[$date] = AUTO_SHIFT_REST;
        } else {
            $newState['rest_streak'] = 0;
            if (!$holiday) {
                $newState['work_streak'] = ($newState['work_streak'] ?? 0) + 1;
                if ($newState['work_streak'] > 6) {
                    return null;
                }
            } else {
                $newState['work_streak'] = $newState['work_streak'] ?? 0;
            }
            $assignments[$date] = null;
        }
    }

    return [
        'state' => $newState,
        'assignments' => $assignments,
    ];
}

function auto_compare_priority(array $a, array $b): int
{
    if (($a['gap'] ?? 0) !== ($b['gap'] ?? 0)) {
        return ($a['gap'] ?? 0) > ($b['gap'] ?? 0) ? -1 : 1;
    }
    if (($a['month'] ?? 0) !== ($b['month'] ?? 0)) {
        return ($a['month'] ?? 0) < ($b['month'] ?? 0) ? -1 : 1;
    }
    if (($a['quarter'] ?? 0) !== ($b['quarter'] ?? 0)) {
        return ($a['quarter'] ?? 0) < ($b['quarter'] ?? 0) ? -1 : 1;
    }
    if (($a['total'] ?? 0) !== ($b['total'] ?? 0)) {
        return ($a['total'] ?? 0) < ($b['total'] ?? 0) ? -1 : 1;
    }
    return ($a['rand'] ?? 0) <=> ($b['rand'] ?? 0);
}


