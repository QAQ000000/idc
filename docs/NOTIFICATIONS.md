# JianYun (简云) · 模块化多渠道通知中心设计文档 (Notification Center)

本文档维护通知占位、一次发送认领和未知结果核验规则。当前只实现单邮件的管理员人工核验告警、客户临期提醒与客户主动申请的密码重置；其余渠道、广播与 Web Push 是未来规划。

---

## 1. 核心设计原则

1. **统一广播，各自独立**：一个业务事件（如产品到期、开通成功、工单回复）可同时投递给多个渠道（邮件、短信、Telegram、微信、QQ等）。
2. **单渠道至多一次 (At-most-once per channel)**：默认只为同一消息指纹发起一次渠道发送尝试，外部系统自身行为由其契约决定。默认只允许一次发送认领；结果未知时停止重发，接受可能漏送并转人工核验，不能同时承诺不可查询渠道的“必达且绝不重复”。数据库唯一行仅约束本地占位，不能证明外部只发送了一次。
3. **插件化热插拔**：所有通知渠道继承统一接口 `NotificationChannelInterface`，后台自动加载驱动与配置表单。
4. **异步队列解耦**：通知投递绝不阻塞前台订单、支付和工单交互，全量进入后台异步队列。

---

## 2. 渠道插件规范 (`plugins/notifications/`)

完整接口及 DTO 以 [插件手册 §五](PLUGIN_DEV.md#五-通知通道插件开发规范-notificationchannelinterface) 和 `app/Plugins/Notifications/` 为准，本页维护投递业务规则，不复制接口。首个实现为 mail/SMTP，其他渠道与动态后台配置仍属规划。

---

## 3. 消息唯一指纹 (Fingerprint) 生成规则

为实现单渠道单消息防重，每一个业务通知在派发前必须生成稳定的**业务消息指纹**（不是认证凭据）：

| 业务场景 | 指纹命名规则 (Fingerprint Format) | 示例 | 产生效果 |
| :--- | :--- | :--- | :--- |
| **实例即将到期提醒** | `host:{id}:due_reminder:{due_utc_compact}:{day}` | `host:10024:due_reminder:20261110000000:2026-11-07` | 同一实例、完整到期时间、北京时间提醒日，每渠道只认领一次 |
| **管理员人工核验告警** | `task:{id}:manual:{execution_token}:admin:{admin_id}` | `task:3:manual:2:admin:1` | 同一任务核验轮次、同一管理员只认领一次 |
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

当前每分钟通知调度补扫来源并执行悬空认领恢复；独立邮件 Worker 立即消费未认领 pending，使用现有表与索引，不新增队列表。以下 SQL 为条件示意，不另建第二套 cron：

```sql
-- A. pending 尚无任何发送认领，可以按 §5.1 原子认领后发送。
SELECT `id` FROM `notification_deliveries`
WHERE `status` = 'pending'
;

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


## 7. 当前单邮件闭环（2026-11-08）

原通知批次实现两类：任务 failed + MANUAL_AUDIT_REQUIRED 给所配置的启用管理员；已交付 active、月付/年付、未来 7 天内到期的启用客户实例给本人。提醒按北京时间自然日生成，数据库 due_date 仍为 UTC；暂停、一次性产品、未交付及已到期不在本批客户提醒范围。这两类消息均为纯文本，只含本站登录后详情链接、必要编号/时间，不含免登 Token、上游凭据、密码或原始错误。后续账户重置邮件另含独立用途的短效重置凭据，不能用作登录。

- 来源补扫 `notifications:sweep` 每分钟只写通知/恢复状态，不调用 SMTP，不加入付款/Worker 结果事务，也不阻塞出账代扣。task 状态是唯一待办事实，提交后崩溃可由后续补扫找回；补扫前已被处理的任务无需再告警。通知发送失败不回滚业务。
- 独立 `notifications:work` 持久认领发送，避免慢 SMTP 卡住金融 Outbox 或调度进程。`notifications:dispatch` 是运维/隔离测试的一次性补扫发送入口，不再额外定时运行它。独立进程增加一个 mail systemd 单元，复用同一配置和数据库，不建设 Laravel jobs 表/第二队列系统。
- 原表 user_id 改为可空，增加 admin_id/context；每行由服务严格执行 user_id/admin_id 二选一，禁止把客户编号冒充管理员。context 只冻结 kind、实例、原任务令牌或完整到期日/提醒日。唯一键不变，无新增实体，仍为 27 表。已有旧 pending 无可识别 context 时置 failed/STALE_BUSINESS_CONTEXT，不猜测正文；旧 sending 继续转人工核验，绝不重发。
- 发送认领前在短事务重查当前实例/任务及收件人，再锁通知行。管理员须仍启用、仍被配置、邮箱未变、原任务及 execution_token 仍待核验；提醒须仍归本人、邮箱未变、active、账期和完整到期时间未变、仍在窗口且仍是同一提醒日。旧消息以 failed/STALE_BUSINESS_CONTEXT 保留，不删行、不自动换收件人或改指纹补发。已退款/暂停/改归属带来的实例失效同样不会发送原提醒。
- 认领事务结束后才调用一次 SMTP，不跨网络持有资源/资金锁。发送前重查是该时点的保证；若客户在 SMTP 发送期间续费，已经被接受的邮件无法撤回。正文提示以控制台最新账期为准，不宣称消除了这一网络窗口。
- 人工处理只收口邮件状态，不裁决关联任务、不退款、不更改实例、不重发。CLI 需当前启用 super_admin、expected_status=pending_manual_check 和至少 10 字节 evidence，行锁防过期/重复，状态与原 admin_audit_logs 同事务。迟到发送结果只能 CAS sending，不能覆盖恢复或人工终态；sent_at 是本地收到/人工确认 SMTP 接受的时间，并非收件箱送达时间。

### 配置和运维

使用当前环境同一 APP_KEY、数据库及 PHP，通过私有 stdin 执行：

```bash
php artisan migrate --force
php artisan notifications:configure ADMIN_ID < /受保护目录/mail.json
php artisan notifications:sweep
php artisan notifications:inspect
php artisan notifications:resolve DELIVERY_ID ADMIN_ID < /受保护目录/review.json
```

配置示例（占位内容不能当真实凭据）：

```json
{"enabled":true,"task_alerts":true,"due_reminders":false,"site_url":"https://shop.example","admin_ids":[1],"subject_prefix":"","config":{"host":"smtp.example","port":465,"security":"smtps","username":"sender@example.test","password":"PRIVATE_SECRET","from":"sender@example.test","from_name":"站点名称"}}
```

SMTP 所有配置整体 AES-256-GCM 加密存入 notify.mail 的 encrypted_credentials；启用开关、场景开关、本站 origin/标题前缀和管理员 ID 放非敏感 value，并同事务审计。配置只允许当前启用 super_admin 本地提交；启用前管理员邮箱须已核准且有效，不能沿用 example.invalid 占位。首版不增加 HTTP 配置接口或通知管理页，不影响现有任务详情权限。客户提醒启用需显式 due_reminders=true，未配置/总开关关闭时不产生或发送新通知；关闭不撤回已在网络中的发送，悬空发送的恢复仍会执行。

核验输入：`{"status":"success","expected_status":"pending_manual_check","evidence":"SMTP 日志/服务商队列中已接受该 Message-ID 的依据"}`；确认权威拒绝则 status=failed，无法确认保持原状。`notifications:inspect` 只输出编号/指纹/状态/时间及脱敏码，CLI 本身是部署运维信任边界，不输出 SMTP 配置、正文和目标地址。

### 契约变更与兼容边界

本批将原纯规划通知契约落地为单一 SMTP 渠道；新增两个收件人/上下文字段并放宽 user_id 可空，不改变唯一键和原状态枚举。迁移 2026_11_08_000006 可用于旧库与最新空库；不自动回滚带管理员/事件历史的字段。同步 schema/字典、架构、插件、接口、README、路线图、续费和部署说明。以前文档所称“邮件/TG 均未实现”仅描述旧基线，当前只有邮件已实现，TG/Web Push/其他未列明场景仍未实现。既有资金/权限/幂等与资源归属规则不变。

### 本批验证记录

- 完整隔离回归 632 项业务检查（原 560 + 旧库通知迁移 1 + 通知 71）通过，独立运行检查 25 项（原 22 + 邮件守护/恢复 3）通过；私有 PHP-FPM 配置缓存前后认证检查通过。新增覆盖四进程同指纹仅一次 DATA、收件人/归属/状态/旧账期/跨日失效、SMTP 接受丢响应不重发、认领后崩溃、慢 Worker 不覆盖恢复/人工终态、权限/证据/重复操作/审计失败回滚及配置密文。
- SMTP 隔离夹具实际执行 UTF-8 MIME 和成功/550 拒绝/接受 DATA 后断开；客户端不透明重试。真实阿里 SMTP 的 465 SMTPS 证书验证通过；用户授权的两封验收邮件均返回 SMTP_ACCEPTED，且用户随后确认“两封均已收到”；该确认只覆盖本次两封，不保证未来邮件必达或已读。
- 第一封来自 M2 原任务 3 的管理员告警，邮件投递 1 持久 success；task 3 仍待核验，未执行任务裁决或资金补偿。第二封来自独立 MySQL 的模拟临期实例，仅发给经确认测试邮箱，主题明确模拟；未改变真实实例到期日。独立库的投递凭证/数据库备份保留于私有状态目录，接受后清理临时容器。
- M2 已完成 000006 迁移，管理员原占位邮箱改为用户确认的邮箱并写审计，SMTP 配置仅密文存库。新增 jianyun-m2-mail.service 已启用；原金融 Worker、schedule.timer 保持 active，健康接口正常。管理员告警启用，真实客户 due_reminders=false，正式环境未部署。
- 部署前后 users/products/instances/invoices/transactions/payment_records/tasks 原行一致；只新增通知/配置/审计，管理员邮箱为本次明确变更。schema 仍为 27 表。无新增购买、续费、支付、退款、电源或上游操作；VNC 继续 d4f3102 直连边界。

### 客户主动密码重置邮件（2026-11-08）

复用同一 mail Worker 和通知表，第三个已实现场景为 `context.kind=password_reset`；指纹 `user:{id}:password_reset:{random_nonce}`。每次明确申请是独立事件，60 秒冷却和 IP/邮箱上限防滥用，不自动更换指纹重试未知发送。无需开启 due_reminders，但要求邮件总开关启用。重置 JWT 只以 AES-GCM 密文暂存在 pending context，认领/失效/终态/悬空恢复清 encrypted_token；发送前重查用户启用、原邮箱、认证版本及 15 分钟期限。邮件链接使用本站 fragment；不含账户密码/上游信息，不可当作主会话。改密后排队旧邮件跳过，已发邮件的旧链接失效。未知结果仍停发转核验，用户可在冷却后主动新申请。详见 [客户自助](M2_CUSTOMER_SELF_SERVICE.md)。
