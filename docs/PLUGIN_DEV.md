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
    /**
     * 1. 声明驱动支持的能力集 (按需声明，解决虚拟主机/Sub2API等假装VPS问题)
     * 返回支持的能力常量列表，如: [Feature::CREATE, Feature::RENEW, Feature::POWER]
     * Feature::SNAPSHOT / Feature::BACKUP 还必须实现下述可选 SnapshotDriverInterface
     */
    public function getSupportedFeatures(): array;

    /**
     * 1.1 声明驱动的反查权威度级别
     * 返回值: 'AUTHORITATIVE_REALTIME' | 'EVENTUAL_CONSISTENCY' | 'NON_QUERYABLE'
     */
    public function getQueryAuthority(): string;

    /**
     * 2. 获取商品规格定义 (SWR 缓存使用)
     */
    public function getFormSchema(int $remotePid, array $credentials): array;

    /**
     * 3. 下单前原子算价与预检 (Pre-flight Check)
     */
    public function preflightPrice(int $remotePid, array $selectedConfig, array $credentials): PreflightResult;

    /**
     * 4. 核心生命周期动作 (必须支持幂等执行，返回结构化状态)
     */
    public function create(array $orderData, array $credentials): DriverExecutionResult;
    public function renew(string $remoteInstanceId, string $targetDueDate, array $credentials): DriverExecutionResult;
    public function suspend(string $remoteInstanceId, string $reason, array $credentials): DriverExecutionResult;
    public function unsuspend(string $remoteInstanceId, array $credentials): DriverExecutionResult;
    public function terminate(string $remoteInstanceId, array $credentials): DriverExecutionResult;
    public function resize(string $remoteInstanceId, array $targetConfig, array $credentials): DriverExecutionResult;

    /**
     * 5. 状态反查与幂等对账 (用于超时后结果未知恢复，严禁盲目重试)
     */
    public function queryOrder(string $idempotencyKey, array $credentials): DriverExecutionResult;
    public function syncStatus(string $remoteInstanceId, array $credentials): InstanceStatusSnapshot;

    /**
     * 6. 可选运维能力 (若不支持，返回 DriverExecutionResult::unsupported())
     */
    public function power(string $remoteInstanceId, string $action, array $credentials): DriverExecutionResult;
    public function getVnc(string $remoteInstanceId, array $credentials): DriverExecutionResult;
    public function reinstall(string $remoteInstanceId, string $osId, string $password, array $credentials): DriverExecutionResult;
}
```

### 1.1 可选快照与备份能力 (`SnapshotDriverInterface`)

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

`ManualAdminDriver` 不应伪装为已交付，也不能返回普通可重试错误让 Worker 无限领取。待人工开通时，将任务置为 `failed` 且 `last_error` 带 `MANUAL_AUDIT_REQUIRED:` 标记，按 [TASKS_OUTBOX.md](TASKS_OUTBOX.md) 保持独立于租约时效的实例人工门禁。人工完成后通过 `POST /api/v1/admin/tasks/{id}/manual-fulfill` 裁决：必须由 `super_admin` 提交核验工单号、真实远端 ID、核验备注与 `expected_execution_token`，锁实例/任务并 CAS 递增令牌，在同一事务内提交任务成功、实例激活与审计。该入口不伪造 Worker 租约，也不借用要求有效 Worker 租约的 `completeCreate()` 调用路径。

### 2. 编写实战范例（以魔方代理为例）
```php
namespace Plugins\providers\zjmf;

use App\Plugins\Providers\UpstreamDriverInterface;

class ZjmfDriver implements UpstreamDriverInterface
{
    public function create(array $orderData, array $credentials): DriverExecutionResult
    {
        $apiUrl = $credentials['api_url'] . '/cart/checkout';
        $payload = [
            'pid' => $orderData['remote_pid'],
            'configoptions' => $orderData['config_options'], // 原样透传用户选择的配置
            'hostname' => $orderData['hostname'],
            'password' => $orderData['password']
        ];

        $response = $this->httpPost($apiUrl, $payload, $credentials['api_token']);
        if ($response['status'] !== 200) {
            throw new \RuntimeException("上游开通失败: " . ($response['msg'] ?? '未知错误'));
        }

        return new DriverExecutionResult(
            success: true,
            remoteInstanceId: (string)$response['data']['host_id'],
            ipAddress: $response['data']['ip'] ?? '',
            rawPayload: $response['data']
        );
    }
    // ...其余方法实现
}
```

---

<a id="payment-gateway-interface"></a>

## 四、 支付网关插件开发规范 (`PaymentGatewayInterface`)

本节是 `App\Plugins\Payments\PaymentGatewayInterface` **接口签名与支付 DTO 的唯一文档来源**；支付领域的收款分流、事务 CAS 与退款副作用分别遵循 [PAYMENTS.md](PAYMENTS.md) 和 [PAYMENTS_LEDGER.md](PAYMENTS_LEDGER.md) §3，其他文档只引用本节，不复制接口定义。下列接口与 DTO 为待实现契约，不代表已安装三方支付能力。

支付插件用于对接三方支付渠道，必须包含**网关元信息、发起收银台、回调验签、原路退款与状态反查**能力：

```php
namespace App\Plugins\Payments;

interface PaymentGatewayInterface
{
    public function getGatewayId(): string;
    public function getName(): string;
    public function getConfigSchema(): array;

    /**
     * 发起支付请求 (返回客户端需要的 URL 或二维码)
     */
    public function purchase(Invoice $invoice, array $config): PaymentPurchaseResult;

    /**
     * 异步回调通知验签与解析
     */
    public function handleNotify(array $requestParams, array $config): PaymentNotifyResult;

    /**
     * 原路退款接口 (支持向第三方网关发起原路退回)
     */
    public function refundPayment(PaymentRefundRequest $request, array $config): PaymentRefundResult;

    /**
     * 退款状态异步反查接口 (用于超时或调查协议单向核验)
     */
    public function queryRefund(string $refundTradeNo, array $config): PaymentRefundResult;
}
```

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

### 回调验签返回值契约：
```php
class PaymentNotifyResult
{
    public function __construct(
        public bool $success,             // 签名校验是否通过
        public ?int $invoiceId = null,    // 解析出的本系统账单 ID
        public ?string $gatewayTradeNo = null, // 第三方支付网关交易流水号
        public ?string $amount = null,    // 实际收款十进制字符串 (如 '30.00'，严禁使用 float)
        public ?string $currency = 'CNY', // 币种
        public string $response = 'SUCCESS' // 验签成功后响应给网关的报文
    ) {}
}
```

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
2. **异常穿透与调查协议**：网络中断或超时必须保留“结果未知”语义，**严禁盲目重试以防重复扣款、开通或发送**。上游生命周期任务按 [TASKS_OUTBOX.md](TASKS_OUTBOX.md) 转 `investigating`；支付退款按本节及 [PAYMENTS_LEDGER.md](PAYMENTS_LEDGER.md) 保持凭证 `refund_status='pending'` 并反查，不能套用实例任务状态或直接补钱；通知发送按 [NOTIFICATIONS.md](NOTIFICATIONS.md) 转 `pending_manual_check`。明确业务拒绝按各领域契约处理，禁止将同一异常强行映射为所有模块共同的失败终态。
