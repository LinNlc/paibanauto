<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$pdo = db();
$user = current_user();
if ($user === null) {
    json_err('未登录', 401);
}

$permissions = load_user_permissions($pdo, (int) ($user['id'] ?? 0));
if ($permissions === null || !permissions_can_view_section($permissions, 'schedule')) {
    json_err('无权访问排班数据', 403);
}

$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
if ($teamId <= 0 || !permissions_can_access_team($permissions, $teamId)) {
    json_err('无权访问该团队', 403);
}

$startParam = isset($_GET['start']) ? (string) $_GET['start'] : '';
$endParam = isset($_GET['end']) ? (string) $_GET['end'] : '';
[$startDate, $endDate] = resolve_stream_range($startParam, $endParam);

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

$keepAlive = (int) (app_config()['sse_keepalive_sec'] ?? 15);
if ($keepAlive < 3) {
    $keepAlive = 3;
}

$logPath = sse_events_log_path();
$directory = dirname($logPath);
if (!is_dir($directory)) {
    @mkdir($directory, 0775, true);
}
if (!file_exists($logPath)) {
    @touch($logPath);
}

$stream = @fopen($logPath, 'rb');
if ($stream === false) {
    echo "event: error\n";
    echo 'data: {"message":"无法打开事件流"}' . "\n\n";
    flush();
    exit;
}

fseek($stream, 0, SEEK_END);
stream_set_blocking($stream, false);

$lastHeartbeat = time();

while (true) {
    if (connection_aborted()) {
        break;
    }

    $line = fgets($stream);
    if ($line !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $payload = json_decode($line, true);
        if (!is_array($payload)) {
            continue;
        }

        if ((int) ($payload['team_id'] ?? 0) !== $teamId) {
            continue;
        }

        $day = (string) ($payload['day'] ?? '');
        if ($day === '' || strcmp($day, $startDate) < 0 || strcmp($day, $endDate) > 0) {
            continue;
        }

        $data = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($data === false) {
            continue;
        }

        echo "event: op\n";
        echo 'data: ' . $data . "\n\n";
        flush();
        continue;
    }

    if (feof($stream)) {
        clearstatcache(false, $logPath);
    }

    $now = time();
    if ($now - $lastHeartbeat >= $keepAlive) {
        echo ": ping\n\n";
        flush();
        $lastHeartbeat = $now;
    }

    $size = @filesize($logPath);
    if ($size !== false && $size < ftell($stream)) {
        fclose($stream);
        $stream = @fopen($logPath, 'rb');
        if ($stream === false) {
            break;
        }
        stream_set_blocking($stream, false);
        fseek($stream, 0, SEEK_END);
    }

    usleep(250000);
}

if (is_resource($stream)) {
    fclose($stream);
}

function resolve_stream_range(string $start, string $end): array
{
    $startDate = normalize_stream_date($start);
    $endDate = normalize_stream_date($end);

    if ($startDate === null || $endDate === null) {
        json_err('日期格式应为 YYYY-MM-DD', 422);
    }

    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }

    return [$startDate, $endDate];
}

function normalize_stream_date(string $value): ?string
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
