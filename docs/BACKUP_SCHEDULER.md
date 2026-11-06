# JianYun (简云) · 云快照与备份自主计划任务调度规范 (Backup & Snapshot Scheduler)

## 1. 架构定位：系统自建自主调度器

- **脱离上游脆弱计划任务**：许多异构上游系统（如第三方魔方代理、Easypanel、自建 PVE 等）要么不提供自动快照计划任务，要么其上游定时脚本缺乏失败重试、不可视、状态易死锁。
- **简云全局管控**：快照与备份的**定时规则、触发时钟、滚动清理与执行流水线 100% 由简云系统自主实现**。简云仅在执行时刻，通过各节点的驱动插件（`ProviderDriver`）调用上游的标准原子 API（创建快照、查询快照、删除快照）。

---

## 2. 计划任务规则配置模型

用户可在支持快照/备份的实例控制面板中，自定义创建自动化规则：

1. **执行周期与时间点**：
   - **每日定时**：每天固定时刻（如每日凌晨 `03:00`）。
   - **间隔天数定时**：每隔 $N$ 天（如每隔 3 天）的固定时刻执行。
2. **配额与保留份数约束**：
   - **商品物理上限（Physical Max）**：从商品配置项 `products.config_cache` 或节点策略中读取（例如：该机型最多支持 4 份快照）。
   - **用户自定义保留份数（Retention Count）**：用户设置保留最新 $K$ 份（必须满足 $1 \le K \le \text{Physical Max}$，例如设置保留最新的 3 份）。

---

## 3. 滚动清理与先后顺序（双阈值全额槽位调度模型）

> [!IMPORTANT]
> **本调度逻辑已全面升级为与 [docs/LIFECYCLE_PRICING.md](LIFECYCLE_PRICING.md) 完全一致的双阈值全额配额模型**。  
> 废止一律“先删后建”的旧口径，实行“槽位有余先创后删、槽位已满先删后创、单槽位默认安全暂停熔断”的三阶安全保护：

1. **槽位全额统计**：
   - 当前已用物理槽位 $TotalUsed = ManualSnapshots + AutoSnapshots$；
   - 物理上限为 $M$，用户设定保留份数为 $K$。
2. **三阶执行分支**：
   - **分支 A (单槽位危险保护 $M = 1$)**：若用户机型仅有 1 个物理快照槽位且已被占用，系统**默认自动暂停任务 status=0 并产生安全告警**，绝不删除唯一现有恢复点；仅当用户在配置中显式勾选 `allow_risky_single_slot_purge = 1` 时才执行授权先删后建。
   - **分支 B (物理槽位仍有空余 $TotalUsed < M$)**：实行【安全优先：先创后删】。先调用上游创建新快照，异步确认达到可用状态后，再清理属于本计划名下超过 $K$ 份的老快照。
   - **分支 C (物理槽位已满 $TotalUsed \ge M$ 且 $M > 1$)**：仅清理属于当前计划任务的 `Auto_` 老快照（绝对禁止删除任何 `Manual_` 用户手动快照），确认腾出槽位后，再创建新快照。

### 关键状态安全防护：
1. **删除动作 Outbox 任务化**：所有快照删除操作均派发 Outbox `snapshot_delete` 任务，带有唯一防重幂等键 `snapshot_delete:{remote_id}`，若删除失败自动退避重试，防止上游配额泄漏；
2. **高风险轮转审计**：命中单槽位 `allow_risky_single_slot_purge = 1` 执行先删后建时，调度器自动记录 `last_risky_purge_at = NOW()`；
3. **防并发互斥排他 (Task-as-Lock)**：当实例名下存在其他正在执行的 Outbox 任务时，调度器自动延后 15 分钟重试。

---

## 4. 数据库表结构规范

> [!NOTE]
> **DDL 单一事实来源**：本模块两张表的完整字段定义以 `database/schema.sql` 为唯一权威（字典释义见 [DATABASE.md](DATABASE.md)），本规范不再内嵌 DDL 副本，避免双源漂移：
> - `instance_snapshot_schedules`（DATABASE.md §1.8）：计划任务规则与调度轮询字段（`next_run_at`、`last_risky_purge_at`、`allow_risky_single_slot_purge` 等）；
> - `instance_snapshots`（DATABASE.md §1.9）：快照/备份归属凭证台账。每次创建成功后必须将上游返回的 `remote_snapshot_id` 与本计划 `schedule_id` 关联落库；Outbox 清理任务幂等键 `snapshot_delete:{remote_snapshot_id}` 即以该唯一键为依据，滚动清理候选严格按 `schedule_id` 查询（详见 [LIFECYCLE_PRICING.md](LIFECYCLE_PRICING.md) §5.2/§5.3）。
