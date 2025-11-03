const form = document.getElementById('auto-form');
const startBtn = document.getElementById('start-btn');
const applyBtn = document.getElementById('apply-btn');
const pauseBtn = document.getElementById('pause-btn');
const resumeBtn = document.getElementById('resume-btn');
const cancelBtn = document.getElementById('cancel-btn');
const progressBar = document.getElementById('progress-bar');
const stepList = document.getElementById('step-list');
const timeline = document.getElementById('timeline');
const statusPill = document.getElementById('job-status');
const violationsBox = document.getElementById('violations');
const violationsList = violationsBox.querySelector('ul');
const scoreCards = {
  total: document.getElementById('score-total'),
  totalHint: document.getElementById('score-total-hint'),
  ratio: document.getElementById('score-ratio'),
  ratioHint: document.getElementById('score-ratio-hint'),
  fair: document.getElementById('score-fair'),
  fairHint: document.getElementById('score-fair-hint'),
  recency: document.getElementById('score-recency'),
  recencyHint: document.getElementById('score-recency-hint'),
};
const gridTable = document.getElementById('grid-table');

let currentJobId = null;
let eventSource = null;
let latestResult = null;

const PHASE_LABELS = {
  [AUTO_PHASE_INIT()]: '初始化',
  [AUTO_PHASE_SEED_REST()]: '铺休息周期',
  [AUTO_PHASE_FIX_ON_DUTY()]: '在岗校正',
  [AUTO_PHASE_ASSIGN_MID1()]: '按比例分配',
  [AUTO_PHASE_IMPROVE()]: '思考优化',
  [AUTO_PHASE_FINALIZE()]: '收尾整理',
};

function AUTO_PHASE_INIT() { return 'init'; }
function AUTO_PHASE_SEED_REST() { return 'seed_rest'; }
function AUTO_PHASE_FIX_ON_DUTY() { return 'fix_on_duty'; }
function AUTO_PHASE_ASSIGN_MID1() { return 'assign_mid1'; }
function AUTO_PHASE_IMPROVE() { return 'improve'; }
function AUTO_PHASE_FINALIZE() { return 'finalize'; }

const STEP_ORDER = [
  AUTO_PHASE_INIT(),
  AUTO_PHASE_SEED_REST(),
  AUTO_PHASE_FIX_ON_DUTY(),
  AUTO_PHASE_ASSIGN_MID1(),
  AUTO_PHASE_IMPROVE(),
  AUTO_PHASE_FINALIZE(),
];

initSteps();

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (startBtn.disabled) {
    return;
  }
  resetUi();
  startBtn.disabled = true;
  statusPill.textContent = '提交中';
  try {
    const payload = buildPayload(new FormData(form));
    const res = await fetch('/auto/api/auto_schedule.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.msg || '启动失败');
    }
    const data = await res.json();
    currentJobId = data.job_id;
    statusPill.textContent = '已排队 · 思考中…';
    listenProgress(currentJobId);
  } catch (err) {
    console.error(err);
    statusPill.textContent = `失败: ${err.message}`;
    startBtn.disabled = false;
  }
});

applyBtn.addEventListener('click', async () => {
  if (!currentJobId || !latestResult) return;
  applyBtn.disabled = true;
  statusPill.textContent = '应用中…';
  try {
    const res = await fetch('/auto/api/auto_schedule_apply.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ job_id: currentJobId }),
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.msg || '应用失败');
    }
    statusPill.textContent = `已应用 · ${data.applied_ops} 处更新`;
  } catch (err) {
    statusPill.textContent = `应用失败: ${err.message}`;
    applyBtn.disabled = false;
  }
});

function initSteps() {
  stepList.innerHTML = '';
  STEP_ORDER.forEach((phase) => {
    const el = document.createElement('div');
    el.className = 'step';
    el.dataset.phase = phase;
    el.textContent = PHASE_LABELS[phase] || phase;
    stepList.appendChild(el);
  });
}

function buildPayload(formData) {
  const teamId = Number(formData.get('team_id'));
  const startDate = formData.get('start_date');
  const endDate = formData.get('end_date');
  const thinkMinutes = clampNumber(formData.get('think_minutes'), 1, 120, 5);
  const minOnDuty = clampNumber(formData.get('min_on_duty'), 1, 999, 3);
  const historyMin = clampNumber(formData.get('history_min'), 1, 120, 30);
  const historyMax = clampNumber(formData.get('history_max'), historyMin, 180, 90);
  const ratioWhite = clampNumber(formData.get('ratio_white'), 0, 100, 70);
  const ratioMid = clampNumber(formData.get('ratio_mid1'), 0, 100, 30);
  const ratioSum = ratioWhite + ratioMid || 100;

  return {
    team_id: teamId,
    start_date: startDate,
    end_date: endDate,
    think_minutes: thinkMinutes,
    min_on_duty: minOnDuty,
    history_days_min: historyMin,
    history_days_max: historyMax,
    target_ratio: {
      '白': ratioWhite / ratioSum,
      '中1': ratioMid / ratioSum,
    },
    holidays: parseLines(formData.get('holidays')),
    employees: parseEmployees(formData.get('employees')),
  };
}

function parseLines(value) {
  if (!value) return [];
  return String(value)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
}

function parseEmployees(value) {
  const lines = parseLines(value);
  return lines
    .map((line) => {
      const [idPart, label] = line.split(':');
      const id = Number(idPart.trim());
      if (!Number.isFinite(id) || id <= 0) return null;
      return { id, label: label ? label.trim() : '' };
    })
    .filter(Boolean);
}

function clampNumber(raw, min, max, fallback) {
  const value = Number(raw);
  if (!Number.isFinite(value)) return fallback;
  return Math.min(max, Math.max(min, value));
}

function listenProgress(jobId) {
  closeSource();
  renderProgress(0, AUTO_PHASE_INIT(), '任务已排队');
  timeline.innerHTML = '';
  eventSource = new EventSource(`/auto/api/auto_schedule_progress.php?job_id=${encodeURIComponent(jobId)}`);
  eventSource.addEventListener('progress', (evt) => {
    try {
      const data = JSON.parse(evt.data);
      const pct = Number(data.progress ?? 0);
      const phase = data.phase || AUTO_PHASE_INIT();
      renderProgress(pct, phase, data.note || '');
      appendTimeline(data);
      if (typeof data.score === 'number') {
        renderScore(data.score);
      }
    } catch (err) {
      console.error('progress parse error', err);
    }
  });
  eventSource.addEventListener('result', (evt) => {
    try {
      const payload = JSON.parse(evt.data);
      latestResult = payload.result;
      if (payload.result?.metrics) {
        renderMetrics(payload.result.metrics);
      }
      if (payload.result?.grid) {
        renderGrid(payload.result.grid);
      }
      renderViolations([...(payload.violations || []), ...((payload.result && payload.result.violations) || [])]);
      statusPill.textContent = '已生成方案';
      applyBtn.disabled = false;
      startBtn.disabled = false;
      renderProgress(1, AUTO_PHASE_FINALIZE(), '完成');
      closeSource();
    } catch (err) {
      console.error('result parse error', err);
      statusPill.textContent = '结果解析失败';
      startBtn.disabled = false;
      closeSource();
    }
  });
  eventSource.onerror = () => {
    statusPill.textContent = '连接中断';
    closeSource();
    startBtn.disabled = false;
  };
}

function closeSource() {
  if (eventSource) {
    eventSource.close();
    eventSource = null;
  }
}

function renderProgress(progress, phase, note) {
  const pct = Math.max(0, Math.min(1, progress));
  progressBar.style.width = `${(pct * 100).toFixed(1)}%`;
  stepList.querySelectorAll('.step').forEach((el) => {
    el.classList.toggle('active', el.dataset.phase === phase);
  });
  if (note) {
    statusPill.textContent = `${PHASE_LABELS[phase] || phase} · ${note}`;
  } else {
    statusPill.textContent = PHASE_LABELS[phase] || phase;
  }
}

function appendTimeline(eventData) {
  if (!eventData.phase) return;
  const chip = document.createElement('span');
  chip.className = 'timeline-chip';
  const pct = eventData.progress ? `${Math.round(eventData.progress * 100)}%` : '--';
  chip.textContent = `${PHASE_LABELS[eventData.phase] || eventData.phase} · ${pct}`;
  timeline.appendChild(chip);
}

function renderScore(score) {
  const card = scoreCards.total;
  const value = scoreCards.total.querySelector('.score-value');
  value.textContent = score.toFixed(3);
  card.classList.add('glow');
  setTimeout(() => card.classList.remove('glow'), 400);
}

function renderMetrics(metrics) {
  const { score, components, actual_ratio: actualRatio, work_total: workTotal, mid1_total: mid1Total } = metrics;
  scoreCards.total.querySelector('.score-value').textContent = score ? score.toFixed(3) : '--';
  scoreCards.totalHint.textContent = `总班次 ${workTotal ?? 0}，中班 ${mid1Total ?? 0}`;
  scoreCards.ratio.querySelector('.score-value').textContent = components?.ratio ? components.ratio.toFixed(3) : '--';
  scoreCards.ratioHint.textContent = `实际 ${formatPercent(actualRatio)}`;
  scoreCards.fair.querySelector('.score-value').textContent = components?.fairness ? components.fairness.toFixed(3) : '--';
  scoreCards.fairHint.textContent = '越高越均衡';
  scoreCards.recency.querySelector('.score-value').textContent = components?.recency ? components.recency.toFixed(3) : '--';
  scoreCards.recencyHint.textContent = '越高代表轮转更平滑';
}

function renderGrid(grid) {
  const days = grid.days || [];
  const rows = grid.rows || [];
  const thead = gridTable.querySelector('thead');
  const tbody = gridTable.querySelector('tbody');
  thead.innerHTML = '';
  tbody.innerHTML = '';

  const headerRow = document.createElement('tr');
  const thName = document.createElement('th');
  thName.textContent = '员工';
  headerRow.appendChild(thName);
  days.forEach((day) => {
    const th = document.createElement('th');
    th.innerHTML = `<div>${day.date}</div><div style="font-size:11px;opacity:0.75;">周${day.weekday}</div>`;
    headerRow.appendChild(th);
  });
  thead.appendChild(headerRow);

  rows.forEach((row) => {
    const tr = document.createElement('tr');
    const nameCell = document.createElement('td');
    nameCell.textContent = row.label || row.name || `#${row.emp_id}`;
    tr.appendChild(nameCell);
    (row.cells || []).forEach((cell) => {
      const td = document.createElement('td');
      td.textContent = cell.value || '';
      if (cell.value === '休息') {
        td.classList.add('rest');
      } else if (cell.value === '中1') {
        td.classList.add('mid1');
      }
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
}

function renderViolations(list) {
  const items = Array.isArray(list) ? list.filter(Boolean) : [];
  if (items.length === 0) {
    violationsBox.classList.remove('active');
    violationsList.innerHTML = '';
    return;
  }
  violationsBox.classList.add('active');
  violationsList.innerHTML = '';
  items.forEach((item) => {
    const li = document.createElement('li');
    li.textContent = item.message || item;
    violationsList.appendChild(li);
  });
}

function resetUi() {
  closeSource();
  latestResult = null;
  renderProgress(0, AUTO_PHASE_INIT(), '');
  renderMetrics({ score: 0, components: {} });
  renderGrid({ days: [], rows: [] });
  renderViolations([]);
  applyBtn.disabled = true;
}

function formatPercent(value) {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return '--';
  }
  return `${(value * 100).toFixed(1)}%`;
}

window.addEventListener('beforeunload', () => {
  closeSource();
});
