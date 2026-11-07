# JianYun (简云) · 云快照与备份自主计划任务调度规范 (Backup & Snapshot Scheduler)

## 1. 架构定位：系统自建自主调度器

- **脱离上游脆弱计划任务**：许多异构上游系统（如第三方魔方代理、Easypanel、自建 PVE 等）要么不提供自动快照计划任务，要么其上游定时脚本缺乏失败重试、不可视、状态易死锁。
- **简云全局管控**：快照与备份的**定时规则、触发时钟、滚动清理与执行流水线 100% 由简云系统自主实现**。调度器只提交 Outbox，由 Worker 通过 [PLUGIN_DEV.md](PLUGIN_DEV.md) 的可选 `SnapshotDriverInterface` 创建、列举、反查和删除快照/备份，禁止绕过统一接口直连上游。
- **能力门禁**：仅当 Provider 声明请求类型对应的 `Feature::SNAPSHOT` / `Feature::BACKUP` 且实现可选接口时，才开放该类型的计划管理；其他驱动无需实现空方法。能力不支持或归属未核准时，服务端拒绝创建计划和任务。

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
   - 当前已用物理槽位 $TotalUsed = ManualSnapshots + AutoSnapshots + OtherSnapshots$，包含上游直接创建及其他计划的对象；必须获取完整远端列表，并保守计入尚未确认释放的创建中、删除中槽位。列表不完整、状态未知或已有调查门禁时延后调度，禁止猜测可用槽位；
   - 物理上限为 $M$，用户设定保留份数为 $K$。
2. **三阶执行分支**：
   - **分支 A (单槽位危险保护 $M = 1$)**：若用户机型仅有 1 个物理快照槽位且已被占用，系统**默认自动暂停任务 status=0 并产生安全告警**，绝不删除唯一现有恢复点；仅当用户在配置中显式勾选 `allow_risky_single_slot_purge = 1` 时才执行授权先删后建。
   - **分支 B (物理槽位仍有空余 $TotalUsed < M$)**：实行【安全优先：先创后删】。先同事务预建台账与创建 Outbox，Worker 确认可用后，再派发属于本计划名下超过 $K$ 份的老快照删除任务。
   - **分支 C (物理槽位已满 $TotalUsed \ge M$ 且 $M > 1$)**：仅按 `schedule_id` 选择当前计划的老快照，绝不以 `Auto_` / `Manual_` 名称判断归属；删除 Outbox 确认腾出槽位后，再派发创建 Outbox。当前计划没有可安全删除对象时暂停并告警，不删除其他计划或手动对象。

### 关键状态安全防护：
1. **创建与删除全部 Outbox 化**：实例行锁内同事务预建 `status='creating'`、`remote_snapshot_id=NULL` 的台账及 `snapshot_create` / `backup_create` 任务，计划幂等键固定为 `{action}:schedule:{schedule_id}:{scheduled_at_UTC_YYYYMMDDHHmmss}`；手动创建为 `{action}:manual:{instance_id}:{client_token}`（限制 token 长度使总键长不超过 100）。同事务推进 `next_run_at`，`tasks.idempotency_key` 冲突必须整笔回滚新台账；重试复用原任务与台账，不新增创建键字段。删除采用 `snapshot_delete` / `backup_delete` 动作，幂等键统一为 `snapshot_delete:{instance_snapshots.id}`。创建与删除均不得在 HTTP 或调度器线程裸调上游；明确可重试错误才退避重试，结果未知须按原始幂等键反查或转人工门禁；
2. **高风险轮转审计**：命中单槽位 `allow_risky_single_slot_purge = 1` 执行先删后建时，调度器自动记录 `last_risky_purge_at = NOW()`；
3. **防并发互斥排他 (Task-as-Lock)**：当实例名下存在执行中、调查中或尚未解除的人工核验门禁时，调度器自动延后 15 分钟重试；租约过期不是放行依据。领取、调查、台账回写遵循 [TASKS_OUTBOX.md](TASKS_OUTBOX.md) 的实例行锁与 Fencing Token 协议。
4. **任务归属与反查**：payload 固化 `snapshot_id`、`provider_id`、`instance_id`、`type`、`remote_instance_id` 与已知 `remote_snapshot_id`。Worker 核对任务、台账、实例、节点后再调用可选接口，终态与台账同事务回写。创建响应丢失仍保留本地台账，通过原始创建幂等键反查真实远端 ID，不能创建第二份替代记录；查不清时阻断轮转。

---

## 4. 数据库表结构规范

> [!NOTE]
> **DDL 单一事实来源**：本模块两张表的完整字段定义以 `database/schema.sql` 为唯一权威（字典释义见 [DATABASE.md](DATABASE.md)），本规范不再内嵌 DDL 副本，避免双源漂移：
> - `instance_snapshot_schedules`（DATABASE.md §1.9）：计划任务规则与调度轮询字段（`next_run_at`、`last_risky_purge_at`、`allow_risky_single_slot_purge` 等）；
> - `instance_snapshots`（DATABASE.md §1.10）：快照/备份归属凭证台账。待建台账允许 `remote_snapshot_id=NULL`，与持稳定业务键的创建任务同事务防重恢复；创建确认后关联真实远端 ID 与本计划 `schedule_id`。远端对象唯一约束为 `(instance_id, type, remote_snapshot_id)`；清理任务以本地台账 ID 防重，候选严格按 `schedule_id` 查询（详见 [LIFECYCLE_PRICING.md](LIFECYCLE_PRICING.md) §5.2/§5.3）。

## 5. 必须通过的演练

| 场景 | 验收门槛 |
|---|---|
| 上游未声明能力、只声明未实现接口、只支持备份却请求快照 | 服务端拒绝，零上游调用、零任务入库 |
| 不同 Provider / 实例 / 类型返回相同远端 ID | 台账各自独立；任务只作用于 payload 与台账共同指向的对象 |
| 同计划同一执行时刻被两个调度器扫描 | 仅一份台账与创建任务；保持原始幂等键 |
| 创建成功后响应丢失、Worker 在回写前退出 | 按原始键调查并回填原台账，不重复创建；不可反查则持续人工门禁 |
| 远端分页失败、删除结果未知、旧 Worker 延迟回写 | 暂停轮转；不按错误计数删恢复点；旧令牌无法提交 |
| 满槽位且无本计划对象、单槽位未授权风险 | 安全暂停并告警，保留所有现有恢复点 |

本节是待实现能力的交付要求，文档与 DDL 修订不构成调度器或真实上游验收通过。
