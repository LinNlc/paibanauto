<?php

declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    header('Allow: POST');
    json_err('Method Not Allowed', 405);
}

$payload = auto_read_json_payload();
$jobId = isset($payload['job_id']) ? (string) $payload['job_id'] : '';
if ($jobId === '') {
    json_err('缺少 job_id', 422);
}

$context = auth_context();
$pdo = $context['pdo'];
$user = $context['user'];
$permissions = $context['permissions'];

$job = auto_fetch_job($pdo, $jobId);
if ($job === null) {
    json_err('任务不存在', 404);
}

$teamId = (int) $job['team_id'];
if (!permissions_can_edit_team($permissions, $teamId)) {
    permission_denied();
}

if ($job['status'] !== AUTO_STATUS_DONE) {
    json_err('任务尚未完成', 409);
}

$resultPayload = $job['result']['result'] ?? null;
if (!is_array($resultPayload)) {
    json_err('任务结果缺失', 500);
}

$diffOps = $resultPayload['diff_ops'] ?? [];
$params = json_decode($job['params_json'] ?? '{}', true);
$start = (string) ($params['start'] ?? ($resultPayload['grid']['days'][0]['date'] ?? ''));

$versions = auto_apply_diff($pdo, $teamId, (int) ($user['id'] ?? 0), $diffOps);
auto_save_rest_cycle($pdo, $teamId, $start, $resultPayload['rest_cycle'] ?? []);
auto_save_shift_debt($pdo, $teamId, $start, $resultPayload['shift_debt'] ?? []);

auto_update_job($pdo, $jobId, [
    'note' => '已手动应用',
]);

json_ok([
    'job_id' => $jobId,
    'applied_ops' => count($diffOps),
    'versions' => $versions,
]);

function auto_read_json_payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_err('JSON格式错误', 400);
    }
    return $data;
}
