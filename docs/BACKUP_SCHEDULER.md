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

### 4.1 快照与备份计划任务表 (`instance_snapshot_schedules`)
```sql
CREATE TABLE IF NOT EXISTS `instance_snapshot_schedules` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `instance_id` BIGINT UNSIGNED NOT NULL COMMENT '所属云实例ID',
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '所属用户ID',
    `type` ENUM('snapshot', 'backup') NOT NULL DEFAULT 'snapshot' COMMENT '操作类型: snapshot快照 / backup备份',
    `frequency` ENUM('daily', 'interval_days') NOT NULL DEFAULT 'daily' COMMENT '执行频率',
    `cron_time` TIME NOT NULL DEFAULT '03:00:00' COMMENT '执行时间点 (时:分:秒)',
    `interval_days` INT NOT NULL DEFAULT 1 COMMENT '间隔天数 (1为每天, 3为每3天)',
    `retention_count` INT NOT NULL DEFAULT 3 COMMENT '保留最新份数 (必须 <= 商品最大配额)',
    `allow_risky_single_slot_purge` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '配额仅1份时是否允许先删后建高风险轮转(默认0安全暂停并告警)',
    `last_risky_purge_at` TIMESTAMP NULL COMMENT '最近一次单槽位高风险轮转时间',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用, 0暂停',
    `last_run_at` TIMESTAMP NULL COMMENT '最近一次触发执行时间',
    `next_run_at` TIMESTAMP NOT NULL COMMENT '下一次触发执行时间 (调度轮询使用)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_scheduler_poll` (`status`, `next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='快照与备份自主计划任务表';
```
