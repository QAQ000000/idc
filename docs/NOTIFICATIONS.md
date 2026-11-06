# JianYun (简云) · 模块化多渠道通知中心设计文档 (Notification Center)

本文档定义了 JianYun 的消息广播、通知渠道插件化扩展，以及基于指纹幂等的“单渠道单消息仅投递一次”保障机制。

---

## 1. 核心设计原则

1. **统一广播，各自独立**：一个业务事件（如产品到期、开通成功、工单回复）可同时投递给多个渠道（邮件、短信、Telegram、微信、QQ等）。
2. **单渠道严格幂等 (At-most-once per channel)**：每个渠道对同一条消息指纹**仅允许成功投递一次**，绝对禁止因重试或定时任务重跑造成重复骚扰或额外扣费。
3. **插件化热插拔**：所有通知渠道继承统一接口 `NotificationChannelInterface`，后台自动加载驱动与配置表单。
4. **异步队列解耦**：通知投递绝不阻塞前台订单、支付和工单交互，全量进入后台异步队列。

---

## 2. 渠道插件规范 (`plugins/notifications/`)

每个渠道插件必须实现以下契约：

```php
namespace App\Plugins\Notifications;

interface NotificationChannelInterface
{
    /**
     * 渠道唯一标识 (如 'mail', 'sms', 'telegram', 'wechat_mp', 'qq_bot')
     */
    public function getChannelId(): string;

    /**
     * 渠道名称展示 (如 'Telegram 机器人')
     */
    public function getName(): string;

    /**
     * 后台动态配置项 (Bot Token, API Key, 网关地址等)
     */
    public function getConfigSchema(): array;

    /**
     * 执行具体的消息发送动作
     */
    public function send(string $target, NotificationMessage $message, array $config): NotificationResult;
}
```

---

## 3. 消息唯一指纹 (Fingerprint) 生成规则

为实现单渠道单消息防重，每一个业务通知在派发前必须生成不可伪造的**消息指纹**：

| 业务场景 | 指纹命名规则 (Fingerprint Format) | 示例 | 产生效果 |
| :--- | :--- | :--- | :--- |
| **实例即将到期提醒** | `host:{host_id}:due_reminder:{date}` | `host:10024:due_reminder:2026-11-07` | 同一台主机在同一天内，每个渠道绝对只提醒一次 |
| **实例自动开通成功** | `host:{host_id}:provision_success` | `host:10024:provision_success` | 开通成功通知有且仅发一次 |
| **账单支付成功** | `invoice:{invoice_id}:paid` | `invoice:80921:paid` | 该账单支付成功通知仅发一次 |
| **工单新回复** | `ticket:{ticket_id}:reply:{reply_id}` | `ticket:332:reply:1502` | 每条新回复仅通知一次 |

---

## 4. 防重投递表结构 (MySQL DDL)

在数据库中追加 `notification_deliveries` 审计与防重表：

```sql
CREATE TABLE IF NOT EXISTS `notification_deliveries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fingerprint` VARCHAR(100) NOT NULL COMMENT '业务消息唯一指纹',
    `channel` VARCHAR(30) NOT NULL COMMENT '渠道标识 (如 mail, sms, telegram)',
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '接收用户 ID',
    `target` VARCHAR(100) NOT NULL COMMENT '投递目标 (邮箱/手机号/ChatID)',
    `status` ENUM('pending', 'sending', 'success', 'failed', 'pending_manual_check') NOT NULL DEFAULT 'pending',
    `error_message` VARCHAR(255) NULL COMMENT '失败报错信息',
    `sent_at` TIMESTAMP NULL COMMENT '实际送达时间',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- ⚡ 铁律唯一索引：相同指纹在同一个渠道下绝对只允许存在一条记录
    UNIQUE KEY `uk_fingerprint_channel` (`fingerprint`, `channel`),
    INDEX `idx_user_created` (`user_id`, `created_at`),
    INDEX `idx_status_updated` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知投递与幂等审计表';
```

---

## 5. 分发与容错工作流 (防重投递与互斥恢复扫描)

```text
[业务触发] -> 生成 Fingerprint -> NotificationDispatcher
                                       ↓
        ┌──────────────────────────────┴──────────────────────────────┐
        ↓ (并发/重复触发保护)                                           ↓
INSERT IGNORE 检查 (fingerprint, channel)                    检查用户订阅偏好
   ├── 命中已存在 (返回0) -> 🛑 静默丢弃，终止发送
   └── 成功插入占位 (返回1) -> 写入 notification_deliveries (status='pending')
                                       ↓
        Worker 原子抢占锁：UPDATE status='sending' WHERE id=? AND status='pending'
                                       ↓
                           对应渠道 Driver 调用发送
                                       ├── 明确送达 -> 更新 status='success', sent_at=NOW()
                                       ├── 结果未知/网络超时:
                                       │     ├── 支持客户端标签排重渠道(Web Push Tag): 允许指数退避重试(最多2次)
                                       │     └── 外部短信/邮件单向通道: 避免重复轰炸，标记 'pending_manual_check' 或记录警告
                                       └── 明确拒绝 -> 记录 error_message, 状态置为 'failed'

⚡ 兜底防漏与互斥抢占扫描 (Recovery Scanner):
后台守护定时器每 10 分钟扫描一次悬空记录：
SELECT id FROM notification_deliveries 
WHERE (status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
   OR (status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE));
抢占时必须使用 CAS 原子更新为 status='sending'，防止多 Worker 并发重发导致的通知轰炸！
```

---

## 6. 浏览器 Web Push (Chrome / Edge / Safari) 与安全隔离

系统支持基于 W3C 标准的 Web Push Notifications（支持 Google Chrome, Microsoft Edge, macOS & iOS Safari 16.4+, Firefox），并通过 Service Worker 实现后台/离线通知。

### 6.1 核心安全铁律：通知设备状态与控制台登录凭据彻底拆分
> [!IMPORTANT]
> **通知订阅凭证 ≠ 控制台登录凭证！**
> 允许用户在公共电脑或手机上“保持接收云服务器到期告警”，但**绝对禁止利用该通知订阅凭证直接登录控制台或调用任何业务写接口**。

1. **凭证隔离与统一鉴权模型**：
   - 控制台登录鉴权遵循统一的 **Bearer JWT / 安全 HttpOnly 会话规范**，具备严格的 2 小时 Access Token 短时效与注销黑名单即时吊销机制。
   - Web Push 使用专用的设备端点与密钥（`endpoint`, `p256dh`, `auth`），作用域被严格限制为 `scope: notification_only`。
2. **退出登录不影响接收通知（可选长效订阅）**：
   - 用户即使在网页上点击【退出登录】，浏览器的 Service Worker 仍可保留订阅，继续静默接收该账号的“机器即将销毁/紧急告警”推送。
3. **点击通知的安全流转**：
   - 用户点击浏览器弹窗，唤起网页目标 URL（如 `/instances/10024`）。
   - 前端必须严格检验主会话有效性：
     - 若当前登录态有效，直接展现实例控制台；
     - 若当前已退出或登录态失效，**立即重定向至登录页**，严禁使用推送凭据免密提权！

### 6.2 Web Push 设备订阅表结构 (`web_push_subscriptions`)
```sql
CREATE TABLE IF NOT EXISTS `web_push_subscriptions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '归属用户',
    `endpoint` TEXT NOT NULL COMMENT '浏览器推送网关 Endpoint URL',
    `endpoint_hash` CHAR(64) NOT NULL COMMENT 'Endpoint SHA256 哈希 (用于唯一索引)',
    `p256dh_key` VARCHAR(255) NOT NULL COMMENT '客户端公钥 P256DH',
    `auth_token` VARCHAR(255) NOT NULL COMMENT '客户端鉴权密钥 Auth Secret',
    `browser` VARCHAR(30) NULL COMMENT '浏览器类型: chrome | edge | safari | firefox',
    `device_name` VARCHAR(50) NULL COMMENT '设备别名 (如 Mac Chrome, iPhone Safari)',
    `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active' COMMENT '订阅状态',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_endpoint_hash` (`endpoint_hash`),
    INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebPush浏览器订阅表';
```

