# JianYun (简云) · 研发路线图与交付里程碑 (Milestones)

本文档规划了 JianYun 项目的工程实施路径。每个 Milestone 必须跑通端到端测试并形成可演示成果，严禁脱离实际运行盲目推进。

---

## 路线图总览

```text
Milestone 1: 极简内核与单链路闭环 (MVP)
     ↓
Milestone 2: 驱动插件体系、魔方代理与三方支付网关 (Drivers & Payments)
     ↓
Milestone 3: 事务发件箱、双阶段自动续费与快照调度 (Reliable Engine)
     ↓
Milestone 4: 实例运维控制台、变配改价与客服工单 (Ops, Billing & Tickets)
     ↓
Milestone 5: 返佣防套利、营销发券、通知中心与生产割接 (Growth & Migration)
```

---

## 阶段一：极简内核与单链路闭环 (MVP)
> **目标**：用户注册登录、浏览一个模拟商品、生成账单、模拟付款成功、落库待交付任务。

- [ ] **任务 1.1**：初始化后端基础框架（REST API 结构、统一返回格式、数据库连接池与迁移运行器）。已完成原生 PHP API 入口、PDO 连接与空库初始化；连接池与增量迁移运行器尚未实现。
- [x] **任务 1.2**：实现 `users` 表鉴权（JWT / Bearer Token 机制），包含密码哈希、登录、注册。
- [x] **任务 1.3**：实现基础 `invoices` 与 `transactions` 资金扣减事务服务。
- [x] **任务 1.4**：实现 `tasks` 事务发件箱基础模型（同事务写入）及 `InstanceActivationService::completeCreate()` 交付激活起租。
- [x] **验收标准**：通过 API 自动化测试，模拟完成一次“注册 $\rightarrow$ 充值余额 $\rightarrow$ 购买 $\rightarrow$ 扣款 $\rightarrow$ 任务入库 $\rightarrow$ 交付起租”的完整事务链路。充值由仅本地/测试可用的 CLI 模拟，真实上游交付未包含在本阶段。

---

## 阶段二：驱动插件体系、魔方代理与三方支付网关 (Drivers & Payments)
> **目标**：打通与真实上游魔方（SakuraIDC / 创峰等）的 API 代理及第三方支付网关（支付宝当面付 / 易支付），实现配置透传、异步回调收单与自动开通。

- [ ] **任务 2.1**：定义统一 `App\Plugins\Providers\UpstreamDriverInterface` 与 `App\Plugins\Payments\PaymentGatewayInterface` 抽象规范。
- [ ] **任务 2.2**：实现 `ZjmfProxyDriver`（对接智简魔方开放 API）：
  - `getFormSchema`：拉取魔方配置项并缓存为 JSON 快照。
  - `preflightPrice`：下单前向魔方预检校验价格与库存。
  - `create`：向魔方提交新购开通。
- [ ] **任务 2.3**：实现自营插件驱动与三方支付网关插件：
  - `Sub2ApiDriver`：AI 额度兑换码分发交付。
  - `ManualAdminDriver`：人工运维待开通工单流。
  - `EasypanelDriver` 与 `SkymnbtDriver`：分别覆盖存量虚拟主机和宝塔节点；逐节点核准远端 ID，逐项验收续费、状态查询与该类产品实际支持的运维动作，不能将“导入成功”当作驱动可用。无法提供的能力不得声明，需显式配置人工接管人、处理入口和响应时限。
  - `AlipayF2FDriver` & `EpayDriver`：收银台二维码/跳转生成、异步回调验签 CAS 入账、迟到款自动转余额（`late_payment_credit`）。
- [ ] **任务 2.4**：统一单前端工程（`web/`）初始化与选购组件 `<ConfigOptionRenderer />` 动态解析 JSON 渲染滑块/单选。
- [ ] **任务 2.5（真实交付前置门禁）**：实现最小可靠 Worker 与 Driver 注册器。先锁实例、带 `execution_token` / `lease_until` 领取任务，网络调用置于事务外，任务终态与交付激活在 Fencing Token 同事务提交；失联或结果未知必须进入调查/人工核验门禁，阻断同实例新动作及通用重试，核验已交付后仅回填。具备基本崩溃接管与告警后才能开启真实驱动，严禁以 HTTP 同步调用上游绕过本任务。
- [ ] **验收标准**：在测试环境下，前端选择配置下单并通过支付回调入账，由任务 2.5 Worker 真实调用上游魔方接口开通 VPS，实例表记录正确的 IP、远端 ID 与交付起止租期；并演练响应丢失、旧 Worker 延迟回写、不可反查人工门禁，证明不重复购买且旧令牌不能覆盖结果。存量节点的能力验收与人工接管清单同步留档。

---

## 阶段三：事务发件箱、双阶段自动续费与快照调度 (Reliable Engine)
> **目标**：彻底消灭“漏单、吞单”和“有钱不续费停机”两大历史 Bug，落地自主快照备份计划任务。

- [ ] **任务 3.1**：在任务 2.5 已可安全交付的 Worker 上扩展恢复与调度能力（基于 Redis 唤醒或轻量定时扫描）：
  - 完善分批领取、长任务心跳、多 Worker 公平调度与崩溃接管；继续使用同一套实例行锁、租约、Fencing Token 和人工核验门禁。
  - 按驱动权威度自动反查 `investigating` 任务，最终一致性观察窗口内不将 404 当作失败，明确可重试错误安排指数退避（5m, 15m, 30m）。
  - 达到最大重试次数或 `NON_QUERYABLE` 超时自动熔断报警，未知结果保持人工门禁；后台提供受门禁约束的【一键重试】、`super_admin` 强制重试与人工交付回填审计接口。
- [ ] **任务 3.2**：实现双阶段自动续费守护器与续费/销毁互斥（SC-L01 ~ SC-L05）：
  - 出账器：提前 7 天扫描生成唯一续费账单（`uk_cycle_instance` 防重，取消/退款时释放 `cycle_marker = NULL`）。
  - 代扣器：充值事件秒级唤醒 + 每 15 分钟定时兜底扫描未付账单，余额充足即行锁扣款、按原 `due_date` 顺延账期并生成单条 `renew` 任务；仅欠费暂停时在 payload 固化 `needs_unsuspend=true`，Worker 在同一组合履约中先续费再解挂，确认成功后原子恢复活跃，禁止拆成可乱序领取的 `renew` / `unsuspend` 两条任务。
- [ ] **任务 3.3**：实现可选 `SnapshotDriverInterface` 与快照/备份调度器（见 `docs/PLUGIN_DEV.md`、`docs/BACKUP_SCHEDULER.md`）：能力声明/接口实现双重门禁，按实例及类型隔离远端 ID，台账预创建与创建/删除 Outbox 同事务防重；实现完整列举、双阈值配额、原始创建键反查和删除归属校验，并通过跨节点 ID 重号、并发触发、响应丢失及单槽位保护演练。
- [ ] **验收标准**：模拟上游网络中断与租约超时，Worker 能安全进入 `investigating` 反查恢复且不重复购买；模拟用户提前充值与临期充值，均能准确秒级代扣并顺延原 `due_date`。

---

## 阶段四：实例运维控制台、变配改价与客服工单 (Ops, Billing & Tickets)
> **目标**：完善客户日常高频操作、后台运维改价补差神器、原路退款状态机与自建客服工单协同。

- [ ] **任务 4.1**：客户控制台实例管理功能：
  - 电源操作：开机、关机、重启。
  - 重装系统：拉取镜像列表并执行重装。
  - 在线 Web VNC：免密透传远程控制台。
- [ ] **任务 4.2**：弹性云动态升降级引擎：
  - 升级按剩余天数折算差价（先乘后除 `scale=6` + `bc_round`），付完款调上游变配。
  - 降级按“0 元不退款”通过 Outbox 异步执行，自动下调下次续费价，并同事务作废在途未付续费单。
- [ ] **任务 4.3**：后台管理员改价弹窗与原路退款闭环：
  - 支持手动输入新的续费价格（更新 `override_price` 并作废在途未付续费单），自动折算当期差价并支持从余额扣除。
  - 按 `PAYMENTS_LEDGER.md` 的退款用途实现状态机：`cloud_consumption` 不预扣钱包，仅在正常云消费退订成功时冲消费/佣金并派发 `terminate`；`wallet_recharge` 处理对应凭证、实际钱包预扣及自身独立充值主账单的冻结/退款终态，拒绝时按上下文恢复该充值账单；`late_wallet_credit` 只处理对应凭证与实际钱包预扣，父账单始终只读。两类钱包款退款均不改实例/消费/佣金、不派销毁任务。网关结果未知单向反查；拒绝时只有存在实际预扣及流水证明才按原额执行 `refund_rollback`，无预扣的云消费拒绝仅恢复本次实际改变的状态，不能凭空补钱。
- [ ] **任务 4.4**：后台“以该客户身份登录（Login as Client）”运维接口与自建客服工单体系（`tickets` & `ticket_replies` 内部私有便签、跨部门转派）。
- [ ] **验收标准**：全流程测试变配补差计算精度无误；后台手工改价后在途旧续费单自动失效且下期按新价格出账；模拟登录读写风控与工单内部便签隔离生效。

---

## 阶段五：返佣防套利、营销发券、通知中心与生产割接 (Growth & Migration)
> **目标**：防羊毛党闭环，完善多渠道通知与 SEO/下载中心，平滑导入正式站 3,363 个客户与存活实例。

- [ ] **任务 5.1**：实现推广返利冻结与三轨分户账本结算状态机（排除 `recharge` 账单，15 天冻结期、`frozen_commission` $\rightarrow$ `available_commission` 双分录流转、双通道提现）。
- [ ] **任务 5.2**：实现正常云消费（`cloud_consumption`）退款成功终态 CAS 同事务级联冲正；迟到/多收钱包款与独立充值退款不得冲原佣金（见 [PAYMENTS_LEDGER.md](PAYMENTS_LEDGER.md) §3）：
  - 冻结期内退款：立即作废佣金并冲减 `frozen_commission`。
  - 已结算退款：触发 Clawback 冲减 `available_commission`（允许扣为负数阻断提现）。
- [ ] **任务 5.3**：实现实名认证法定生日解析、三大营销发券命令（`SendRegisterGifts` / `SendBirthdayGifts` / `SendWinBackCoupons`）、单渠道指纹幂等通知中心（含 Web Push 隔离）及 SEO 文章/安全下载中心（`X-Accel-Redirect`）。
- [ ] **任务 5.4**：编写旧数据库（`idc2`）平滑迁移与对账稽核脚本（遵循 `docs/MIGRATION_RUNBOOK.md`）：
  - 显式分离导入 `admins` 与 3,363 位 `users` 的密码哈希与可用余额。
  - 前置导入 `providers` / `products`，严格零伪造导入 118 台 Active 与 22 台 Suspended 实例及 2 台待人工核办 Pending 实例。
  - 先部署旧支付回调持久接收与协议桥接：验签、加密原始回调私有存储、旧商户订单/账单映射、幂等重放与逐笔确认对账；覆盖 11 笔存量未付订单，未知映射先隔离核办，不得简单重定向回调、复用实例任务或伪造新账单。
  - 按 `MIGRATION_RUNBOOK.md` 的生产门禁完成演练：回调桥接未实现、存量驱动逐能力未验收或人工接管未获确认，均不得开放生产交易。
- [ ] **验收标准**：完成数据迁移与全平对账，旧账号可登录（自动热升级 Bcrypt）并查看实例；每类存量节点在已声明能力内通过续费及实际支持的运维动作验收。缺失能力必须由明确的人工接管流程承接，禁止宣称所有迁移实例均可自动重启/续费；旧回调重复、迟到、映射缺失和接收/重放崩溃演练均无吞款或重复入账。

## 蓝图映射与交付依赖

| 阶段 | 权威设计分片 | 必须先满足的跨模块依赖 |
|---|---|---|
| M1 内核 | `ARCHITECTURE.md`、`DATABASE.md`、`API.md`、`CAPTCHA.md`、`PAYMENTS_LEDGER.md` | 账单、账本与 Outbox 同事务；开发模拟交付不能充当真实上游验收 |
| M2 驱动与真实收单 | `PLUGIN_DEV.md`、`PAYMENTS.md`、`TASKS_OUTBOX.md`、`FRONTEND.md` | 任务 2.5 安全 Worker 先于真实交付启用；存量 Provider 能力清单先于迁移承诺 |
| M3 可靠调度 | `TASKS_OUTBOX.md`、`LIFECYCLE_PRICING.md`、`BACKUP_SCHEDULER.md` | 复用 M2 Worker；快照可选接口、台账与 Outbox 全链路验收 |
| M4 运维与退款 | `API.md`、`LIFECYCLE_PRICING.md`、`PAYMENTS_LEDGER.md`、`TICKETS.md` | 复用任务门禁、账本与审计，禁止旁路厂商调用 |
| M5 增长与割接 | `AFFILIATE.md`、`MARKETING_ENGINE.md`、`NOTIFICATIONS.md`、`SEO.md`、`MIGRATION_RUNBOOK.md` | 佣金冲正、通知防重、旧回调桥接、存量能力/人工接管逐项通过后方可生产割接 |

新增或修正设计契约仍属于待实现工作，以上未勾选任务不得因文档补齐而改为已完成。
