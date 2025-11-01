<?php

declare(strict_types=1);

function app_config(): array
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/../config/app.php';
    }
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $dbPath = (string)($config['db_path'] ?? '');
    if ($dbPath === '') {
        throw new RuntimeException('database path is not configured');
    }

    $dir = dirname($dbPath);
    if ($dir !== '' && !is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('failed to create database directory');
        }
    }

    $dsn = 'sqlite:' . $dbPath;
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    ensure_schema_up_to_date($pdo);

    return $pdo;
}

function ensure_schema_up_to_date(PDO $pdo): void
{
    static $applied = false;
    if ($applied) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = ON');

    ensure_table_column($pdo, 'teams', 'settings_json', "ALTER TABLE teams ADD COLUMN settings_json TEXT DEFAULT '{}'");

    ensure_table_column($pdo, 'users', 'display_name', "ALTER TABLE users ADD COLUMN display_name TEXT NOT NULL DEFAULT ''");
    ensure_table_column($pdo, 'users', 'role', "ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
    ensure_table_column($pdo, 'users', 'allowed_teams_json', "ALTER TABLE users ADD COLUMN allowed_teams_json TEXT DEFAULT '[]'");
    ensure_table_column($pdo, 'users', 'allowed_views_json', "ALTER TABLE users ADD COLUMN allowed_views_json TEXT DEFAULT '[]'");
    ensure_table_column($pdo, 'users', 'editable_teams_json', "ALTER TABLE users ADD COLUMN editable_teams_json TEXT DEFAULT '[]'");
    ensure_table_column($pdo, 'users', 'features_json', "ALTER TABLE users ADD COLUMN features_json TEXT DEFAULT '{}'");
    ensure_table_column($pdo, 'users', 'disabled', "ALTER TABLE users ADD COLUMN disabled INTEGER NOT NULL DEFAULT 0");

    $applied = true;
}

function ensure_table_column(PDO $pdo, string $table, string $column, string $ddl): void
{
    if (table_has_column($pdo, $table, $column)) {
        return;
    }

    $pdo->exec($ddl);
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
        throw new InvalidArgumentException('invalid table name');
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
        throw new InvalidArgumentException('invalid column name');
    }

    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    if ($stmt === false) {
        return false;
    }

    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $info) {
        if (isset($info['name']) && strcasecmp((string) $info['name'], $column) === 0) {
            return true;
        }
    }

    return false;
}

function json_ok(array $data = []): void
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'ok' => false,
        'error' => $message,
        'msg' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function permission_denied(): void
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'msg' => 'forbidden',
        'error' => 'forbidden',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_session_started(): void
{
    static $started = false;
    if ($started) {
        return;
    }

    $config = app_config();
    $sessionName = (string)($config['session_name'] ?? 'paibanauto');
    if (session_status() === PHP_SESSION_NONE) {
        session_name($sessionName);
        $isSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    $started = true;
}

function current_user(): ?array
{
    ensure_session_started();
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        return null;
    }

    return $user;
}

function set_current_user(array $user): void
{
    ensure_session_started();
    $_SESSION['user'] = $user;
}

function clear_current_user(): void
{
    ensure_session_started();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function release_session_lock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        json_err('未登录', 401);
    }

    return $user;
}

function require_admin(): array
{
    $context = enforce_view_access('admin');
    if (($context['permissions']['role'] ?? '') !== 'admin') {
        permission_denied();
    }

    return $context['user'];
}

function merge_user_permissions(array $user, array $permissions): array
{
    $user['role'] = $permissions['role'] ?? ($user['role'] ?? '');
    $user['is_admin'] = (bool) ($permissions['is_admin'] ?? false);

    $allowedViews = $permissions['allowed_views'] ?? [];
    if (!is_array($allowedViews)) {
        $allowedViews = [];
    }
    $user['allowed_views'] = $allowedViews;

    $user['allowed_teams'] = $permissions['allowed_teams'] ?? null;
    $user['editable_teams'] = $permissions['editable_teams'] ?? null;
    $user['features'] = $permissions['features'] ?? normalize_feature_flags(null);

    return $user;
}

function auth_context(): array
{
    static $cached;
    if ($cached !== null) {
        return $cached;
    }

    $user = require_login();
    $pdo = db();
    $permissions = load_user_permissions($pdo, (int) ($user['id'] ?? 0));
    if ($permissions === null) {
        permission_denied();
    }

    $user = merge_user_permissions($user, $permissions);
    set_current_user($user);

    $cached = [
        'pdo' => $pdo,
        'user' => $user,
        'permissions' => $permissions,
    ];

    return $cached;
}

function enforce_view_access(string $view): array
{
    $context = auth_context();
    if ($view !== '' && !permissions_can_view_section($context['permissions'], $view)) {
        permission_denied();
    }

    return $context;
}

function enforce_edit_access(int $teamId): array
{
    $context = auth_context();
    if ($teamId <= 0) {
        json_err('缺少有效的团队', 422);
    }

    if (!permissions_can_access_team($context['permissions'], $teamId)
        || !permissions_can_edit_team($context['permissions'], $teamId)) {
        permission_denied();
    }

    return $context;
}

function ensure_team_access(array $permissions, int $teamId): void
{
    if ($teamId <= 0) {
        json_err('缺少有效的团队', 422);
    }

    if (!permissions_can_access_team($permissions, $teamId)) {
        permission_denied();
    }
}

/**
 * @template T
 * @param T $default
 * @return T|array<mixed>
 */
function decode_json_field(?string $json, $default)
{
    if ($json === null || $json === '') {
        return $default;
    }

    $data = json_decode($json, true);
    if ($data === null || $data === false) {
        return $default;
    }

    return is_array($data) ? $data : $default;
}

function decode_team_settings(?string $json): array
{
    $settings = decode_json_field($json, []);
    return normalize_team_settings($settings);
}

function encode_json_field($value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        json_err('JSON编码失败', 500);
    }

    return $encoded;
}

function hash_password(string $password): string
{
    $config = app_config();
    $options = [];
    if (isset($config['password_cost'])) {
        $cost = (int) $config['password_cost'];
        if ($cost >= 4) {
            $options['cost'] = $cost;
        }
    }

    if ($options !== []) {
        return password_hash($password, PASSWORD_DEFAULT, $options);
    }

    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * @param mixed $value
 * @return array<int>
 */
function normalize_id_list($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $id = (int) $item;
        if ($id <= 0) {
            continue;
        }
        $result[$id] = $id;
    }

    if ($result === []) {
        return [];
    }

    ksort($result);

    return array_values($result);
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function normalize_string_list($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $str = trim((string) $item);
        if ($str === '' || isset($result[$str])) {
            continue;
        }
        $result[$str] = $str;
    }

    return array_values($result);
}

function normalize_feature_flags($value): array
{
    $defaults = [
        'scheduleFloatingBall' => false,
        'scheduleImportExport' => false,
        'scheduleAssistSettings' => false,
        'scheduleAi' => false,
    ];

    if (!is_array($value)) {
        return $defaults;
    }

    foreach ($defaults as $key => $fallback) {
        $raw = $value[$key] ?? $fallback;
        $defaults[$key] = is_bool($raw) ? $raw : (bool) $raw;
    }

    return $defaults;
}

function schedule_assist_color_options(): array
{
    return [
        'white' => ['sky', 'amber', 'emerald', 'slate'],
        'mid1' => ['indigo', 'violet', 'amber', 'slate'],
        'mid2' => ['teal', 'emerald', 'cyan', 'slate'],
        'night' => ['rose', 'violet', 'amber', 'slate'],
    ];
}

function schedule_summary_color_options(): array
{
    return ['emerald', 'sky', 'amber', 'rose', 'slate'];
}

function schedule_assist_default_settings(): array
{
    return [
        'shiftColors' => [
            'white' => 'sky',
            'mid1' => 'indigo',
            'mid2' => 'teal',
            'night' => 'rose',
        ],
        'hover' => [
            'showAnnual' => true,
            'showQuarterly' => true,
        ],
        'dailySummary' => [
            'enabled' => true,
            'showTotal' => true,
            'showWhite' => true,
            'showMid1' => true,
            'showMid2' => true,
            'showNight' => true,
            'thresholds' => [
                'total' => [
                    'high' => ['value' => null, 'color' => 'emerald'],
                    'low' => ['value' => null, 'color' => 'rose'],
                ],
                'white' => [
                    'high' => ['value' => null, 'color' => 'emerald'],
                    'low' => ['value' => null, 'color' => 'rose'],
                ],
                'mid1' => [
                    'high' => ['value' => null, 'color' => 'emerald'],
                    'low' => ['value' => null, 'color' => 'rose'],
                ],
                'mid2' => [
                    'high' => ['value' => null, 'color' => 'emerald'],
                    'low' => ['value' => null, 'color' => 'rose'],
                ],
                'night' => [
                    'high' => ['value' => null, 'color' => 'emerald'],
                    'low' => ['value' => null, 'color' => 'rose'],
                ],
            ],
        ],
    ];
}

function normalize_assist_shift_colors($value): array
{
    $defaults = schedule_assist_default_settings()['shiftColors'];
    $options = schedule_assist_color_options();
    if (!is_array($value)) {
        return $defaults;
    }

    foreach ($defaults as $key => $fallback) {
        $raw = isset($value[$key]) ? (string) $value[$key] : $fallback;
        if (!in_array($raw, $options[$key], true)) {
            $raw = $fallback;
        }
        $defaults[$key] = $raw;
    }

    return $defaults;
}

function normalize_assist_hover($value): array
{
    $defaults = schedule_assist_default_settings()['hover'];
    if (!is_array($value)) {
        return $defaults;
    }

    foreach ($defaults as $key => $fallback) {
        $raw = $value[$key] ?? $fallback;
        $defaults[$key] = is_bool($raw) ? $raw : (bool) $raw;
    }

    return $defaults;
}

function normalize_assist_threshold($value, string $defaultColor): array
{
    $allowedColors = schedule_summary_color_options();
    $normalized = ['value' => null, 'color' => $defaultColor];
    if (is_array($value)) {
        if (isset($value['value'])) {
            $num = $value['value'];
            if ($num === null || $num === '' || !is_numeric($num)) {
                $normalized['value'] = null;
            } else {
                $parsed = (int) $num;
                $normalized['value'] = $parsed >= 0 ? $parsed : null;
            }
        }
        if (isset($value['color'])) {
            $color = (string) $value['color'];
            if (in_array($color, $allowedColors, true)) {
                $normalized['color'] = $color;
            }
        }
    }

    return $normalized;
}

function normalize_assist_daily_summary($value): array
{
    $defaults = schedule_assist_default_settings()['dailySummary'];
    if (!is_array($value)) {
        return $defaults;
    }

    foreach (['enabled', 'showTotal', 'showWhite', 'showMid1', 'showMid2', 'showNight'] as $key) {
        $raw = $value[$key] ?? $defaults[$key];
        $defaults[$key] = is_bool($raw) ? $raw : (bool) $raw;
    }

    $thresholds = $value['thresholds'] ?? [];
    if (!is_array($thresholds)) {
        $thresholds = [];
    }

    foreach ($defaults['thresholds'] as $key => $config) {
        $raw = $thresholds[$key] ?? [];
        $defaults['thresholds'][$key] = [
            'high' => normalize_assist_threshold($raw['high'] ?? null, $config['high']['color']),
            'low' => normalize_assist_threshold($raw['low'] ?? null, $config['low']['color']),
        ];
    }

    return $defaults;
}

function normalize_assist_settings($value): array
{
    $defaults = schedule_assist_default_settings();
    if (!is_array($value)) {
        return $defaults;
    }

    $defaults['shiftColors'] = normalize_assist_shift_colors($value['shiftColors'] ?? []);
    $defaults['hover'] = normalize_assist_hover($value['hover'] ?? []);
    $defaults['dailySummary'] = normalize_assist_daily_summary($value['dailySummary'] ?? []);

    return $defaults;
}

function normalize_team_settings($value): array
{
    $settings = is_array($value) ? $value : [];
    $assist = $settings['assist'] ?? [];
    $settings['assist'] = normalize_assist_settings($assist);
    return $settings;
}

function get_shift_options(): array
{
    $config = app_config();
    $options = $config['shift_options'] ?? [];
    if (!is_array($options)) {
        return [];
    }

    $normalized = [];
    foreach ($options as $option) {
        $value = (string) $option;
        if ($value === '') {
            continue;
        }
        $normalized[] = $value;
    }

    return $normalized;
}

function load_user_permissions(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, role, disabled, allowed_teams_json, allowed_views_json, editable_teams_json, features_json FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['disabled'] === 1) {
        return null;
    }

    $role = (string) $row['role'];
    $isAdmin = $role === 'admin';
    $allowedTeams = $isAdmin ? null : normalize_id_list(decode_json_field($row['allowed_teams_json'] ?? '[]', []));
    $editableTeams = $isAdmin ? null : normalize_id_list(decode_json_field($row['editable_teams_json'] ?? '[]', []));
    $allowedViews = $isAdmin ? ['*'] : normalize_string_list(decode_json_field($row['allowed_views_json'] ?? '[]', []));
    $features = normalize_feature_flags(decode_json_field($row['features_json'] ?? '{}', []));

    return [
        'id' => (int) $row['id'],
        'role' => $role,
        'is_admin' => $isAdmin,
        'allowed_teams' => $allowedTeams,
        'editable_teams' => $editableTeams,
        'allowed_views' => $allowedViews,
        'features' => $features,
    ];
}

function permissions_can_view_section(array $permissions, string $section): bool
{
    if (($permissions['is_admin'] ?? false) === true) {
        return true;
    }

    $allowedViews = $permissions['allowed_views'] ?? [];
    if (!is_array($allowedViews)) {
        return false;
    }

    if (in_array('*', $allowedViews, true)) {
        return true;
    }

    return in_array($section, $allowedViews, true);
}

function permissions_can_access_team(array $permissions, int $teamId): bool
{
    if (($permissions['is_admin'] ?? false) === true) {
        return true;
    }

    $allowed = $permissions['allowed_teams'] ?? [];
    if ($allowed === null) {
        return true;
    }

    return in_array($teamId, $allowed, true);
}

function permissions_can_edit_team(array $permissions, int $teamId): bool
{
    if (($permissions['is_admin'] ?? false) === true) {
        return true;
    }

    $editable = $permissions['editable_teams'] ?? [];
    if ($editable === null) {
        return true;
    }

    return in_array($teamId, $editable, true);
}

function permissions_has_feature(array $permissions, string $feature): bool
{
    if (($permissions['is_admin'] ?? false) === true) {
        return true;
    }

    $features = $permissions['features'] ?? [];
    if (!is_array($features)) {
        return false;
    }

    if (array_key_exists($feature, $features)) {
        return (bool) $features[$feature];
    }

    return false;
}

function sse_events_log_path(): string
{
    $config = app_config();
    $dbPath = (string) ($config['db_path'] ?? (__DIR__ . '/../data/schedule.db'));
    $baseDir = dirname($dbPath);

    return $baseDir . '/sse_events.log';
}

function append_sse_event(array $event): void
{
    $payload = $event;
    if (!isset($payload['dispatched_at'])) {
        $payload['dispatched_at'] = gmdate('c');
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return;
    }

    $path = sse_events_log_path();
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $handle = @fopen($path, 'ab');
    if ($handle === false) {
        return;
    }

    try {
        if (flock($handle, LOCK_EX)) {
            fwrite($handle, $encoded . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        }
    } finally {
        fclose($handle);
    }
}

function record_schedule_op(PDO $pdo, int $teamId, array $op, ?int $userId = null): void
{
    $json = json_encode($op, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO ops_log (team_id, op_json, created_at, created_by) VALUES (:team_id, :op_json, :created_at, :created_by)');
        $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
        $stmt->bindValue(':op_json', $json, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        if ($userId !== null && $userId > 0) {
            $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':created_by', null, PDO::PARAM_NULL);
        }
        $stmt->execute();
    } catch (Throwable $e) {
        // 忽略日志写入异常，避免影响主流程
    }
}
