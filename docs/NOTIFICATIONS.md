# JianYun (简云) · 模块化多渠道通知中心设计文档 (Notification Center)

本文档定义了 JianYun 的消息广播、通知渠道插件化扩展，以及基于指纹幂等的“单渠道单消息仅投递一次”保障机制。

---

## 1. 核心设计原则

1. **统一广播，各自独立**：一个业务事件（如产品到期、开通成功、工单回复）可同时投递给多个渠道（邮件、短信、Telegram、微信、QQ等）。
2. **单渠道至多一次 (At-most-once per channel)**：每个渠道对同一条消息指纹至多产生一次外部发送效果。默认只允许一次发送认领；结果未知时停止重发，接受可能漏送并转人工核验，不能同时承诺不可查询渠道的“必达且绝不重复”。数据库唯一行仅约束本地占位，不能证明外部只发送了一次。
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
| **实例自动开通成功** | `host:{host_id}:provision_success` | `host:10024:provision_success` | 开通成功通知每渠道至多发送一次 |
| **账单支付成功** | `invoice:{invoice_id}:paid` | `invoice:80921:paid` | 该账单支付成功通知仅发一次 |
| **工单新回复** | `ticket:{ticket_id}:reply:{reply_id}` | `ticket:332:reply:1502` | 每条新回复仅通知一次 |

---

## 4. 防重投递表 (`notification_deliveries`)

> [!NOTE]
> **DDL 单一事实来源**：`notification_deliveries` 完整字段定义以 `database/schema.sql` 为唯一权威（字典释义见 [DATABASE.md](DATABASE.md) §1.17），本规范不再内嵌 DDL 副本，避免双源漂移。
> 核心约束为铁律唯一索引 `UNIQUE KEY uk_fingerprint_channel (fingerprint, channel)`：相同业务指纹在同一个渠道下绝对只允许存在一条记录，数据库层物理防重。

---

## 5. 分发与容错工作流（未知结果禁止自动重发）

### 5.1 占位与唯一发送认领

检查订阅偏好后，业务事务或可靠补扫将 `(fingerprint, channel)` 写入 `notification_deliveries(status='pending')`；只有成功创建的行进入发送流程。重复触发命中唯一键时返回已有记录，不再调用 Driver。唯一行在失败或人工核验期间也必须保留，禁止删除后重建以绕过防重。

```sql
-- 仅对从未被认领发送的 pending 行做短事务抢占。
START TRANSACTION;
SELECT `id`, `status` FROM `notification_deliveries` WHERE `id` = :id FOR UPDATE;
UPDATE `notification_deliveries`
SET `status` = 'sending', `updated_at` = UTC_TIMESTAMP()
WHERE `id` = :id AND `status` = 'pending';
-- affected_rows 必须为 1；否则结束且不发送。
COMMIT;
-- 唯一认领者在事务外调用一次 Driver::send()，不得跨网络持有行锁。
```

`NotificationMessage` 必须携带固定的业务 fingerprint；通用接口本身没有声明服务端持久幂等或查询能力，**所有渠道默认按不可查询、不能安全重试处理**。认领提交后即使进程在真正调用前崩溃，也无法证明请求未发出；不把 `sending` 重置为 `pending`。驱动及其 HTTP/SMTP SDK 同样必须关闭发送请求的透明自动重试，除非已有 §5.3 的持久幂等证明；一次 `send()` 不能暗中发起多次非幂等请求。

发送后的本地回写使用 CAS `WHERE id=:id AND status='sending'`：

- 明确被渠道接受：置 `success` 并记录 `sent_at`。这里代表发送确认，不等同于终端用户已阅读。
- 渠道权威明确拒绝：置 `failed`，记录脱敏错误；不由通用扫描器重发。
- 网络超时、连接中断或结果不明：置 `pending_manual_check` 并告警，禁止重新调用 `send()`。CAS 影响 0 行时重读当前状态，不能用旧 Worker 的结果覆盖已完成的人工裁决；可靠的迟到确认经人工核验收口入口处理，不触发发送。

### 5.2 恢复扫描只领取未发送行，隔离发送结果未知行

后台每 10 分钟执行两条独立路径，使用现有表与索引，不新增队列表：

```sql
-- A. pending 尚无任何发送认领，可以按 §5.1 原子认领后发送。
SELECT `id` FROM `notification_deliveries`
WHERE `status` = 'pending'
  AND `created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE);

-- B. sending 超时意味着外部结果未知，只转核验，绝不重新置 sending/pending。
UPDATE `notification_deliveries`
SET `status` = 'pending_manual_check',
    `error_message` = 'SEND_OUTCOME_UNKNOWN: stale sending claim',
    `updated_at` = UTC_TIMESTAMP()
WHERE `status` = 'sending'
  AND `updated_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE);
```

若原 Worker 已完成 CAS，路径 B 不再命中；若 B 先转核验，原 Worker 不能覆盖该状态。对 `sending` 的超时判定不会证明渠道未发送，因此无论扫描器如何互斥，都不能据此重发。人工核验确认已被渠道接受时可在行锁/CAS 下置 `success`；确认明确拒绝时置 `failed`；无法确认则保持 `pending_manual_check`。不得通过新 fingerprint、删除占位或换 Worker 规避同一业务消息防重。

### 5.3 可安全恢复的渠道必须另有服务端契约

只有渠道适配器另行提供并验证以下扩展契约，才可以在独立评审后启用受控恢复；未具备时仍执行上面的默认流程：

1. 外部服务按固定幂等键持久排重，同一 `(fingerprint, channel)` 必须映射同一个键，覆盖并发调用、超时及进程崩溃；键的保留期须覆盖全部恢复窗口。
2. 或提供权威状态查询，能明确区分“已接受”“仍在处理”“保证未接受且旧请求以后绝不生效”；普通 404、最终一致性的未查到不构成安全重发依据。
3. 若恢复允许重试，只能由行锁/CAS 下的唯一恢复者使用**同一外部幂等键**执行，并用已有本地行保存恢复依据；不能并发恢复或生成新键。现有通用 `NotificationChannelInterface` 未暴露此扩展，因此不能仅凭插件配置或名称启用。

**Web Push 的 `tag` 仅用于客户端通知展示合并，不保证推送服务端、网关或计费侧的持久幂等，也不能撤回已经送出的消息。** 不得用 tag 或“最多重试两次”作为绝不重复发送的证明。

### 5.4 故障验收矩阵

| 场景 | 处理与验证要点 |
|---|---|
| 两个 Worker 同时领取同一 pending | 只有一个 `pending -> sending` CAS 影响 1 行，外部 send 仅调用一次 |
| 渠道已接受，Worker 在回写 success 前崩溃 | 悬空 sending 转 pending_manual_check；扫描不重发，唯一占位保留 |
| 认领已提交，Worker 在调用渠道前崩溃 | 同样转 pending_manual_check；接受可能漏送，不能凭猜测自动补发 |
| 恢复扫描与慢 Worker 回写竞争 | 仅一个状态 CAS 成功；迟到确认走核验入口，无二次发送 |
| Web Push 超时但配置了相同 tag | 按未知结果处理，tag 不授权通用重试 |
| 渠道声称支持幂等但无持久化/保留期证据 | 按默认不可安全重试渠道处理，保持核验状态 |
| 业务事件重放命中既有 failed/核验行 | 返回已有结果，不删除记录、不换 fingerprint、不重新发送 |

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

### 6.2 Web Push 设备订阅表 (`web_push_subscriptions`)

> [!NOTE]
> **DDL 单一事实来源**：`web_push_subscriptions` 完整字段定义以 `database/schema.sql` 为唯一权威（字典释义见 [DATABASE.md](DATABASE.md) §1.18），本规范不再内嵌 DDL 副本，避免双源漂移。
> 关键设计：`endpoint_hash`（SHA256）承担唯一索引防重复订阅；`auth_token` 为推送鉴权密钥，AES-256-GCM 加密存储，严禁明文落库与日志。

