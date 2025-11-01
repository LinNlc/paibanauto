#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)"
DATA_DIR="$ROOT_DIR/data"
DB_PATH="$DATA_DIR/schedule.db"
SCHEMA_FILE="$ROOT_DIR/schema/init.sql"

mkdir -p "$DATA_DIR"

if ! command -v sqlite3 >/dev/null 2>&1; then
  echo "sqlite3 command not found. Please install SQLite3." >&2
  exit 1
fi

if [ ! -f "$SCHEMA_FILE" ]; then
  echo "Schema file not found: $SCHEMA_FILE" >&2
  exit 1
fi

sqlite3 "$DB_PATH" < "$SCHEMA_FILE"

sqlite3 "$DB_PATH" <<'SQL'
INSERT OR IGNORE INTO teams (id, name, settings_json) VALUES (1, '默认团队', '{}');

INSERT OR IGNORE INTO users (
    id, username, display_name, password_hash, role,
    allowed_teams_json, allowed_views_json, editable_teams_json,
    features_json, disabled, created_at
) VALUES (
    1, 'admin', '管理员', '$2y$12$COi/ECLUl0ZonJ5IJPzhT.ph/9b87G61.6Ero/N.vpbR4/nU0/qhi', 'admin',
    '[1]', '["people","schedule","stats"]', '[1]',
    '{"scheduleFloatingBall":true,"scheduleImportExport":true,"scheduleAssistSettings":true,"scheduleAi":false}',
    0, datetime('now')
);

INSERT OR IGNORE INTO employees (id, team_id, name, display_name, active, sort_order) VALUES
    (1, 1, 'employee1', '测试员工A', 1, 1),
    (2, 1, 'employee2', '测试员工B', 1, 2);
SQL

chmod 600 "$DB_PATH"

echo "Installation complete. Database initialized at $DB_PATH"
