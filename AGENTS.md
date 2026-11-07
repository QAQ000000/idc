# JianYun (简云) · Agent 协同开发指南与工程宪法

> **项目代号**：JianYun (简云)
> **项目定位**：极简、高扩展的现代化云产品聚合销售系统（支持魔方代理、自营Kangle/Easypanel、Sub2API、手工VPS等多异构上游）。
> **核心原则**：**极简内核、驱动插件化、可靠事务发件箱、绝不复刻历史包袱。**

---

## 一、 项目背景与防坑铁律（必须牢记）

本项目是在复盘了历史 4 款重构失败/半成品项目（`zjmf-manger-decoded`、`goidc`、`goidc2`、`xiaoheiFS`、`caiwu`）的惨痛教训后启动的。**任何 Agent 在编写或重构代码时，必须严格遵守以下铁律：**

### 1. 严禁陷入“第二系统综合症”（绝不 1:1 复刻旧魔方）
- 旧魔方数据库有 178 张表，其中 109 张是完全 0 行数据的僵尸功能（如合同PDF、信用贷、绩效打分、复杂下载等）。
- **铁律**：严禁把旧魔方那些没人用的功能搬过来！系统核心表严格控制在 **8~10 张** 以内。

### 2. 严禁把上游配置项死板拆表（拒绝 4 级树形表）
- 旧魔方 216 行配置项中有 215 行直接来自上游。
- **铁律**：严禁在本地建 `product_config_groups`、`options`、`sub`、`pricing` 4 级死板表！对上游魔方商品采用 **“上游 Schema 透传 + 本地 JSON 快照”**。前端动态渲染表单，下单时原样透传参数。

### 3. 必须采用“事务发件箱（Transactional Outbox）”任务机制
- 旧系统“先调上游接口，后写任务表”，导致网络超时后任务凭空消失，严重漏单。
- **铁律**：扣除余额/标记支付与插入任务，必须在**同一个数据库事务**内完成！先落库 `pending` 任务，再由后台 Worker 异步调用上游，支持指数退避重试与人工干预。

### 4. 自动续费必须“出账”与“代扣”解耦
- 旧代码因为账单重复而在第 48 行粗暴 `return 500`，导致用户有余额也续不上。
- **铁律**：出账器只管到期前 N 天生成唯一账单；代扣器每 15 分钟扫描未付账单，发现余额充足即行锁安全划扣。

### 5. 推荐返佣必须与退款级联冲正（防羊毛党）
- 旧系统退款完全不处理返利，导致被自推自买套现。
- **铁律**：退款时必须同步取消待结算佣金；若已结算，必须触发 Clawback（追回/扣减余额）。

### 6. 前端严禁多端战线过长
- 严禁同时做 3 套独立前端。
- **铁律**：采用一套自适应响应式的现代化管理后台 + 用户控制台（同一前端应用，根据角色路由划分），前台购买页面是免登/公开路由。

### 7. 通知中心必须单渠道指纹幂等（严禁重复轰炸）
- 业务事件（到期提醒、支付成功等）支持同时广播至多渠道（邮件、短信、TG、微信、QQ等）。
- **铁律**：每条消息必须携带业务指纹（`fingerprint`），利用 `UNIQUE KEY (fingerprint, channel)` 在数据库层做物理级防重占位，确保每一个渠道对于同一条消息**绝对只推送一次**，杜绝并发重试刷短信与骚扰客户。

### 8. Web Push 浏览器通知与控制台登录态严格隔离 (安全红线)
- Chrome、Edge、Safari 等浏览器的 Web Push 属于设备级长效推送订阅（通过 Service Worker 监听）。
- **铁律**：**通知订阅凭证 ≠ 控制台登录凭证！** 严禁凭借推送端点/凭据直接免密访问控制台；用户点击浏览器通知唤起网页时，必须严格校验独立的主会话 Cookie/Token，未登录或会话过期必须拦截到登录页。

### 9. 验证码驱动插件化与场景策略隔离
- 系统支持图片输入、一键点击（Turnstile）、滑块拼图（极验）等多种形态，默认内置纯本地图片验证码。
- **铁律**：验证码逻辑与业务接口解耦！通过中间件基于场景（`login`, `register`, `password_reset`）动态读取配置并调用对应驱动二次验签，前端通过通用 `<CaptchaWidget />` 组件基于驱动类型自适应渲染，严禁在业务 Controller 里写死特定验证码 SDK。

### 10. 支付回调必须 CAS 原子防重入，敏感秘钥必须加密
- 第三方支付异步通知存在高频并发重发的必然性。
- **铁律**：账单标记支付必须使用原子更新 `UPDATE invoices SET status='paid' WHERE id=? AND status='unpaid'`。唯有影响行数 > 0 才能触发充值或开机，严禁重复加钱！
- 上游 API Token、支付私钥和主机密码，存库前必须经 AES-256-GCM 密文存储，日志严禁明文打出。

---

## 二、 核心领域模型（8 张核心表，全系统收敛至 26 张物理表）

系统摒弃旧魔方 178 张表的臃肿设计，收敛为 8 张核心业务表 + 18 张支撑/配置/审计/下载表（全库 26 张物理表详见 `docs/DATABASE.md`）。任何 Agent 在设计实体与业务流转时，围绕这 8 张核心表展开：

1. `users`：用户基础信息、钱包可用余额、冻结佣金、密码与认证状态。
2. `providers`：统一上游服务提供方（包含魔方财务、Easypanel、Sub2API、人工运维）。
3. `products`：商品目录定义、绑定 Provider、加价策略、配置项快照 JSON。
4. `instances`：用户已购云资源实例、状态机、到期日、续费价格覆盖（`override_price`）。
5. `invoices`：账单流水（新购、续费、升级补差），唯一索引防重。
6. `transactions`：资金账本变动明细（充值、消费、退款、佣金冲正）。
7. `tasks` (Outbox)：可靠任务队列（开通、续费、升降级、暂停、销毁）。
8. `commissions`：推广返利明细与冻结状态机。

---

## 三、 技术栈与架构基准

- **后端**：PHP 8.2+ (现代框架 / 纯净模块化架构) 或 TypeScript (Node.js)，以清晰的 Domain/Service/Driver 分层设计。
- **数据库**：MySQL 8.0+（严格启用事务与外键/唯一键，金额使用 DECIMAL(10,2)）。
- **缓存与队列**：Redis（用于快照缓存、分布式锁、Worker 队列调度）。
- **前端**：Vue 3 + Vite + TypeScript + 现代组件库（TDesign / Element Plus / Shadcn-vue）。

---

## 四、 统一 Provider（上游驱动）开发规范

所有上游系统对接必须实现统一接口 `UpstreamDriverInterface`（详见 `docs/PLUGIN_DEV.md`）：

```php
interface UpstreamDriverInterface
{
    // 声明驱动支持的能力集与反查权威度级别
    public function getSupportedFeatures(): array;
    public function getQueryAuthority(): string; // 'AUTHORITATIVE_REALTIME' | 'EVENTUAL_CONSISTENCY' | 'NON_QUERYABLE'

    // 获取规格配置定义（供购买页渲染动态表单）
    public function getFormSchema(int $remotePid, array $credentials): array;

    // 算价预检（下单前校验库存与最终价格）
    public function preflightPrice(int $remotePid, array $selectedConfig, array $credentials): PreflightResult;

    // 核心生命周期动作 (必须支持幂等)
    public function create(array $orderData, array $credentials): DriverExecutionResult;
    public function renew(string $remoteInstanceId, string $targetDueDate, array $credentials): DriverExecutionResult;
    public function suspend(string $remoteInstanceId, string $reason, array $credentials): DriverExecutionResult;
    public function unsuspend(string $remoteInstanceId, array $credentials): DriverExecutionResult;
    public function terminate(string $remoteInstanceId, array $credentials): DriverExecutionResult;
    public function resize(string $remoteInstanceId, array $targetConfig, array $credentials): DriverExecutionResult;

    // 状态反查与幂等对账 (用于超时后结果未知恢复，严禁盲目重试)
    public function queryOrder(string $idempotencyKey, array $credentials): DriverExecutionResult;
    public function syncStatus(string $remoteInstanceId, array $credentials): InstanceStatusSnapshot;

    // 控制台运维动作
    public function power(string $remoteInstanceId, string $action, array $credentials): DriverExecutionResult;
    public function getVnc(string $remoteInstanceId, array $credentials): DriverExecutionResult;
    public function reinstall(string $remoteInstanceId, string $osId, string $password, array $credentials): DriverExecutionResult;
}
```

---

## 五、 Agent 编码实施工作流

1. **先阅读设计文档**：先查看 `docs/ARCHITECTURE.md` 与 `docs/DATABASE.md`。
2. **测试驱动/小步验证**：每实现一个 Service 或 Driver，必须附带最小化单元/集成测试。
3. **保持提交原子性**：遵循规范的 Conventional Commits（`feat:`, `fix:`, `refactor:`, `test:`）。
