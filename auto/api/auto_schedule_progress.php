<?php

declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

$context = auth_context();
$pdo = $context['pdo'];
$permissions = $context['permissions'];

$jobId = isset($_GET['job_id']) ? (string) $_GET['job_id'] : '';
if ($jobId === '') {
    json_err('缺少 job_id', 422);
}

$job = auto_fetch_job($pdo, $jobId);
if ($job === null) {
    json_err('任务不存在', 404);
}

ensure_team_access($permissions, (int) $job['team_id']);
release_session_lock();

set_time_limit(0);
ignore_user_abort(true);
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

echo "retry: 2000\n";
echo ": init\n\n";
flush();

$sent = 0;
$keepAlive = (int) (app_config()['sse_keepalive_sec'] ?? 15);
if ($keepAlive < 3) {
    $keepAlive = 3;
}
$lastBeat = time();

while (!connection_aborted()) {
    $job = auto_fetch_job($pdo, $jobId);
    if ($job === null) {
        break;
    }
    $events = is_array($job['events']) ? $job['events'] : [];
    $total = count($events);
    while ($sent < $total) {
        $event = $events[$sent];
        $event['status'] = $job['status'];
        $event['job_id'] = $jobId;
        echo "event: progress\n";
        echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        $sent++;
    }

    if ($job['status'] === AUTO_STATUS_DONE || $job['status'] === AUTO_STATUS_FAILED) {
        $result = $job['result'] ?? null;
        if ($result !== null) {
            echo "event: result\n";
            echo 'data: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
        break;
    }

    $now = time();
    if ($now - $lastBeat >= $keepAlive) {
        echo ": ping\n\n";
        flush();
        $lastBeat = $now;
    }

    usleep(200000);
}
