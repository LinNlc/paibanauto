<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = strtolower((string) ($_REQUEST['action'] ?? ''));

$context = enforce_view_access('schedule');
/** @var PDO $pdo */
$pdo = $context['pdo'];
$permissions = $context['permissions'];
$user = $context['user'];

switch ($action) {
    case 'template':
        ensure_import_export_permission($permissions);
        deliver_template_workbook();
        break;
    case 'export':
        if ($method !== 'GET') {
            header('Allow: GET, POST');
            json_err('Method Not Allowed', 405);
        }
        ensure_import_export_permission($permissions);
        handle_export($pdo, $permissions);
        break;
    case 'import':
        if ($method !== 'POST') {
            header('Allow: GET, POST');
            json_err('Method Not Allowed', 405);
        }
        ensure_import_export_permission($permissions);
        handle_import($pdo, $user, $permissions);
        break;
    default:
        json_err('未知操作', 400);
}

function ensure_import_export_permission(array $permissions): void
{
    if (!permissions_has_feature($permissions, 'scheduleImportExport')) {
        permission_denied();
    }
}

function deliver_template_workbook(): void
{
    $rows = [
        ['日期', '示例：张三', '示例：李四'],
        ['2024-01-01', '白', '中1'],
    ];

    $content = build_workbook($rows);
    output_workbook('schedule_template.xlsx', $content);
}

function handle_export(PDO $pdo, array $permissions): void
{
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
    if ($teamId <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    if (!permissions_can_access_team($permissions, $teamId)) {
        permission_denied();
    }

    $start = isset($_GET['start']) ? (string) $_GET['start'] : '';
    $end = isset($_GET['end']) ? (string) $_GET['end'] : '';

    [$startDate, $endDate] = resolve_export_range($start, $end);

    $employees = fetch_team_employees_for_io($pdo, $teamId);
    if ($employees === []) {
        json_err('该团队暂无员工数据', 404);
    }

    $cells = fetch_schedule_cells_for_range($pdo, $teamId, $startDate, $endDate);
    $rows = build_export_rows($employees, $cells, $startDate, $endDate);

    $filename = sprintf('schedule_%d_%s_%s.xlsx', $teamId, str_replace('-', '', $startDate), str_replace('-', '', $endDate));
    $content = build_workbook($rows);
    output_workbook($filename, $content);
}

function handle_import(PDO $pdo, array $user, array $permissions): void
{
    $teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
    if ($teamId <= 0) {
        json_err('缺少有效的团队ID', 422);
    }

    if (!permissions_can_access_team($permissions, $teamId)) {
        permission_denied();
    }

    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        json_err('请上传有效的 Excel 文件', 422);
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $rows = parse_workbook_rows($tmpPath);
    if (count($rows) <= 1) {
        json_err('导入文件中不包含有效数据', 422);
    }

    $header = array_map('trim', $rows[0]);
    if (($header[0] ?? '') !== '日期') {
        json_err('模板格式不正确，请使用系统提供的模板', 422);
    }

    $employees = fetch_team_employees_for_io($pdo, $teamId);
    if ($employees === []) {
        json_err('该团队暂无员工数据', 404);
    }
    $normalizeName = static function (string $value): string {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($trimmed, 'UTF-8');
        }

        return strtolower($trimmed);
    };

    $nameIndex = [];
    foreach ($employees as $employee) {
        $candidates = [];
        if (isset($employee['display_name']) && trim((string) $employee['display_name']) !== '') {
            $candidates[] = trim((string) $employee['display_name']);
        }
        if (isset($employee['name']) && trim((string) $employee['name']) !== '') {
            $candidates[] = trim((string) $employee['name']);
        }
        foreach ($candidates as $candidate) {
            $key = $normalizeName($candidate);
            if ($key !== '' && !isset($nameIndex[$key])) {
                $nameIndex[$key] = (int) $employee['id'];
            }
        }
    }

    $columnMap = [];
    $usedEmployees = [];
    $headerCount = count($header);
    for ($col = 1; $col < $headerCount; $col++) {
        $rawName = trim((string) ($header[$col] ?? ''));
        if ($rawName === '') {
            continue;
        }
        $key = $normalizeName($rawName);
        if ($key === '' || !isset($nameIndex[$key])) {
            json_err('无法匹配人员列：' . $rawName, 422);
        }
        $empId = $nameIndex[$key];
        if (isset($usedEmployees[$empId])) {
            json_err('导入文件中存在重复的人员列：' . $rawName, 422);
        }
        $columnMap[$col] = $empId;
        $usedEmployees[$empId] = true;
    }

    if ($columnMap === []) {
        json_err('未识别到任何人员列', 422);
    }

    $updates = [];
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if ($row === []) {
            continue;
        }
        $date = normalize_day((string) ($row[0] ?? ''));
        if ($date === null) {
            continue;
        }
        foreach ($columnMap as $colIndex => $empId) {
            $valueRaw = $row[$colIndex] ?? '';
            $value = normalize_shift_value($valueRaw);
            if ($value === null) {
                continue;
            }
            $updates[] = [
                'day' => $date,
                'emp_id' => $empId,
                'value' => $value,
            ];
        }
    }

    if ($updates === []) {
        json_err('未解析到任何有效的班次记录', 422);
    }

    $pdo->beginTransaction();
    try {
        $affected = 0;
        foreach ($updates as $update) {
            $result = apply_schedule_value($pdo, $user, $teamId, $update['emp_id'], $update['day'], $update['value']);
            if ($result['changed'] ?? false) {
                $affected++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_err('导入失败：' . $e->getMessage(), 500);
    }

    json_ok(['updated' => $affected]);
}

function resolve_export_range(string $start, string $end): array
{
    $startDate = normalize_day($start);
    $endDate = normalize_day($end);

    if ($startDate === null || $endDate === null) {
        json_err('请选择有效的日期范围', 422);
    }

    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }

    $startObj = new DateTimeImmutable($startDate);
    $endObj = new DateTimeImmutable($endDate);
    $diff = $startObj->diff($endObj)->days ?? 0;
    if ($diff > 120) {
        json_err('导出区间过大，最多支持 120 天', 422);
    }

    return [$startDate, $endDate];
}

function fetch_team_employees_for_io(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare('SELECT id, name, display_name FROM employees WHERE team_id = :team ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':team' => $teamId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'display_name' => (string) $row['display_name'],
        ];
    }, $rows);
}

function fetch_schedule_cells_for_range(PDO $pdo, int $teamId, string $start, string $end): array
{
    $stmt = $pdo->prepare('SELECT day, emp_id, value FROM schedule_cells WHERE team_id = :team AND day >= :start AND day <= :end');
    $stmt->execute([
        ':team' => $teamId,
        ':start' => $start,
        ':end' => $end,
    ]);

    $grid = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $day = (string) $row['day'];
        $empId = (int) $row['emp_id'];
        if (!isset($grid[$day])) {
            $grid[$day] = [];
        }
        $grid[$day][$empId] = (string) ($row['value'] ?? '');
    }

    return $grid;
}

function build_export_rows(array $employees, array $cells, string $start, string $end): array
{
    $headers = ['日期'];
    $orderedEmployees = [];
    foreach ($employees as $employee) {
        $label = (string) ($employee['display_name'] ?? '');
        if ($label === '') {
            $label = (string) ($employee['name'] ?? '');
        }
        if ($label === '') {
            $label = '员工 #' . $employee['id'];
        }
        $headers[] = $label;
        $orderedEmployees[] = $employee;
    }

    $rows = [$headers];
    $cursor = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);

    while ($cursor <= $endDate) {
        $day = $cursor->format('Y-m-d');
        $row = [$day];
        foreach ($orderedEmployees as $employee) {
            $row[] = $cells[$day][$employee['id']] ?? '';
        }
        $rows[] = $row;
        $cursor = $cursor->modify('+1 day');
    }

    return $rows;
}

function build_workbook(array $rows): string
{
    $sheetXml = generate_sheet_xml($rows);
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        json_err('无法创建临时文件', 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        json_err('无法生成Excel文件', 500);
    }

    $zip->addFromString('[Content_Types].xml', get_content_types_xml());
    $zip->addFromString('_rels/.rels', get_root_rels_xml());
    $zip->addFromString('xl/_rels/workbook.xml.rels', get_workbook_rels_xml());
    $zip->addFromString('xl/workbook.xml', get_workbook_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $content = file_get_contents($tmp);
    @unlink($tmp);

    if ($content === false) {
        json_err('生成Excel文件失败', 500);
    }

    return $content;
}

function output_workbook(string $filename, string $content): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

function generate_sheet_xml(array $rows): string
{
    $xml = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
        '<sheetData>',
    ];

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $xml[] = '<row r="' . $excelRow . '">';
        foreach ($row as $colIndex => $cell) {
            $columnLetter = column_index_to_name($colIndex + 1);
            $ref = $columnLetter . $excelRow;
            $escaped = htmlspecialchars((string) $cell, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $xml[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
        }
        $xml[] = '</row>';
    }

    $xml[] = '</sheetData>';
    $xml[] = '</worksheet>';

    return implode('', $xml);
}

function get_content_types_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';
}

function get_root_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function get_workbook_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
}

function get_workbook_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

function parse_workbook_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        json_err('无法读取上传的Excel文件', 422);
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false) {
        json_err('未找到工作表数据', 422);
    }

    $rows = [];
    $doc = new DOMDocument();
    $doc->loadXML($sheetXml);
    $rowNodes = $doc->getElementsByTagName('row');
    foreach ($rowNodes as $rowNode) {
        $cells = [];
        foreach ($rowNode->getElementsByTagName('c') as $cellNode) {
            $type = $cellNode->getAttribute('t');
            $ref = $cellNode->getAttribute('r');
            $colIndex = column_name_to_index($ref);
            if ($colIndex <= 0) {
                $colIndex = count($cells) + 1;
            }
            while (count($cells) < $colIndex - 1) {
                $cells[] = '';
            }
            if ($type === 'inlineStr') {
                $textNode = $cellNode->getElementsByTagName('t')->item(0);
                $cells[$colIndex - 1] = $textNode ? $textNode->textContent : '';
            } else {
                $valueNode = $cellNode->getElementsByTagName('v')->item(0);
                $cells[$colIndex - 1] = $valueNode ? $valueNode->textContent : '';
            }
        }
        $rows[] = $cells;
    }

    return $rows;
}

function column_index_to_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }

    return $name;
}

function column_name_to_index(string $ref): int
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

function apply_schedule_value(PDO $pdo, array $user, int $teamId, int $employeeId, string $day, string $value): array
{
    $userId = (int) ($user['id'] ?? 0);
    $displayName = trim((string) ($user['display_name'] ?? ''));
    if ($displayName === '') {
        $displayName = (string) ($user['username'] ?? '');
    }

    if ($value === '') {
        $result = process_empty_value_update($pdo, $userId, $displayName, $teamId, $employeeId, $day, PHP_INT_MAX);
    } else {
        $result = process_value_update($pdo, $userId, $displayName, $teamId, $employeeId, $day, $value, PHP_INT_MAX);
    }

    if (($result['changed'] ?? false) === true) {
        $cell = $result['cell'];
        $event = [
            'team_id' => $teamId,
            'day' => $cell['day'],
            'emp_id' => $cell['emp_id'],
            'value' => $cell['value'],
            'version' => $cell['version'],
            'updated_at' => $cell['updated_at'],
            'updated_by' => $cell['updated_by']['id'] ?? null,
            'updated_by_name' => $cell['updated_by']['display_name'] ?? $displayName,
        ];
        record_schedule_op($pdo, $teamId, [
            'type' => 'schedule_cell_update',
            'cell' => $event,
        ], $cell['updated_by']['id'] ?? null);
        append_sse_event($event);
    }

    return $result;
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

