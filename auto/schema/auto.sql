PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS auto_rules (
    team_id INTEGER PRIMARY KEY,
    rules_json TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rest_cycle_ledger (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    quarter INTEGER NOT NULL,
    emp_id INTEGER NOT NULL,
    rest_type TEXT NOT NULL,
    UNIQUE(team_id, year, quarter, emp_id),
    FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY(emp_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS shift_debt_ledger (
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
);

CREATE TABLE IF NOT EXISTS auto_jobs (
    job_id TEXT PRIMARY KEY,
    team_id INTEGER NOT NULL,
    params_json TEXT NOT NULL,
    status TEXT NOT NULL,
    phase TEXT NOT NULL DEFAULT '',
    progress REAL NOT NULL DEFAULT 0,
    score REAL,
    eta TEXT DEFAULT '',
    note TEXT DEFAULT '',
    events_json TEXT DEFAULT '[]',
    result_json TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_auto_jobs_team ON auto_jobs(team_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_rest_cycle_ledger_team ON rest_cycle_ledger(team_id, year, quarter);
CREATE INDEX IF NOT EXISTS idx_shift_debt_ledger_team ON shift_debt_ledger(team_id, year, quarter);
