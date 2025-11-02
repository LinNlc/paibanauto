# 自动排班模块

`/auto` 目录提供一个与现有排班系统解耦的自动排班模块，支持配置思考时间、年度休息周期硬约束、SSE 实时进度以及对比 diff 后一键落库。模块会生成完整的候选方案，并仅以 diff 形式返回变更，避免直接覆盖排班真相表。

## 目录结构

```
auto/
├── api/
│   ├── auto_schedule.php          # 创建自动排班任务
│   ├── auto_schedule_progress.php # SSE 进度流
│   └── auto_schedule_apply.php    # 手动应用 diff
├── bin/
│   └── run_job.php                # 后台执行器（CLI）
├── public/
│   ├── auto-scheduler.html        # 悬浮窗页面
│   └── auto-scheduler.js          # 前端逻辑与渲染
├── schema/
│   └── auto.sql                   # 新表定义
├── _lib.php                       # 模块级公共函数
├── engine.php                     # 排班算法核心
└── README.md
```

## 初始化

1. **创建新表**：在同一 SQLite 数据库中执行 `auto/schema/auto.sql`。
   ```bash
   sqlite3 data/schedule.db < auto/schema/auto.sql
   ```
2. **可选：造历史假数据**，确保 `schedule_cells` 中存在最近 30~90 天的班次记录，便于算法推导历史轮转和休息类型。

## 最小可跑路径

1. 启动内置或外部 Web 服务，确保 `/auto/public/auto-scheduler.html` 可访问。
2. 以具有排班编辑权限的账号登录系统。
3. 打开悬浮窗页面，填写以下信息：
   - 团队 ID、日期范围。
   - 思考时间（分钟），范围 1~120。
   - 最少在岗人数，以及白班/中1 目标比例。
   - 历史窗口（天），默认 30~90。
   - 节假日列表（逐行填写），以及需参与排班的员工 ID（逐行输入 `ID` 或 `ID:备注`）。
4. 点击 “开始自动排班” 触发任务。接口立即返回 `job_id`，后台执行器由 `auto/bin/run_job.php` 在 CLI 下处理。前端会通过 `/auto/api/auto_schedule_progress.php` 建立 SSE 连接，实时展示阶段、进度条和评分跳动。
5. 任务完成后会返回：
   - `grid`：周视图预览（仅白/中1/休息）。
   - `diff_ops`：相对于真相表的差异集合。
   - `metrics`：比例、均衡、轮转间隔、集中度评分以及总分。
   - `violations`：无法满足的硬约束或提醒信息（例如在岗人数不足）。
6. 需要写入时，点击 “应用方案” 调用 `/auto/api/auto_schedule_apply.php`。接口会使用 `auto_apply_diff()` 落库，并同步更新 `rest_cycle_ledger` 与 `shift_debt_ledger`。

## 运行说明

- **思考时间**：算法会在截止时间内持续局部搜索，每 ~200ms 写入 `auto_jobs.events_json` 以驱动前端动画；思考时间越长，评分通常越高。
- **硬约束**：
  - 连续上班 ≤ 6 天（节假日上班不计入连续天数）。
  - 连续休息 ≤ 3 天。
  - 每日实到 ≥ `min_on_duty`。
  - 本季休息对必须来自指定集合（workdays: 12/23/34，weekend: 56/71）。
- **软目标**：围绕白班/中1 比例、个人均衡、轮转间隔、日内集中度，采用加权总分。
- **差异落库**：所有写操作均通过 diff 完成。若需自动应用，可在请求体中携带 `"apply": true`，系统会在后台任务结束后自动落库（前提是创建者仍具备编辑权限）。

## 自检 Checklist

- [ ] 执行 `auto/schema/auto.sql` 后四张新表均存在。
- [ ] `/auto/api/auto_schedule.php` 能返回 `job_id`，CLI 执行器正常启动。
- [ ] `/auto/api/auto_schedule_progress.php` SSE 事件流包含阶段、进度、分数字段。
- [ ] 生成的 `grid` 与 `diff_ops` 与现有 `schedule_cells` 对比正确。
- [ ] `/auto/api/auto_schedule_apply.php` 成功写入排班并更新账本。
- [ ] 悬浮窗页面能展示实时进度、评分仪表、预览网格，并高亮 violations。
