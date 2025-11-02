<?php

declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    header('Allow: POST');
    json_err('Method Not Allowed', 405);
}

$payload = auto_read_json_payload();
$params = auto_decode_params($payload);
$params['apply'] = !empty($payload['apply']);

$context = enforce_edit_access($params['team_id']);
/** @var PDO $pdo */
$pdo = $context['pdo'];
$user = $context['user'];

$params['created_by'] = (int) ($user['id'] ?? 0);

auto_ensure_schema($pdo);
$jobId = auto_create_job($pdo, $params['team_id'], $params);
auto_update_job($pdo, $jobId, [
    'note' => '已排队等待执行',
]);
auto_spawn_job_runner($jobId);

json_ok([
    'job_id' => $jobId,
    'status' => AUTO_STATUS_QUEUED,
    'score' => null,
    'violations' => [],
    'proposed_ops' => [],
    'grid' => new stdClass(),
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
