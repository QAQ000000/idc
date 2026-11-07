# JianYun (简云) · 模块化插件与驱动开发手册 (Plugin Development Guide)

本文档是系统扩展插件（上游云产品驱动、支付网关、消息通知通道、验证码插件）的标准开发规范与 SDK 手册。**任何开发者或 Agent 编写新插件，必须严格遵循本文档约定。**

**已确认框架底座**：当前运行环境为 PHP 8.4 + Laravel 13，M0 已完成现有 MVP 的框架适配。Laravel 容器负责装配契约和注册器，具体插件仍位于 `plugins/`，核心业务不直接引用具体实现。框架认证、加密及后台队列组件不能绕过领域事务、密文兼容和未知结果核验，见 [TECH_STACK.md](TECH_STACK.md)。

**外置旧站插件不属于本插件体系**：旧站迁移插件独立发布并上传到旧系统，按旧系统的插件机制运行，输出简云目标版本的 SQL 数据库备份及校验材料。它不安装到简云 `plugins/`，不新增核心迁移模块或插件类型；目标恢复与割接验收遵循 [MIGRATION_RUNBOOK.md](MIGRATION_RUNBOOK.md)。

---

## 一、 插件类型与目录规范

所有扩展插件统一按类型组织在 `plugins/` 目录下，禁止在核心代码目录中硬编码第三方业务：

```text
plugins/
├── providers/           # 上游服务提供方驱动 (对接魔方、Easypanel、Sub2API、PVE 等)
│   ├── zjmf/            # 魔方财务/DCIM API 代理驱动
│   ├── easypanel/       # Kangle/Easypanel 虚拟主机驱动
│   ├── sub2api/         # Sub2API 兑换码/额度分发驱动
│   └── pve/             # Proxmox VE 虚拟化驱动
├── payments/            # 支付网关插件 (收款与回调)
│   ├── alipay_f2f/      # 支付宝当面付
│   ├── epay/            # 易支付通用接口
│   └── hpjpay/          # 汇付宝/红苹果支付
├── notifications/       # 消息通道插件 (多渠道广播)
│   ├── mail/            # SMTP 邮件
│   ├── sms/             # 短信通道 (阿里云/腾讯云)
│   └── telegram/        # Telegram 机器人
└── captcha/             # 验证码插件 (人机验证)
    ├── builtin_image/   # 系统内置本地图片验证码
    ├── turnstile/       # Cloudflare Turnstile 一键点击
    └── geetest/         # 极验滑块拼图
```

---

## 二、 插件清单元数据 (`manifest.json`)

每个插件根目录下**必须包含一个 `manifest.json` 文件**。管理后台根据此文件自动加载插件，并**根据 `form_fields` 动态自动渲染后台配置页面，开发者无需编写任何前端配置页面**：

```json
{
  "id": "zjmf_proxy",
  "name": "智简魔方财务代理驱动",
  "version": "1.0.0",
  "type": "provider",
  "description": "通过官方 API 代理销售上游魔方系统的弹性云、独服及虚拟主机",
  "author": "JianYun Team",
  "entry": "ZjmfDriver.php",
  "form_fields": [
    {
      "key": "api_url",
      "label": "上游魔方系统地址",
      "type": "text",
      "placeholder": "https://api.example.com",
      "required": true,
      "description": "上游魔方站点的根 URL 地址 (带 https://，末尾不加斜杠)"
    },
    {
      "key": "api_token",
      "label": "API Token / 秘钥",
      "type": "password",
      "required": true,
      "description": "在上游魔方后台 - API 授权管理中生成的密钥"
    },
    {
      "key": "timeout",
      "label": "HTTP 超时时间(秒)",
      "type": "number",
      "default": 10,
      "required": false
    }
  ]
}
```

### `form_fields` 支持的表单控件类型：
- `text`：单行文本输入
- `password`：密码/敏感密钥（系统存库前会自动进行 AES-256-GCM 信封加密）
- `number`：数字输入
- `textarea`：多行文本（如公私钥证书）
- `select`：下拉选择（需配 `options: [{"label": "A", "value": "1"}]`）
- `switch`：开关（布尔值）

---

## 三、 上游云产品驱动开发规范 (`UpstreamDriverInterface`)

### 1. 契约方法定义
所有插件接口统一置于 `App\Plugins\*` 命名空间下（与 `App\Plugins\Captcha\CaptchaDriverInterface` 保持一致），插件实现类的命名空间必须与 `plugins/` 下的全小写物理目录严格对应（如 `Plugins\providers\zjmf\ZjmfDriver`，匹配 Linux 大小写敏感的 PSR-4 自动加载器 `bootstrap.php`）。

上游驱动必须实现 `App\Plugins\Providers\UpstreamDriverInterface` 接口：

```php
namespace App\Plugins\Providers;

interface UpstreamDriverInterface
{
    public function getDriverId(): string;
    public function getQueryAuthority(): string; // AUTHORITATIVE_REALTIME | EVENTUAL_CONSISTENCY | NON_QUERYABLE
    public function getFormSchema(int $remotePid, array $credentials): array;
    public function preflightPrice(int $remotePid, string $billingCycle, array $selectedConfig, array $credentials): PreflightResult;
    public function create(array $orderData, array $credentials): DriverExecutionResult;
    public function queryOrder(array $orderData, array $credentials): DriverExecutionResult;
}
```

M2 已实现以上最小接口。电源、变配、重装与快照仍为后续能力，续费通过下述可选接口启用，不强迫首条 CDN 驱动编写空实现。变更原因及兼容边界见 [M2_CONTRACT_CHANGE.md](M2_CONTRACT_CHANGE.md)。

| DTO | 当前字段与语义 |
|---|---|
| `PreflightResult` | `amount` 十进制字符串、`config` 已验证配置、`currency`（首链仅 CNY）、可空 `recurringAmount` 表示独立的周期续费成本；旧驱动省略时保持原同价行为。`context` 驱动必需且不含秘密的履约参数（随订单冻结）。预检在数据库事务外完成，锁内比对商品指纹及报价，不接受客户端价格。 |
| `DriverExecutionResult` | `status` 为 `succeeded/pending/unknown/not_executed/rejected`；可含 `remoteInstanceId`、`ipAddress`、`operationId`、固定脱敏 `code`；可选真实 `username/password` 仅经受控交付写入，密码立即 AES 加密，不存任务或日志。 |

`not_executed` 必须证明没有资产、扣款和可延迟执行请求，才可退避重试；`rejected` 是确认未执行且需人工处理的拒绝。超时、非 JSON、异常与最终一致性查无均为 unknown。成功必须真实可用并返回远端 ID，CDN 不要求 IP。`orderData` 包含服务端快照的远端 PID、账期、上游金额、配置、订单命名空间、原任务键、冻结的 driver_context，以及已知 operation_id；密码仅执行时解密。调查不能再次调用 create 或支付。

Provider 行固定表示一个上游账户/资源命名空间，不允许原地换站点或账号。注册器在 require 前核验清单/入口物理路径，拒绝符号链接越界；核心只能依赖接口与注册器。

### 魔方财务首链范围

`plugins/providers/zjmf` 使用 `/zjmf_api_login`、`/cart/get_product_config`、`/cart/get_total`、带独立 `cart_data` 的 `/cart/settle`、`/invoice_page`、`/apply_credit`。不清空或修改共享购物车，不默认上游支持请求键幂等。付款前校验远端账单实付与小计均等于冻结金额；结果未知不再支付。

按部署生成的 `order_namespace` 与原任务键生成固定 8 位数字/大小写字母的资源名称（含三类字符，不带前缀或连字符），规则写入 `driver_context.hostname_rule=alnum8` 随订单冻结；已提交的旧订单保留原命名规则用于调查，不因升级改名或重购。未知资源 ID 时，调查 `/host/list` 必须严格匹配冻结名称、PID、账期与首付金额，再用账户范围 `/cart/hostinfo` 核对候选 ID；已由结算/支付响应或上述调查取得的稳定资源 ID，以 ID、PID、账期与首付金额核验，允许上游改名，但不接受接口返回另一个 ID。仅 Active 回填。查无/多匹配均保留调查；一小时仍未知转人工。固定首链兼容保留；新增独立枚举选配支持见 [配置销售契约](M2_CONFIG_SALES.md)，高级联动/自定义字段/数量仍拒绝销售。选配 Schema 变化需刷新快照；付款后执行前成本或 Schema 变化转人工，不重购。上游待付款订单也可能迟后执行，不能因为本地没有 Active 资产就直接退款。

### 可选续费能力（当前已实现）

```php
interface RenewalDriverInterface
{
    public function preflightRenewal(array $instance, array $credentials): PreflightResult;
    public function renew(array $order, array $credentials): DriverExecutionResult;
    public function queryRenewal(array $order, array $credentials): DriverExecutionResult;
}
```

接口位于 `app/Plugins/Providers`。新购驱动不实现本接口时，不能销售自动续费。预检只读，返回 CNY 成本及不含秘密的 `context.remote_base_due/remote_target_due`；本地售价仍取实例固定价。`order` 固化账号范围 remote_instance_id、remote_pid、账期、上游金额、renewal_context、本地 base_due_date/target_due_date 和 needs_unsuspend，执行结果继续使用原 DTO。

魔方实现用账户范围 `/cart/hostinfo` 与 `/host/renewpage` 校验身份、账期、金额及单周期 duration，上游 initiative_renew 必须关闭。执行一次 `POST /host/renew`，核对 `/invoice_page` 后一次 `/apply_credit`，再观察原 ID 的目标到期日和 Active。未知后只读调查，绝不补发 renew/支付/解挂；没有原路退款或不可查询取消的保证。魔方可能在收款后自行解除暂停，故当前核心仅准入 active 或 billing 暂停，其他暂停转人工。已购买权益的日期顺延、退款/销毁门禁与人工裁决见 [续费契约](M2_RENEWAL_CONTRACT.md)。

### 1.1 可选快照与备份能力 (`SnapshotDriverInterface`)

以下为尚未实现的 M3 扩展设计；当前运行接口不包含这些能力，落地时需复审能力声明与接口装配。

快照能力通过独立接口扩展，不向所有上游驱动强加空实现。仅支持虚拟主机、额度兑换或人工交付的驱动无需实现本接口；支持快照或备份的驱动应按实际能力声明 `Feature::SNAPSHOT`、`Feature::BACKUP`。注册器必须同时校验能力声明与 `instanceof SnapshotDriverInterface`，声明与实现不符时拒绝加载；服务端也必须校验请求的 `type` 在驱动能力集中，不能只隐藏前端按钮。

```php
namespace App\Plugins\Providers;

interface SnapshotDriverInterface
{
    // $type: 'snapshot' | 'backup'; $action: 'create' | 'delete'
    // 权威度取值与 UpstreamDriverInterface::getQueryAuthority() 相同，按本动作实际能力声明。
    public function getSnapshotQueryAuthority(string $type, string $action): string;

    public function createSnapshot(SnapshotCreateRequest $request, array $credentials): SnapshotOperationResult;
    public function listSnapshots(string $remoteInstanceId, string $type, array $credentials): SnapshotListResult;
    public function querySnapshotOperation(SnapshotOperationQuery $query, array $credentials): SnapshotOperationResult;
    public function deleteSnapshot(SnapshotDeleteRequest $request, array $credentials): SnapshotOperationResult;
}
```

请求与结果 DTO 均放在 `App\Plugins\Providers`，驱动不得自行返回无法被统一 Worker 解释的数组：

| DTO | 必填字段与语义 |
|---|---|
| `SnapshotCreateRequest` | `remoteInstanceId`、`type`、`idempotencyKey`、`name`。`idempotencyKey` 来自计划触发时刻或手动请求的稳定业务键，重试沿用，不得每次随机生成。 |
| `SnapshotDeleteRequest` | `remoteInstanceId`、`type`、`remoteSnapshotId`、`idempotencyKey`。远端 ID 仅在该实例和类型内解释。 |
| `SnapshotOperationQuery` | `remoteInstanceId`、`type`、`action`、原始 `idempotencyKey`；已知时携带 `remoteSnapshotId`、`remoteOperationId`，允许创建响应丢失时仅按原始幂等键反查。 |
| `SnapshotOperationResult` | `status`（`succeeded` / `pending` / `not_executed` / `failed` / `unknown`）、回显 `type` 与 `idempotencyKey`；可选 `remoteSnapshotId`、`remoteOperationId`、`errorCode`、脱敏 `errorMessage`。创建 `succeeded` 必须包含真实远端 ID 且已达到可用状态；仅受理异步请求应返回 `pending`。 |
| `SnapshotListResult` | `complete`、`items`（每项含 `remoteSnapshotId`、`type`、`status`、可选 `sizeBytes`）。驱动负责完整分页并覆盖手动、计划及上游直接创建的对象；`complete=false` 或查询失败时禁止据此判断空槽位或删除候选。 |

- **统一执行出口**：创建和删除均由 `tasks` 的 `snapshot_create` / `backup_create` / `snapshot_delete` / `backup_delete` 动作调用本接口，API 与调度器不得直连具体厂商 SDK。创建先在本地同事务插入 `status='creating'`、`remote_snapshot_id=NULL` 的 `instance_snapshots` 台账与 Outbox；计划创建任务幂等键为 `{snapshot_create|backup_create}:schedule:{schedule_id}:{scheduled_at_UTC_YYYYMMDDHHmmss}`，手动创建为 `{action}:manual:{instance_id}:{client_token}`（限制 token 长度，使总键长不超过 100）。同事务推进 `next_run_at`，任务唯一键冲突时整笔回滚，包括新插入台账；重试复用原任务和台账，不新增创建键字段。删除统一用 `snapshot_delete:{台账ID}`，不能仅用跨节点可能重号的远端 ID。
- **归属校验**：任务 payload 固化 `snapshot_id`、`provider_id`、`instance_id`、`type`、`remote_instance_id` 与已知的 `remote_snapshot_id`；Worker 在执行和回写前核对台账、实例、Provider 及任务的归属。远端 ID 唯一域为 `(instance_id, type, remote_snapshot_id)`，不得通过全局远端 ID 找另一实例的对象。凭据在执行时经 Provider 受控解密，禁止放入 payload。
- **结果未知恢复**：`pending` 或超时结果进入调查，使用原始幂等键和远端操作 ID 调用 `querySnapshotOperation()`。权威反查确认 `not_executed` 后方可安全重发；最终一致性窗口内的 404 不是未执行证明。删除对象查无也不能自动证明原始创建动作未执行。`NON_QUERYABLE` 或超过观察窗口仍未知时进入人工核验门禁，并持续阻断同实例新动作，遵循 [TASKS_OUTBOX.md](TASKS_OUTBOX.md) 的统一锁规则，严禁盲目重建或重删。
- **原子回写**：创建/删除结果、台账状态与任务终态在同一事务内提交，并校验当前 Worker 租约及 Fencing Token。创建回填 `remote_snapshot_id`；删除确认后才置 `deleted`；旧 Worker 和超时查询不得覆盖新令牌结果。

### 1.2 人工交付驱动的等待状态

`ManualAdminDriver` 不应伪装为已交付，也不能返回普通可重试错误让 Worker 无限领取。待人工开通时，将任务置为 `failed` 且 `last_error` 带 `MANUAL_AUDIT_REQUIRED:` 标记，按 [TASKS_OUTBOX.md](TASKS_OUTBOX.md) 保持独立于租约时效的实例人工门禁。人工完成后通过 `POST /api/v1/admin/tasks/{id}/resolve`、`action=fulfill` 裁决：必须由 `super_admin` 提交核验工单号、真实远端 ID、核验备注与 `expected_execution_token`，锁实例/任务并 CAS 递增令牌，在同一事务内提交任务成功、实例激活与审计。该入口不伪造 Worker 租约，也不借用要求有效 Worker 租约的 `completeCreate()` 调用路径。

### 2. 当前实装参考

完整可执行参考在 `plugins/providers/zjmf/ZjmfDriver.php`；驱动不写业务表，Worker 同事务提交交付与任务终态。旧 `/cart/checkout` 示例已删除，避免把未核实的接口当作真实魔方协议。

---

<a id="payment-gateway-interface"></a>

## 四、 支付网关插件开发规范 (`PaymentGatewayInterface`)

本节是支付接口签名与 DTO 的唯一文档来源。M2 已实现收单与易支付；用户已确认退款由管理员手动办理，本期不增加自动网关退款或反查能力。原退款 DTO/状态机保留为未来另行授权的参考，见 [人工退款策略](PAYMENTS_LEDGER.md#manual-refund-policy)。资金分流依照 [PAYMENTS.md](PAYMENTS.md) 与 [PAYMENTS_LEDGER.md](PAYMENTS_LEDGER.md)。

```php
namespace App\Plugins\Payments;
interface PaymentGatewayInterface
{
    public function getDriverId(): string;
    public function checkout(array $payment, array $config): array;
    public function verify(array $notification, array $config): PaymentNotification;
}
```

`checkout` 返回 `{method: POST, action, fields}` 浏览器表单，不包含商户密钥。`PaymentNotification` 包含 `merchantOrder/gatewayTrade/amount/currency`；只有验签且达到成功终态才可返回，签名正确但待付款不得进入收单事务。

易支付 checkout 显式发送支付类型（配置 type，默认 alipay；兼容本机网关强制要求 type 的实现）。按 ASCII 键排序排除 sign/sign_type 和空值，原始 `k=v` 连接后追加密钥做 MD5，通知兼容 GET/POST，且要求 PID 一致、`TRADE_SUCCESS`；收单成功才返回小写 `success`。浏览器 return_url 只供展示。回调不存任意原始请求，只存服务端用途及 merchant_order，防止回调覆盖退款上下文或泄漏敏感字段。

以下退款接口/DTO 仅为未来参考，不属于当前交付待办，当前支付接口不包含它们；将来若另行授权自动退款，须独立评审可选退款能力，不能把钱包补偿声称为原路退款。

### 退款请求与响应契约：
```php
class PaymentRefundRequest
{
    public function __construct(
        public int $invoiceId,              // 父账单关联，不代表本凭证购买了云资源
        public string $gatewayTradeNo,     // 原网关支付交易号
        public string $refundTradeNo,      // 领域层按收款凭证生成的稳定退款号，查询沿用
        public string $refundAmount,       // 退款金额十进制字符串 (如 '60.00')
        public string $reason = '用户协商退款'
    ) {}
}

class PaymentRefundResult
{
    public function __construct(
        public bool $success,             // 仅refundStatus=success时为true；未知结果不能当失败
        public string $refundStatus,       // 'success' | 'pending' | 'failed'
        public ?string $gatewayRefundNo = null, // 网关返回的退款流水号
        public ?string $errorMessage = null
    ) {}
}
```

**退款执行边界**：插件只能按领域层传入的原网关交易号、稳定退款号和金额调用/查询网关，不得按 `invoiceId` 自行选择另一笔收款，不得更新账单、钱包、佣金、实例或任务。领域层以本地收款凭证及服务端 `settlement_purpose` 分流：`late_wallet_credit` 退款只影响该凭证与此前实际预扣的钱包款，即使父账单关联在跑实例，也不得触发云资源退订；`wallet_recharge` 与 `cloud_consumption` 按各自协议处理。稳定退款号按凭证确定，不能只按父账单生成，避免同账单多笔实收串用退款号。

`PaymentRefundResult.refundStatus='pending'` 表示仍在处理或结果未知；网络超时、响应丢失和非权威“未查到”均保留 pending，通过同退款号单向查询或人工核验，不能自动重发退款。`failed` 仅用于权威明确拒绝/未执行且以后不会执行的结论，不能由 `success=false` 直接推导。领域层最终仍须以“凭证 ID + 稳定退款号 + pending”执行终态 CAS；仅影响 1 行时才能在同一事务执行对应用途副作用，插件结果不直接等于账单退款终态。

### 收单返回值

运行中的 `PaymentNotification` 以本地支付尝试流水归属，不接受网关指定用户或直接指定账单。领域层按 `(channel,gateway_trade_no)` 判重，已结算凭证的用途、金额和归属不可被重放覆盖。同一尝试的另一真实交易另建独立收款凭证并转钱包。

---

## 五、 通知通道插件开发规范 (`NotificationChannelInterface`)

```php
namespace App\Plugins\Notifications;

interface NotificationChannelInterface
{
    public function getChannelId(): string;
    public function getName(): string;
    public function getConfigSchema(): array;

    /**
     * 执行具体的消息发送
     */
    public function send(string $target, NotificationMessage $message, array $config): NotificationResult;
}
```

### 示例：Telegram 机器人插件
```php
namespace Plugins\notifications\telegram;

use App\Plugins\Notifications\NotificationChannelInterface;

class TelegramChannel implements NotificationChannelInterface
{
    public function send(string $target, NotificationMessage $message, array $config): NotificationResult
    {
        $botToken = $config['bot_token'];
        $chatId = $target; // 用户的 Telegram Chat ID

        $text = "🔔 *{$message->title}*\n\n{$message->content}\n\n_发送时间: " . date('Y-m-d H:i:s') . "_";
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $res = $this->curlPost($url, ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown']);
        return new NotificationResult(success: ($res['ok'] ?? false) === true);
    }
}
```

---

## 六、 插件加载与测试原则

1. **零核心侵入与受控基础设施**：插件可以依赖 `App\Plugins\*` 契约，并通过核心公开接口使用授权范围内的服务及基础设施（如本地验证码使用 `App\Core\RedisStore` 存取自身挑战）。不得直接引用其他具体插件；不得跨领域直接写表或绕过 `LedgerService` 修改账户/资金账本；不得修改 `app/` 核心文件来适配某个插件。该约定只明确依赖边界，不表示尚未实现的驱动或扩展能力已经可用；
2. **异常穿透与调查协议**：网络中断或超时必须保留“结果未知”语义，**严禁盲目重试以防重复扣款、开通或发送**。上游生命周期任务按 [TASKS_OUTBOX.md](TASKS_OUTBOX.md) 转 `investigating`；退款当前由管理员手动处理，未来另行授权网关退款能力后，才按本节及 [PAYMENTS_LEDGER.md](PAYMENTS_LEDGER.md) 保持凭证 `refund_status='pending'` 并反查，不能套用实例任务状态或直接补钱；通知发送按 [NOTIFICATIONS.md](NOTIFICATIONS.md) 转 `pending_manual_check`。明确业务拒绝按各领域契约处理，禁止将同一异常强行映射为所有模块共同的失败终态。


### 可选配置表单能力

`ConfigurableDriverInterface::describeForm(array $snapshot): array` 将原始快照投影为公开的独立枚举字段列表。核心只识别此接口与 `ConfigOptions` 验证器，驱动负责上游 Schema 语义和不支持能力的拒绝。`PreflightResult.context.schema_hash` 绑定同一安全表单语义；`recurringAmount` 与 `amount` 分别返回周期和首期成本。当前只在魔方实现，不要求其他驱动增加空实现；接口、兼容与验证见 [M2_CONFIG_SALES.md](M2_CONFIG_SALES.md)。

输入约束复用可选 `context.password_policy`：min_length/max_length 为 12–72 范围内整数，可选 character_sets（upper/lower/digit/special）及 generation_length。`PasswordPolicy` 校验通用边界与字符集，核心不解析魔方原始规则；未提供时保留原 12–72。魔方自身将 Schema 转为密码生成策略及冻结的 hostname_policy/schema_v1；新执行前比对当前规则，变化在提交前拒绝。历史 alnum8/jy- 身份与 queryOrder 不改写，调查不生成新密码或重发。规则只在原 JSON 快照/context 中保存，无新实体或配置树。具体字符集、真实缺陷与兼容影响见 [配置销售契约](M2_CONFIG_SALES.md#后续一次真实开通与输入规则修正2026-11-08)。
