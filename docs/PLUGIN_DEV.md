# JianYun (简云) · 模块化插件与驱动开发手册 (Plugin Development Guide)

本文档是系统扩展插件（上游云产品驱动、支付网关、消息通知通道、验证码插件）的标准开发规范与 SDK 手册。**任何开发者或 Agent 编写新插件，必须严格遵循本文档约定。**

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
上游驱动必须实现 `App\Contracts\UpstreamDriverInterface` 接口：

```php
namespace App\Contracts;

interface UpstreamDriverInterface
{
    /**
     * 1. 声明驱动支持的能力集 (按需声明，解决虚拟主机/Sub2API等假装VPS问题)
     * 返回支持的能力常量列表，如: [Feature::CREATE, Feature::RENEW, Feature::POWER, Feature::SNAPSHOT]
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

### 2. 编写实战范例（以魔方代理为例）
```php
namespace Plugins\Providers\Zjmf;

use App\Contracts\UpstreamDriverInterface;

class ZjmfDriver implements UpstreamDriverInterface
{
    public function create(array $orderData, array $credentials): ProvisionResult
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

        return new ProvisionResult(
            remoteInstanceId: (string)$response['data']['host_id'],
            ipAddress: $response['data']['ip'] ?? '',
            rawPayload: $response['data']
        );
    }
    // ...其余方法实现
}
```

---

## 四、 支付网关插件开发规范 (`PaymentGatewayInterface`)

支付插件用于对接三方支付渠道，必须包含**发起收银台**与**回调验签**两大能力：

```php
namespace App\Contracts;

interface PaymentGatewayInterface
{
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
        public int $invoiceId,
        public string $gatewayTradeNo,     // 原网关支付交易号
        public string $refundTradeNo,      // 简云生成的本次唯一退款批次号
        public string $refundAmount,       // 退款金额十进制字符串 (如 '60.00')
        public string $reason = '用户协商退款'
    ) {}
}

class PaymentRefundResult
{
    public function __construct(
        public bool $success,
        public string $refundStatus,       // 'success' | 'pending' | 'failed'
        public ?string $gatewayRefundNo = null, // 网关返回的退款流水号
        public ?string $errorMessage = null
    ) {}
}
```

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
namespace App\Contracts;

interface NotificationChannelInterface
{
    /**
     * 执行具体的消息发送
     */
    public function send(string $target, NotificationMessage $message, array $config): NotificationResult;
}
```

### 示例：Telegram 机器人插件
```php
namespace Plugins\Notifications\Telegram;

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

1. **零核心侵入**：插件代码仅能引用 `App\Contracts\*` 与核心服务，严禁直接修改 `app/` 核心文件；
2. **异常穿透与调查协议**：遇到网络中断或超时必须抛出 `NetworkUnknownException`，通知发件箱任务进入 `investigating` 调查状态并遵循未知结果反查协议，**严禁盲目直接发起自动重试以防重复扣款与开通**；若遇到致命明确业务拒绝（如余额不足/参数非法）抛出 `FatalBusinessException`，终止任务并触发人工干预或告警。
