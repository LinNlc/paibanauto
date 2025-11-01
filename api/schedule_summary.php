<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET') {
    header('Allow: GET');
    json_err('Method Not Allowed', 405);
}

$context = enforce_view_access('schedule');
/** @var PDO $pdo */
$pdo = $context['pdo'];
$permissions = $context['permissions'];

$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
if ($teamId <= 0) {
    json_err('缺少有效的团队ID', 422);
}

if (!permissions_can_access_team($permissions, $teamId)) {
    permission_denied();
}

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($year < 2000 || $year > 2100) {
    json_err('年份超出支持范围', 422);
}

$start = sprintf('%04d-01-01', $year);
$end = sprintf('%04d-12-31', $year);

$stmt = $pdo->prepare(
    'SELECT e.id,
            e.name,
            e.display_name,
            SUM(CASE WHEN sc.value = "白" THEN 1 ELSE 0 END) AS white_total,
            SUM(CASE WHEN sc.value = "中1" THEN 1 ELSE 0 END) AS mid1_total,
            SUM(CASE WHEN sc.value = "中2" THEN 1 ELSE 0 END) AS mid2_total,
            SUM(CASE WHEN sc.value = "夜" THEN 1 ELSE 0 END) AS night_total,
            SUM(CASE WHEN sc.value = "白" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS white_q1,
            SUM(CASE WHEN sc.value = "白" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 4 AND 6 THEN 1 ELSE 0 END) AS white_q2,
            SUM(CASE WHEN sc.value = "白" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 7 AND 9 THEN 1 ELSE 0 END) AS white_q3,
            SUM(CASE WHEN sc.value = "白" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 10 AND 12 THEN 1 ELSE 0 END) AS white_q4,
            SUM(CASE WHEN sc.value = "中1" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS mid1_q1,
            SUM(CASE WHEN sc.value = "中1" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 4 AND 6 THEN 1 ELSE 0 END) AS mid1_q2,
            SUM(CASE WHEN sc.value = "中1" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 7 AND 9 THEN 1 ELSE 0 END) AS mid1_q3,
            SUM(CASE WHEN sc.value = "中1" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 10 AND 12 THEN 1 ELSE 0 END) AS mid1_q4,
            SUM(CASE WHEN sc.value = "中2" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS mid2_q1,
            SUM(CASE WHEN sc.value = "中2" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 4 AND 6 THEN 1 ELSE 0 END) AS mid2_q2,
            SUM(CASE WHEN sc.value = "中2" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 7 AND 9 THEN 1 ELSE 0 END) AS mid2_q3,
            SUM(CASE WHEN sc.value = "中2" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 10 AND 12 THEN 1 ELSE 0 END) AS mid2_q4,
            SUM(CASE WHEN sc.value = "夜" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS night_q1,
            SUM(CASE WHEN sc.value = "夜" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 4 AND 6 THEN 1 ELSE 0 END) AS night_q2,
            SUM(CASE WHEN sc.value = "夜" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 7 AND 9 THEN 1 ELSE 0 END) AS night_q3,
            SUM(CASE WHEN sc.value = "夜" AND CAST(strftime("%m", sc.day) AS INTEGER) BETWEEN 10 AND 12 THEN 1 ELSE 0 END) AS night_q4
       FROM employees e
  LEFT JOIN schedule_cells sc
         ON sc.emp_id = e.id
        AND sc.team_id = :team
        AND sc.day >= :start
        AND sc.day <= :end
      WHERE e.team_id = :team
   GROUP BY e.id, e.name, e.display_name
   ORDER BY e.sort_order ASC, e.id ASC'
);

$stmt->execute([
    ':team' => $teamId,
    ':start' => $start,
    ':end' => $end,
]);

$records = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $records[] = [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'display_name' => (string) $row['display_name'],
        'annual' => [
            'white' => (int) $row['white_total'],
            'mid1' => (int) $row['mid1_total'],
            'mid2' => (int) $row['mid2_total'],
            'night' => (int) $row['night_total'],
        ],
        'quarters' => [
            'q1' => [
                'white' => (int) $row['white_q1'],
                'mid1' => (int) $row['mid1_q1'],
                'mid2' => (int) $row['mid2_q1'],
                'night' => (int) $row['night_q1'],
            ],
            'q2' => [
                'white' => (int) $row['white_q2'],
                'mid1' => (int) $row['mid1_q2'],
                'mid2' => (int) $row['mid2_q2'],
                'night' => (int) $row['night_q2'],
            ],
            'q3' => [
                'white' => (int) $row['white_q3'],
                'mid1' => (int) $row['mid1_q3'],
                'mid2' => (int) $row['mid2_q3'],
                'night' => (int) $row['night_q3'],
            ],
            'q4' => [
                'white' => (int) $row['white_q4'],
                'mid1' => (int) $row['mid1_q4'],
                'mid2' => (int) $row['mid2_q4'],
                'night' => (int) $row['night_q4'],
            ],
        ],
    ];
}

json_ok([
    'team_id' => $teamId,
    'year' => $year,
    'records' => $records,
]);

