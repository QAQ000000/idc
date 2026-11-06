# JianYun (简云) · 支付网关聚合与资金安全规范 (Payments & Security)

本文档定义了 JianYun 的支付网关插件扩展契约、异步回调原子防重入机制，以及敏感凭据的应用层信封加密设计。

---

## 1. 支付网关插件接口契约 (`PaymentGatewayInterface`)

所有支付插件放置在 `plugins/payments/` 目录下（如 `alipay_f2f`, `epay`, `hpjpay`, `balance`），实现统一规范：

```php
namespace App\Plugins\Payments;

interface PaymentGatewayInterface
{
    /**
     * 网关唯一标识
     */
    public function getGatewayId(): string;

    /**
     * 展现名称 (如 '支付宝当面付', '微信支付 (汇付宝)')
     */
    public function getName(): string;

    /**
     * 后台动态配置项 (商户ID, 异步通知地址, RSA公私钥, MD5密钥)
     */
    public function getConfigSchema(): array;

    /**
     * 发起支付，统一返回前端收银台形态
     * @return PaymentPurchaseResult (包含支付URL, 二维码QrCodeBase64, 或唤起参数)
     */
    public function purchase(Invoice $invoice, array $config): PaymentPurchaseResult;

    /**
     * 异步通知验签与解析
     */
    public function handleNotify(array $requestData, array $config): PaymentNotifyResult;
}
```

---

## 2. 异步回调防重入铁律 (Anti-Reentrant Callback)

第三方支付平台（如支付宝、易支付等）因网络抖动，经常会在短时间内发送 3~5 次相同的异步通知。**绝对禁止裸查裸改**（即先 `SELECT` 发现未付，然后直接修改），必须使用原子行级更新：

```php
// ⚡ 核心铁律：利用数据库底层行锁做 CAS (Compare-And-Swap) 原子状态跃迁
$affectedRows = DB::update("
    UPDATE invoices 
    SET status = 'paid', paid_at = NOW(), payment_method = ?
    WHERE id = ? AND status = 'unpaid'
", [$gatewayId, $invoiceId]);

if ($affectedRows === 0) {
    // 说明该账单已经被前一个并发通知或定时任务成功处理过了
    Log::info("账单 #{$invoiceId} 回调重复触发，CAS 拦截生效，直接响应平台 SUCCESS");
    return response('SUCCESS');
}

// 只有成功抢占状态的这一个线程，才能继续往下执行：
// 1. 记录交易流水 transactions
// 2. 如果是余额充值账单 -> 增加用户 wallet.balance
// 3. 如果是产品购买/续费 -> 事务发件箱 tasks 插入待执行任务
```

---

## 3. 敏感数据信封加密 (Envelope Encryption)

系统处理的敏感数据（数据库拖库防范）：
- 上游魔方 API Token；
- 支付商户私钥 (RSA Private Key)；
- 虚拟机初始 Root 密码。

**规则**：
- 底层统一由 `SecurityService` 托管，基于主密钥 `APP_KEY` 使用 `AES-256-GCM` 算法加密；
- 数据库中只存储带初始向量（IV）与认证标签（TAG）的密文字符串；
- 任何调试日志、错误回显中，严禁明文打印包含 `credentials` 或 `password` 的字段。
