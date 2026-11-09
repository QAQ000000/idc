# JianYun (简云) · 数据库设计字典 (Database Schema)

本系统全面丢弃旧魔方 178 张表的臃肿设计，收敛为高内聚、模块化的生产数据架构（全系统共 27 张物理表：8 张核心业务表 + 18 张支撑/配置/审计/下载表 + 1 张框架版本登记表）。全表基于 **MySQL 8.0+ InnoDB** 引擎，字符集为 `utf8mb4_unicode_ci`，金额统一使用 `DECIMAL(10,2)`。

> [!IMPORTANT]
> **全库时间列统一使用 `DATETIME`（不使用 TIMESTAMP）**：`TIMESTAMP` 存在 2038-01-19 的硬上限，会直接导致年付/多年付实例的到期日写入失败。时区语义由应用层统一约定（按 UTC 存取、展示层转换），数据库层不做隐式时区换算。
>
> **DDL 单一事实来源**：建表与变更以 [database/schema.sql](../database/schema.sql) 为唯一权威。本字典保留字段释义与领域约束；下方 27 段 `CREATE TABLE` 是从该文件同步生成的摘录，禁止独立修改。摘录不包含初始化设置与触发器，**不是完整初始化脚本**；资金账本与管理审计的五个保护触发器均以 schema 文件为准。

修改 schema 后运行 `python3 scripts/sync_schema_docs.py --write` 同步摘录；运行 `python3 scripts/sync_schema_docs.py --check` 检查完整 DDL（含 SQL 注释）、表名及重复/缺失表。省略参数同样只检查，不写文件。同步器仅替换各表 SQL 语句，保留正文说明；这些命令不连接数据库，也不执行迁移。

**状态查询优化（2026-11-09）**：重装 `view=tasks` 只投影现有 tasks 并读取原门禁；页面轮询窗口仅存在浏览器内存，不持久化运行状态或新建同步副本。27表／DDL／迁移保持，Worker 终态与凭据同步规则不变，见 [重装契约](M2_INSTANCE_REINSTALL.md)。

**Laravel 13 适配边界**：M0 已使用框架同连接事务和标准迁移运行器；新增 `migrations` 仅承载升级版本元数据，审查记录见 [TECH_STACK.md §7](TECH_STACK.md#7-m0-实施中的-schema-边界审查)。不安装默认 users/sessions/cache/jobs 表。`invoices.checkout_key/checkout_hash` 是原账单实体的幂等属性；可空兼容旧数据，摘要为 HMAC，唯一键按用户隔离。schema.sql 是空库完整结构，版本 PHP 迁移是已有库增量升级记录，禁止双份手写完整模型。

**充值与资金查询增量**：复用原 invoices.type=recharge、instance_id=NULL，checkout_key 使用 recharge: 命名空间、checkout_hash 为用途／金额 HMAC；payment_records 仍同时承载待付尝试和实际收款。transactions.trade_no 新入钱包流水关联原收款 trade_no，旧只追加事实不重写，查询按原键回溯。仅 account_type=wallet 进入本批钱包查询；不增财务汇总副本、实体、DDL 或迁移，27 表保持，见 [本批契约](M2_WALLET_FINANCE.md)。

**账单展示增量**：fulfillment_status、wallet_credit_total、late_credit_total 仅为原任务／流水的即时查询结果，不是数据库新列或汇总表。任务按本账单、动作及资源归属限定，钱包按本客户、账单及 wallet 分户限定；历史流水不改写，27 表与 DDL 保持，见 [第三批 API](API.md#第三批结账与账单展示增量)。

**充值唤醒增量**：Redis 集合只保存提交后待扫描的客户 ID，不新增账单、事件事实表、业务锁或汇总列。原 invoices CAS／周期唯一键、transactions 幂等键、users 钱包锁及 renew Outbox 事务继续裁决；丢失提示由 15 分钟 MySQL 扫描恢复。27 表与 DDL 不变，无迁移，见 [充值唤醒](M2_RENEWAL_CONTRACT.md#充值到账后的异步唤醒)。

**上游接入管理增量**：复用 providers 的字段级 credentials 密文与 admin_audit_logs。显示名称写入使用原行／配置审计的 HMAC 版本，创建重放指纹只存原审计；无版本列、连接副本、新表、DDL 或迁移，27 表保持。账号／地址／驱动不提供网页替换；见 [接入契约](M2_PROVIDER_CONNECTIONS.md)。

**管理员客户／实例查询增量**：只读关联 users/instances/products/providers 及原 invoices，财务和任务查询仅追加实例／客户筛选。无统计副本、新字段、索引、表、DDL 或迁移，27 表保持；密码、快照、密文和钱包金额不进入非财务投影，详见 [本批契约](M2_ADMIN_QUERIES.md)。

**退款字段交付边界（2026-11-08）**：退款由管理员手动办理，现有 `refund_*` 字段及退款用途说明不代表已实现自动退款、自动网关调用或人工登记接口。本次仅调整交付范围，27 表、DDL、触发器与历史数据均不变；退款证据、防重记账和审计要求见 [人工退款策略](PAYMENTS_LEDGER.md#manual-refund-policy)。

**运营后台第一批（2026-11-08）**：复用 admins、tasks、instances、invoices、payment_records 与 admin_audit_logs，27 表与 DDL 无变化，不需要迁移。admins.role 当前用于所有启用管理员只读、仅 super_admin 裁决；列表/详情不读取凭据或原始任务载荷，处理记录来自原审计表，不增加同步副本。登录仅签发 admin 作用域 Token，既有二次认证配置不能被绕过；接口边界见 [API](API.md#admin-operations)。

**商品运营增量（2026-11-08）**：复用 products.config_cache 记录默认配置 reference_costs（first/recurring）与 recurring_prices，旧 JSON 可缺，按 [商品 API](API.md#admin-products) 只读补取或拒绝猜价。复用 admin_audit_logs，商品版本由销售字段/快照指纹与最新商品审计构成，不新增版本表/字段或同步副本。无 DDL/迁移与历史快照重写，27 表保持。

**重装增量**：tasks.action 追加 reinstall，invoice_id=NULL，payload 冻结镜像／能力与原资源身份，不存明文密码；复用 payload 记录受理、monitor version 1、已观察执行及 execution_ended／verified 结果；当前魔方执行识别仅认结构化任务类型，不追补历史观察，复用实例 encrypted_password 与 delivery.credentials_verification 区分上游提供和已核验凭据。自动执行结束不写已确认镜像；无新增 DDL。27 表保持，已有库先执行 000008 再加载新 Worker；不删除历史枚举。详见 [重装契约](M2_INSTANCE_REINSTALL.md)。

**电源运维增量（2026-11-08）**：tasks.action 增加 power，invoice_id=NULL；payload 冻结命令、原资源绑定及请求客户，max_retries=0，沿用任务令牌/状态和管理员审计。无新表、实例状态副本或资金变更，27 表保持。已有库须执行 2026_11_08_000005 增量迁移；语义与兼容见 [电源契约](M2_INSTANCE_POWER.md)。

**本站 VNC 增量（2026-11-08）**：用户已改定独立云控制台直连，删除旧 Redis 单次连接票据/服务端中继，Redis 只保留签发限流；无 MySQL 新表/列、资金动作或任务，27 表保持。连接参数只返回给经主登录与归属/任务/资金门禁校验的本人；直连不具备本站强制撤销能力，见 [VNC 契约](M2_INSTANCE_VNC.md)。

**单邮件通知增量（2026-11-08）**：原 notification_deliveries 增加可空 admin_id/context，user_id 改为可空，服务只允许一个收件身份；context 冻结原任务令牌或实例完整到期日，不含 SMTP/上游凭据。唯一键及发送状态不变，仍为 27 表；SMTP 配置整体加密复用 system_settings，人工收口复用 admin_audit_logs。已有库执行 2026_11_08_000006，不重写历史资金/任务/资源。sent_at 是 SMTP 接受的本地确认时间，不证明终端送达；详情见 [通知契约](NOTIFICATIONS.md)。

**旧站数据库备份契约**：外置旧站插件必须按指定简云发布版本的完整 `database/schema.sql`（含初始化设置、外键、唯一键及保护触发器）生成 `schema.sql` 与目标表数据 `data.sql`，并附目标版本、schema 校验和及对账清单。SQL 恢复仅面向独立空库，不携带旧库查询或依赖新旧数据库同机，不默认覆盖或合并已有业务库；不为迁移增加物理表。本节 DDL 摘录不能作为完整恢复脚本。目标版本不匹配时重新导出并演练，细则见 [MIGRATION_RUNBOOK.md](MIGRATION_RUNBOOK.md)。

---

## 1. 全库表 DDL（8 张核心业务表 + 18 张支撑业务表 + 1 张框架版本登记表）

<a id="table-admins"></a>

### 1.1 `admins`（系统管理与运维员工档案）
```sql
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT '管理员登录名',
    `email` VARCHAR(100) NOT NULL UNIQUE COMMENT '安全邮箱',
    `password` VARCHAR(255) NOT NULL COMMENT '密码哈希 (Argon2id/Bcrypt)',
    `role` ENUM('super_admin', 'finance', 'support', 'operator') NOT NULL DEFAULT 'operator' COMMENT '后台管理角色',
    `two_factor_secret` VARCHAR(255) NULL COMMENT 'Google 2FA 密钥 (AES-256-GCM 加密存储, 严禁明文)',
    `status` ENUM('active', 'disabled') NOT NULL DEFAULT 'active' COMMENT '状态',
    `last_login_at` DATETIME NULL COMMENT '最后登录时间',
    `last_login_ip` VARCHAR(45) NULL COMMENT '最后登录IP',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统管理与运维员工表';
```

---

<a id="table-system-settings"></a>

### 1.2 `system_settings`（系统全局与插件动态配置表）
```sql
CREATE TABLE IF NOT EXISTS `system_settings` (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY COMMENT '配置键 (如 captcha_scenes, payment.alipay_f2f, notify.telegram, seo.config)',
    `group` VARCHAR(50) NOT NULL DEFAULT 'general' COMMENT '配置分组: general | captcha | payment | notification | seo',
    `value` JSON NULL COMMENT '非敏感公开配置 JSON',
    `encrypted_credentials` TEXT NULL COMMENT '敏感密钥 JSON 密文 (经 AES-256-GCM 整体或字段级信封加密)',
    `updated_by` INT UNSIGNED NULL COMMENT '最后修改管理员ID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`updated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
    INDEX `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统全局与插件动态配置表';
```

---

<a id="table-users"></a>

### 1.3 `users`（终端客户账户与钱包资产）
```sql
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL UNIQUE COMMENT '登录邮箱',
    `phone` VARCHAR(30) NULL UNIQUE COMMENT '手机号',
    `password` VARCHAR(255) NOT NULL COMMENT '密码哈希',
    `auth_version` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户认证版本，改密递增以撤销旧登录和重置凭据',
    `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '可用钱包余额',
    `frozen_commission` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '冻结中的推广佣金',
    `available_commission` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '可提现的推广佣金',
    `total_spent` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '历史累计消费金额 (原子累加)',
    `affiliate_code` VARCHAR(32) NULL UNIQUE COMMENT '用户专属推广邀请码',
    `affiliate_id` BIGINT UNSIGNED NULL COMMENT '推荐人 User ID',
    `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active' COMMENT '状态',
    `last_login_at` DATETIME NULL COMMENT '最后登录时间',
    `last_login_ip` VARCHAR(45) NULL COMMENT '最后登录IP',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`affiliate_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    INDEX `idx_affiliate_id` (`affiliate_id`),
    INDEX `idx_login_spent` (`last_login_at`, `total_spent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='终端客户账户与钱包表';
```

---

<a id="table-user-verifications"></a>

### 1.4 `user_verifications`（用户实名认证与法定公历生日档案）
```sql
CREATE TABLE IF NOT EXISTS `user_verifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL UNIQUE COMMENT '关联用户ID',
    `real_name` VARCHAR(50) NOT NULL COMMENT '真实姓名',
    `id_card_num` VARCHAR(255) NOT NULL COMMENT '身份证号(AES-256-GCM信封加密存储)',
    `id_card_hash` CHAR(64) NOT NULL UNIQUE COMMENT '身份证HMAC-SHA256盲索引(用于唯一性查询防作弊)',
    `id_card_birthday` DATE NOT NULL COMMENT '从身份证7-14位解析出的法定公历生日',
    `birthday_md` CHAR(5) GENERATED ALWAYS AS (DATE_FORMAT(`id_card_birthday`, '%m-%d')) STORED COMMENT '月-日生成列 (如 08-24)',
    `status` ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending' COMMENT '认证状态',
    `reject_reason` VARCHAR(255) NULL COMMENT '驳回原因',
    `verified_type` ENUM('manual', 'api_three_factor', 'api_face') NOT NULL DEFAULT 'manual' COMMENT '认证方式',
    `verified_at` DATETIME NULL COMMENT '认证通过时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_status_birthday_md` (`status`, `birthday_md`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户实名认证与法定生日档案';
```

---

<a id="table-providers"></a>

### 1.5 `providers`（上游服务提供方与自营节点）
```sql
CREATE TABLE IF NOT EXISTS `providers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL COMMENT '节点名称 (如: SakuraIDC上游 / 亚太自营PVE / Sub2API)',
    `driver` VARCHAR(50) NOT NULL COMMENT '驱动标识: zjmf | easypanel | sub2api | pve | manual',
    `endpoint` VARCHAR(255) NULL COMMENT '接口地址 URL',
    `credentials` JSON NULL COMMENT '认证密钥 JSON (值经 AES-256-GCM 加密保存 api_token / secret, 严禁明文落库与日志)',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用, 0禁用',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='上游提供方与自营节点';
```

---

<a id="table-product-categories"></a>

### 1.6 `product_categories`（产品分类与聚合落地页表）
```sql
CREATE TABLE IF NOT EXISTS `product_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT '分类名称 (如: 香港CN2云服务器 / 洛杉矶Cera大带宽)',
    `slug` VARCHAR(100) NOT NULL UNIQUE COMMENT 'SEO友好URL别名 (如: hk-cn2-cloud)',
    `type` ENUM('cloud', 'dedicated', 'cdn', 'hosting', 'software') NOT NULL DEFAULT 'cloud' COMMENT '业务类型',
    `headline` VARCHAR(150) NULL COMMENT '分类卖点标语',
    `description` TEXT NULL COMMENT '分类详情介绍与线路特点',
    `seo_title` VARCHAR(200) NULL COMMENT '分类独立SEO标题',
    `seo_keywords` VARCHAR(255) NULL COMMENT '分类独立SEO关键词',
    `seo_description` VARCHAR(500) NULL COMMENT '分类独立SEO描述',
    `order_num` INT NOT NULL DEFAULT 0 COMMENT '排序权重',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用, 0隐藏',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_type_status` (`type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='产品分类与聚合落地页表';
```

---

<a id="table-products"></a>

### 1.7 `products`（商品目录定义）
```sql
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED NULL COMMENT '所属分类/产品组',
    `provider_id` INT UNSIGNED NOT NULL COMMENT '关联 provider',
    `remote_pid` INT NOT NULL DEFAULT 0 COMMENT '上游商品PID (自营则为0)',
    `name` VARCHAR(100) NOT NULL COMMENT '商品名称',
    `type` ENUM('cloud', 'dedicated', 'cdn', 'hosting', 'software') NOT NULL DEFAULT 'cloud',
    `markup_type` ENUM('fixed', 'percent', 'none') NOT NULL DEFAULT 'percent' COMMENT '代理加价策略',
    `markup_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '加价值 (如 15.00 表示加价 15%)',
    `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '推广返佣比例 (如 10.00 表示返佣 10%, 0 表示不参与返佣)',
    `config_cache` JSON NULL COMMENT '上游规格与配置项 JSON 快照 (SWR 缓存使用)',
    `is_auto_setup` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否支付后自动交付',
    `stock` INT NOT NULL DEFAULT -1 COMMENT '本地库存缓存 (-1 表示不限或以远端为准)',
    `status` ENUM('active', 'hidden', 'retired') NOT NULL DEFAULT 'active' COMMENT '销售状态',
    `seo_title` VARCHAR(200) NULL COMMENT '单品独立SEO标题 (留空则继承分类+配置模板)',
    `seo_keywords` VARCHAR(255) NULL COMMENT '单品独立SEO关键词',
    `seo_description` VARCHAR(500) NULL COMMENT '单品独立SEO描述',
    `order_num` INT NOT NULL DEFAULT 0 COMMENT '排序权重',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品目录';
```

---

<a id="table-instances"></a>

### 1.8 `instances`（已售云实例与服务资产）
```sql
CREATE TABLE IF NOT EXISTS `instances` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '归属用户',
    `product_id` INT UNSIGNED NOT NULL COMMENT '关联商品',
    `provider_id` INT UNSIGNED NOT NULL COMMENT '关联提供方',
    `remote_instance_id` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT '远端实例 ID (如魔方的 hostid / PVE vmid)',
    `name` VARCHAR(100) NULL COMMENT '实例自定义名称/主机名',
    `ip_address` VARCHAR(100) NULL COMMENT '主 IP 地址',
    `encrypted_password` VARCHAR(255) NULL COMMENT '实例当前 Root/面板密码 (AES-256-GCM 密文)',
    `config_snapshot` JSON NULL COMMENT '当前实例规格配置快照',
    `billing_cycle` VARCHAR(20) NOT NULL DEFAULT 'monthly' COMMENT '付费周期',
    `recurring_price` DECIMAL(10,2) NOT NULL COMMENT '标准下次续费价格',
    `override_price` DECIMAL(10,2) NULL COMMENT '运维后台手动改价覆盖值',
    `status` ENUM('pending', 'active', 'suspended', 'terminated') NOT NULL DEFAULT 'pending',
    `suspend_type` ENUM('billing', 'compliance', 'abuse', 'manual') NOT NULL DEFAULT 'billing' COMMENT '暂停原因类型 (续费仅可自动解挂billing类型)',
    `suspend_reason` VARCHAR(255) NULL COMMENT '暂停原因详细描述',
    `auto_renew` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否开启自动续费',
    `start_date` DATETIME NULL DEFAULT NULL COMMENT '实际交付起租时间 (pending 未交付时为NULL)',
    `due_date` DATETIME NULL DEFAULT NULL COMMENT '到期时间 (pending 未交付时为NULL)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE RESTRICT,
    INDEX `idx_user_status` (`user_id`, `status`),
    INDEX `idx_due_date` (`status`, `due_date`),
    UNIQUE KEY `uk_provider_remote` (`provider_id`, `remote_instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='已购云资源实例';
```

M2 远端资源按 `(provider_id, remote_instance_id)` 区分大小写唯一，pending 允许 NULL。Provider 固定表示账户与资源命名空间，禁止原地更换账号/节点。增量迁移发现重复绑定立即拒绝升级，须先核对真实资产归属，不自动择一。

> [!NOTE]
> **Task-as-Lock 互斥设计**：`tasks` 是实例任务执行占用的单一事实来源。`running`、`investigating` 以及 `failed AND last_error LIKE 'MANUAL_AUDIT_REQUIRED:%'` 都阻断同实例其他动作，**不因租约过期解除业务阻断**。`lease_until` 仅决定 Worker 执行权、接管及回写资格；所有领取、接管和人工裁决先锁 `instances` 行，再锁任务。业务准入还须检查正常云消费主账单的 `refund_pending` 及已退款待销毁冻结；这些是退款领域状态，不复制为另一份任务锁。迟到/多收钱包款退款不修改父账单，不触发父实例冻结。详见 [TASKS_OUTBOX.md](TASKS_OUTBOX.md) §2.1/§2.4/§3。

---

<a id="table-instance-snapshot-schedules"></a>

### 1.9 `instance_snapshot_schedules`（云快照与备份自主计划任务）
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
    `last_risky_purge_at` DATETIME NULL COMMENT '最近一次单槽位高风险轮转时间',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用, 0暂停',
    `last_run_at` DATETIME NULL COMMENT '最近一次触发执行时间',
    `next_run_at` DATETIME NOT NULL COMMENT '下一次触发执行时间 (调度轮询使用)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_scheduler_poll` (`status`, `next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='快照与备份自主计划任务表';
```

---

<a id="table-instance-snapshots"></a>

### 1.10 `instance_snapshots`（云快照与备份归属凭证台账）
```sql
CREATE TABLE IF NOT EXISTS `instance_snapshots` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `instance_id` BIGINT UNSIGNED NOT NULL COMMENT '所属云实例ID',
    `schedule_id` BIGINT UNSIGNED NULL COMMENT '来源计划任务ID (手动/外部来源为NULL; 计划任务删除后转孤儿, 不参与自动清理)',
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '所属用户ID (冗余, 便于用户侧列表查询)',
    `type` ENUM('snapshot', 'backup') NOT NULL DEFAULT 'snapshot' COMMENT '操作类型: snapshot快照 / backup备份',
    `origin` ENUM('manual', 'scheduled') NOT NULL DEFAULT 'manual' COMMENT '产生来源: manual手动 / scheduled计划任务 (配额全额计数模型依赖此字段区分)',
    `remote_snapshot_id` VARCHAR(100) NULL COMMENT '上游实例内该类型的快照/备份标识，创建未决时为NULL；清理使用本地台账ID',
    `size_bytes` BIGINT UNSIGNED NULL COMMENT '快照/备份大小 (字节, 上游可查时回填)',
    `status` ENUM('creating', 'active', 'deleting', 'deleted', 'failed') NOT NULL DEFAULT 'creating' COMMENT '生命周期: creating创建中 / active可用 / deleting删除中 / deleted已删除墓碑 / failed创建失败',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`schedule_id`) REFERENCES `instance_snapshot_schedules` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_instance_type_remote_snapshot` (`instance_id`, `type`, `remote_snapshot_id`),
    INDEX `idx_schedule_cleanup` (`schedule_id`, `status`, `created_at`),
    INDEX `idx_instance_type_status` (`instance_id`, `type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='云快照与备份归属凭证台账 (清理候选严格按schedule_id查询, 严禁模糊名称匹配)';
```
> [!NOTE]
> **快照归属凭证（LIFECYCLE_PRICING 协议 §5.2）**：远端标识仅在 `(instance_id, type)` 内唯一，不能假定不同 Provider、实例或 snapshot/backup 类型共用全局编号。创建前预占 `creating` 台账，`remote_snapshot_id = NULL`，并在同一事务插入稳定计划时刻键的 `snapshot_create` / `backup_create` 任务、推进调度时刻；任务唯一键冲突时整笔回滚，避免重复台账。重试复用原任务与台账，成功后原子回填远端 ID。删除任务统一以本地台账主键生成 `snapshot_delete:{instance_snapshots.id}`，payload 固化 `snapshot_id/provider_id/instance_id/type/remote_instance_id/remote_snapshot_id`，执行前逐项核验归属。滚动清理候选**严格仅限** `schedule_id` 匹配的历史记录，严禁模糊名称匹配。`origin` 用于 Manual + Auto + Other 全额配额计数；计划删除后孤儿台账（`schedule_id = NULL`）不参与自动清理。旧库升级见 `database/migrations/002_snapshot_identity.sql`。

---

<a id="table-invoices"></a>

### 1.11 `invoices`（账单与结算）
```sql
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '客户 ID',
    `instance_id` BIGINT UNSIGNED NULL COMMENT '关联实例 ID',
    `type` ENUM('new', 'renew', 'upgrade', 'recharge') NOT NULL COMMENT '账单类型',
    `total` DECIMAL(10,2) NOT NULL COMMENT '账单总额',
    `status` ENUM('unpaid', 'paid', 'refund_pending', 'refunded', 'cancelled') NOT NULL DEFAULT 'unpaid',
    `cycle_marker` VARCHAR(50) NULL COMMENT '防重标记 (如 instance_id:2026-12-01)',
    `price_snapshot` JSON NULL COMMENT '下单时锁定的规格配置明细、周期、基准价与折扣快照',
    `quote_expired_at` DATETIME NULL COMMENT '报价锁定期截止时间 (超时需重新核价)',
    `checkout_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '用户范围下单请求键，旧客户端可空',
    `checkout_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '规范请求的 HMAC 摘要，不存密码明文',
    `payment_method` VARCHAR(50) NULL COMMENT '最终支付通道',
    `paid_at` DATETIME NULL COMMENT '付款时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_cycle_instance` (`cycle_marker`),
    UNIQUE KEY `uk_user_checkout` (`user_id`, `checkout_key`),
    INDEX `idx_status_due` (`status`, `created_at`),
    INDEX `idx_user_status_created` (`user_id`, `status`, `created_at`),
    INDEX `idx_instance_type` (`instance_id`, `type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账单结算表';
```
> [!NOTE]
> **`cycle_marker` 唯一键对 NULL 的依赖与释放规则**：MySQL 唯一索引允许多行 NULL，因此非周期类账单（新购/充值）`cycle_marker` 为 NULL 不占位，续费/变配账单通过唯一标记实现数据库级防重。当周期账单被取消（`status = 'cancelled'`）或因撞上不可逆销毁而退回余额（SC-L02 `status = 'refunded'`）时，必须同步执行 `SET cycle_marker = NULL` 释放唯一槽位，以便同一周期内重新出账。请勿将其"修复"为 NOT NULL。

---

<a id="table-payment-records"></a>

### 1.12 `payment_records`（支付尝试与实收凭证流水表）
```sql
CREATE TABLE IF NOT EXISTS `payment_records` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `channel` VARCHAR(50) NOT NULL COMMENT '支付通道: alipay | epay | balance | wechat',
    `trade_no` VARCHAR(100) NOT NULL UNIQUE COMMENT '系统内部收单流水号',
    `gateway_trade_no` VARCHAR(100) NULL COMMENT '第三方支付网关交易单号',
    `amount` DECIMAL(10,2) NOT NULL COMMENT '实际收款金额',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'CNY',
    `status` ENUM('pending', 'success', 'failed', 'late_credited_to_balance') NOT NULL DEFAULT 'pending',
    `refund_status` ENUM('none', 'pending', 'success', 'failed') NOT NULL DEFAULT 'none' COMMENT '退款状态',
    `refund_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '已退款金额',
    `refund_reason` VARCHAR(255) NULL COMMENT '退款原因',
    `refund_trade_no` VARCHAR(128) NULL COMMENT '网关退款批次/流水号',
    `refunded_at` DATETIME NULL COMMENT '退款成功时间',
    `paid_at` DATETIME NULL,
    `payload` JSON NULL COMMENT '网关异步回调原始报文快照',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    UNIQUE KEY `uk_channel_gateway_trade` (`channel`, `gateway_trade_no`),
    INDEX `idx_invoice_status` (`invoice_id`, `status`),
    INDEX `idx_refund_status` (`refund_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付尝试与实收凭证流水表';
```

---

<a id="table-transactions"></a>

### 1.13 `transactions`（资金全账本明细流水）
```sql
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `invoice_id` BIGINT UNSIGNED NULL,
    `account_type` ENUM('wallet', 'available_commission', 'frozen_commission') NOT NULL DEFAULT 'wallet' COMMENT '资金分户',
    `type` ENUM('recharge', 'pay_invoice', 'refund', 'refund_rollback', 'refund_to_balance', 'commission_settle', 'commission_clawback', 'affiliate_to_balance', 'withdrawal_freeze', 'withdrawal_payout', 'withdrawal_reject_rollback', 'late_payment_credit') NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL COMMENT '变动金额 (正数为增，负数为扣)',
    `balance_before` DECIMAL(10,2) NOT NULL COMMENT '变动前账户分户余额',
    `balance_after` DECIMAL(10,2) NOT NULL COMMENT '变动后账户分户余额',
    `idempotency_key` VARCHAR(100) NOT NULL UNIQUE COMMENT '资金流水幂等防重键',
    `trade_no` VARCHAR(100) NULL COMMENT '关联外部/内部交易单号',
    `remark` VARCHAR(255) NOT NULL COMMENT '流水摘要说明',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
    INDEX `idx_user_account` (`user_id`, `account_type`, `created_at`),
    CONSTRAINT `chk_ledger_balance` CHECK (`balance_after` = `balance_before` + `amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资金全账本变动流水';

-- 内核级只追加与平衡强制触发器：
-- trg_transactions_check_balance (INSERT 时强制 balance_after = balance_before + amount)
-- trg_transactions_no_update (物理拦截 UPDATE)
-- trg_transactions_no_delete (物理拦截 DELETE)
```
> [!NOTE]
> **`refund_to_balance` 流水类型**：既用于 [LIFECYCLE_PRICING.md](LIFECYCLE_PRICING.md) §2.1 的 SC-L02（已实收续费款被不可逆销毁阻断，款项转入钱包），也用于 [PAYMENTS_LEDGER.md](PAYMENTS_LEDGER.md) §3.3 的正常云消费退款至钱包。前者凭证用途为 `late_wallet_credit`、记录 `credit_reason='termination_blocked_renewal'`，后者为 `cloud_consumption`；不能仅凭流水类型推断实例销毁或佣金冲正资格。未实际扣款的自动代扣不产生退款入账。

---

<a id="table-tasks"></a>

### 1.14 `tasks`（事务发件箱与幂等任务队列）
```sql
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `instance_id` BIGINT UNSIGNED NOT NULL,
    `invoice_id` BIGINT UNSIGNED NULL,
    `idempotency_key` VARCHAR(100) NOT NULL UNIQUE COMMENT '业务幂等防重键 (如 instance_create:inv_1024)',
    `action` ENUM('create', 'renew', 'upgrade', 'suspend', 'unsuspend', 'terminate', 'snapshot_create', 'snapshot_delete', 'backup_create', 'backup_delete', 'power', 'reinstall') NOT NULL,
    `payload` JSON NULL COMMENT '固化的任务执行参数 (如确定的目标到期日 target_due_date)',
    `status` ENUM('pending', 'running', 'investigating', 'success', 'failed') NOT NULL DEFAULT 'pending' COMMENT 'investigating: 超时结果反查中',
    `worker_id` VARCHAR(50) NULL COMMENT '抢占此任务的 Worker 标识',
    `execution_token` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '单调递增防护令牌 (Fencing Token, 防止脑裂/旧Worker延迟回写)',
    `lease_until` DATETIME NULL COMMENT '分布式执行租约截止时间 (超时允许其他Worker安全接管)',
    `upstream_task_id` VARCHAR(100) NULL COMMENT '上游异步处理流水号',
    `retry_count` INT NOT NULL DEFAULT 0 COMMENT '已重试次数',
    `max_retries` INT NOT NULL DEFAULT 5 COMMENT '最大重试次数',
    `next_retry_at` DATETIME NULL COMMENT '下一次重试时间',
    `last_error` TEXT NULL COMMENT '最近一次报错信息',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
    INDEX `idx_invoice_id` (`invoice_id`),
    INDEX `idx_poll_lease` (`status`, `lease_until`, `next_retry_at`),
    INDEX `idx_instance_lease` (`instance_id`, `status`, `lease_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='事务发件箱与幂等任务队列';
```
> [!NOTE]
> **`idx_instance_lease` 索引**：互斥检查先按 `instance_id` 限定，再判断 `status IN ('running','investigating') OR (status='failed' AND last_error LIKE 'MANUAL_AUDIT_REQUIRED:%')`，不得用租约是否过期过滤未裁决任务。`lease_until` 用于接管轮询和执行权校验。所有检查均遵守先实例行锁、再任务行锁的顺序。

---

<a id="table-commissions"></a>

### 1.15 `commissions`（推荐返利明细与退款联动）
```sql
CREATE TABLE IF NOT EXISTS `commissions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `affiliate_user_id` BIGINT UNSIGNED NOT NULL COMMENT '推荐人 A',
    `buyer_user_id` BIGINT UNSIGNED NOT NULL COMMENT '买家 B',
    `invoice_id` BIGINT UNSIGNED NOT NULL COMMENT '触发返利的账单',
    `amount` DECIMAL(10,2) NOT NULL COMMENT '提成金额',
    `freeze_until` DATETIME NOT NULL COMMENT '冻结到期时间 (统一规范为付款后15天无理由退款期)',
    `status` ENUM('pending', 'available', 'cancelled', 'clawbacked') NOT NULL DEFAULT 'pending',
    `remark` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`affiliate_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
    UNIQUE KEY `uk_invoice_affiliate` (`invoice_id`, `affiliate_user_id`),
    INDEX `idx_affiliate_created` (`affiliate_user_id`, `status`, `created_at`),
    INDEX `idx_freeze_poll` (`status`, `freeze_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='推广返佣明细';
```

---

<a id="table-commission-withdrawals"></a>

### 1.16 `commission_withdrawals`（推广返佣提现申请表）
```sql
CREATE TABLE IF NOT EXISTS `commission_withdrawals` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '申请提现的用户ID',
    `amount` DECIMAL(10,2) NOT NULL COMMENT '提现金额',
    `destination` ENUM('balance', 'external') NOT NULL COMMENT '提现目标: balance=转入站内可用余额, external=站外转账',
    `channel_type` ENUM('alipay', 'bank', 'manual') NOT NULL DEFAULT 'alipay' COMMENT '站外提现渠道',
    `account_name` VARCHAR(100) NULL COMMENT '收款人姓名/开户名',
    `account_no` VARCHAR(255) NULL COMMENT '收款账号 (支付宝账号/银行卡号, AES-256-GCM 加密存储)',
    `channel_info` TEXT NULL COMMENT '站外提现备注/附加信息 (如开户支行等)',
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT '审批状态',
    `admin_id` INT UNSIGNED NULL COMMENT '经办/审核管理员ID',
    `admin_remark` VARCHAR(255) NULL COMMENT '转账流水号或驳回原因',
    `processed_at` DATETIME NULL COMMENT '处理/打款时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
    INDEX `idx_status_created` (`status`, `created_at`),
    INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='推广返佣提现申请表';
```

---

<a id="table-notification-deliveries"></a>

### 1.17 `notification_deliveries`（通知投递与渠道级幂等防重）
```sql
CREATE TABLE IF NOT EXISTS `notification_deliveries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fingerprint` VARCHAR(100) NOT NULL COMMENT '业务消息唯一指纹',
    `channel` VARCHAR(30) NOT NULL COMMENT '渠道标识 (如 mail, sms, telegram)',
    `user_id` BIGINT UNSIGNED NULL COMMENT '客户收件人 ID (与 admin_id 二选一，发送前重查)',
    `admin_id` INT UNSIGNED NULL COMMENT '管理员收件人 ID (与 user_id 二选一，发送前重查)',
    `context` JSON NULL COMMENT '事件身份快照 (kind、原任务令牌或实例到期日/提醒日，不含消息正文及密钥)',
    `target` VARCHAR(100) NOT NULL COMMENT '投递目标 (邮箱/手机号/ChatID)',
    `status` ENUM('pending', 'sending', 'success', 'failed', 'pending_manual_check') NOT NULL DEFAULT 'pending',
    `error_message` VARCHAR(255) NULL COMMENT '失败报错信息',
    `sent_at` DATETIME NULL COMMENT '实际送达时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_fingerprint_channel` (`fingerprint`, `channel`),
    INDEX `idx_user_created` (`user_id`, `created_at`),
    INDEX `idx_status_updated` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知投递与幂等审计表';
```

---

<a id="table-web-push-subscriptions"></a>

### 1.18 `web_push_subscriptions`（浏览器 Web Push 设备订阅）
```sql
CREATE TABLE IF NOT EXISTS `web_push_subscriptions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '归属用户',
    `endpoint` TEXT NOT NULL COMMENT '浏览器推送网关 Endpoint URL',
    `endpoint_hash` CHAR(64) NOT NULL COMMENT 'Endpoint SHA256 哈希 (用于唯一索引)',
    `p256dh_key` VARCHAR(255) NOT NULL COMMENT '客户端公钥 P256DH',
    `auth_token` VARCHAR(255) NOT NULL COMMENT '客户端鉴权密钥 Auth Secret (AES-256-GCM 加密存储)',
    `browser` VARCHAR(30) NULL COMMENT '浏览器类型: chrome | edge | safari | firefox',
    `device_name` VARCHAR(50) NULL COMMENT '设备别名 (如 Mac Chrome, iPhone Safari)',
    `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active' COMMENT '订阅状态',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_endpoint_hash` (`endpoint_hash`),
    INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebPush浏览器订阅表';
```

---

<a id="table-tickets"></a>

### 1.19 `tickets`（客服工单）
```sql
CREATE TABLE IF NOT EXISTS `tickets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '提交人',
    `instance_id` BIGINT UNSIGNED NULL COMMENT '关联实例',
    `assigned_admin_id` INT UNSIGNED NULL COMMENT '当前承办管理员ID',
    `title` VARCHAR(150) NOT NULL COMMENT '工单主题',
    `department` VARCHAR(50) NOT NULL DEFAULT 'general' COMMENT '部门 (技术/财务/售前)',
    `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    `status` ENUM('open', 'in_progress', 'answered', 'customer_replied', 'closed') NOT NULL DEFAULT 'open',
    `closed_at` DATETIME NULL COMMENT '工单完结时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`assigned_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
    INDEX `idx_user_status` (`user_id`, `status`),
    INDEX `idx_assigned_status` (`assigned_admin_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单表';
```

---

<a id="table-ticket-replies"></a>

### 1.20 `ticket_replies`（工单协同与回复）
```sql
CREATE TABLE IF NOT EXISTS `ticket_replies` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NULL COMMENT '客户ID (客户回复时必填)',
    `admin_id` INT UNSIGNED NULL COMMENT '管理员ID (管理员回复或内部便签时必填)',
    `type` ENUM('reply', 'internal_note', 'transfer_log') NOT NULL DEFAULT 'reply' COMMENT '消息类型: reply客户可见回复 / internal_note内部私有便签 / transfer_log转派流水',
    `transfer_to_admin_id` INT UNSIGNED NULL COMMENT '转派目标管理员ID',
    `content` TEXT NOT NULL,
    `request_key` CHAR(36) NULL COMMENT '作者作用域的提交UUID，历史行可空',
    `request_hash` CHAR(64) NULL COMMENT '提交动作与正文指纹',
    `attachments` JSON NULL COMMENT '附件文件路径列表',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ticket_user_request` (`user_id`, `request_key`),
    UNIQUE KEY `uk_ticket_admin_request` (`admin_id`, `request_key`),
    FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`transfer_to_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单回复表';
```
> [!NOTE]
> 回复人身份由 `user_id`（客户）或 `admin_id`（管理员）二选一承载，应用层必须保证二者至少其一非空；不再使用旧版 `role` 枚举字段，以避免身份字段与外键脱节。

---

<a id="table-coupons"></a>

### 1.21 `coupons`（统一促销与优惠券模板）
```sql
CREATE TABLE IF NOT EXISTS `coupons` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT '优惠券/码名称 (如: 新人礼券 / 2024迎新9折)',
    `code` VARCHAR(50) NULL UNIQUE COMMENT '口令码 (NULL表示专属/定向卡券，填值表示公开优惠码)',
    `type` ENUM('percent', 'fixed') NOT NULL COMMENT '折扣类型: percent打折 / fixed立减',
    `value` DECIMAL(10,2) NOT NULL COMMENT '折扣值: percent类型填折扣百分比 0.01~99.99 (如 15.00 表示打85折立减15%, 20.00 表示打8折立减20%); fixed类型填立减金额 (如 20.00 表示立减20元)',
    `min_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '最低消费门槛 (0为无门槛)',
    `target_type` ENUM('all', 'product', 'category') NOT NULL DEFAULT 'all' COMMENT '适用范围',
    `target_id` INT UNSIGNED NULL COMMENT '绑定的商品ID或分类ID',
    `apply_cycle` ENUM('once', 'recurring') NOT NULL DEFAULT 'once' COMMENT '首期优惠 / 终身循环续费优惠',
    `max_uses` INT NOT NULL DEFAULT -1 COMMENT '全系统总使用次数 (-1为不限)',
    `max_uses_per_user` INT NOT NULL DEFAULT 1 COMMENT '单用户限用次数',
    `used_times` INT NOT NULL DEFAULT 0 COMMENT '已使用次数',
    `is_stackable` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否允许与其他优惠叠加 (默认0禁止)',
    `expire_at` DATETIME NULL COMMENT '绝对过期时间 (主要用于公开优惠码)',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用, 0禁用',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优惠券与促销模板表';
```

---

<a id="table-user-coupons"></a>

### 1.22 `user_coupons`（用户卡券资产包）
```sql
CREATE TABLE IF NOT EXISTS `user_coupons` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '所属用户',
    `coupon_id` INT UNSIGNED NOT NULL COMMENT '关联优惠券模板',
    `source_type` ENUM('campaign', 'admin_grant', 'code_redeem', 'event_register') NOT NULL DEFAULT 'campaign' COMMENT '发放来源',
    `source_id` INT UNSIGNED NULL COMMENT '关联的营销策略ID或管理员ID',
    `status` ENUM('unused', 'used', 'expired') NOT NULL DEFAULT 'unused' COMMENT '状态',
    `start_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '生效时间',
    `expire_at` DATETIME NOT NULL COMMENT '失效时间 (动态计算生成)',
    `used_at` DATETIME NULL COMMENT '使用核销时间',
    `order_invoice_id` BIGINT UNSIGNED NULL COMMENT '核销的账单ID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
    INDEX `idx_user_status_expire` (`user_id`, `status`, `expire_at`),
    INDEX `idx_source` (`source_type`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户卡券资产包';
```

---

<a id="table-coupon-issue-logs"></a>

### 1.23 `coupon_issue_logs`（优惠券发放幂等与审计日志）
```sql
CREATE TABLE IF NOT EXISTS `coupon_issue_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '目标用户ID',
    `coupon_id` INT UNSIGNED NOT NULL COMMENT '关联优惠券模板ID',
    `user_coupon_id` BIGINT UNSIGNED NULL COMMENT '生成的卡券包资产ID',
    `action_type` ENUM('register_gift', 'birthday_gift', 'winback_gift', 'admin_grant') NOT NULL COMMENT '发券业务类型',
    `cycle_marker` VARCHAR(100) NOT NULL COMMENT '防重周期标记 (如 reg:user_42, bday:user_42:2026, winback:user_42:202611)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_user_action_cycle` (`cycle_marker`),
    INDEX `idx_user_action_time` (`user_id`, `action_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优惠券发放幂等与审计日志';
```

---

<a id="table-articles"></a>

### 1.24 `articles`（统一公告与帮助中心）
```sql
CREATE TABLE IF NOT EXISTS `articles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('news', 'help') NOT NULL DEFAULT 'news' COMMENT 'news: 新闻公告, help: 帮助文档',
    `category` VARCHAR(50) NOT NULL DEFAULT '默认分类' COMMENT '分类名称',
    `title` VARCHAR(200) NOT NULL COMMENT '文章标题',
    `slug` VARCHAR(150) NULL UNIQUE COMMENT 'SEO友好URL别名 (如 centos7-install-bt)',
    `content` LONGTEXT NOT NULL COMMENT '文章内容 (HTML 富文本格式)',
    `seo_title` VARCHAR(200) NULL COMMENT '自定义SEO标题 (留空则默认使用title)',
    `seo_keywords` VARCHAR(255) NULL COMMENT '自定义SEO关键词 (长尾词挖掘)',
    `seo_description` VARCHAR(500) NULL COMMENT '自定义SEO摘要/描述',
    `related_product_id` INT UNSIGNED NULL COMMENT '教程内嵌推荐商品ID (长尾词商业变现)',
    `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '阅读量/点击量',
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否置顶',
    `status` ENUM('published', 'draft') NOT NULL DEFAULT 'published',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`related_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
    INDEX `idx_type_status` (`type`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章与帮助文档中心';
```

---

<a id="table-admin-audit-logs"></a>

### 1.25 `admin_audit_logs`（管理员高危操作审计日志）
```sql
CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT UNSIGNED NOT NULL COMMENT '操作管理员ID',
    `action` VARCHAR(100) NOT NULL COMMENT '动作标识 (如 task.force_retry / instance.price_override / invoice.refund.approve)',
    `target_type` VARCHAR(50) NULL COMMENT '目标资源类型 (instance / invoice / user / task 等, 多态关联不设外键)',
    `target_id` BIGINT UNSIGNED NULL COMMENT '目标资源ID',
    `context` JSON NULL COMMENT '结构化操作上下文 (如 force-retry 人工核验工单号、改价前后价格、退款原因)',
    `ip` VARCHAR(45) NULL COMMENT '操作来源IP',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
    INDEX `idx_admin_created` (`admin_id`, `created_at`),
    INDEX `idx_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员高危操作审计日志 (仅追加, UPDATE/DELETE被触发器物理拦截)';
```
> [!NOTE]
> **Append-only 高危操作审计**：与 `transactions` 资金账本同级，由数据库触发器物理拦截 UPDATE / DELETE（见 `database/schema.sql` 尾部）。管理员改价、退款审批、实名审核、提现打款、任务 `force-retry`（TASKS_OUTBOX 协议 §3.3 要求显式提交的人工核验工单号落入 `context` 字段）等动作必须落审计。`action` 采用开放 `VARCHAR` 而非 ENUM：审计动作集合随管理端功能持续增长，禁止因枚举迁移阻断审计写入。

---

<a id="table-downloads"></a>

### 1.26 `downloads`（资源与工具下载中心表）
```sql
CREATE TABLE IF NOT EXISTS `downloads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category` VARCHAR(50) NOT NULL DEFAULT '常用工具' COMMENT '分类名称 (如: 远程连接 / 常用工具 / 专属驱动)',
    `title` VARCHAR(150) NOT NULL COMMENT '资源名称',
    `description` VARCHAR(500) NULL COMMENT '资源简介或提取码说明 (严禁存放真实生产明文密码)',
    `source_type` ENUM('upload', 'external') NOT NULL DEFAULT 'upload' COMMENT '来源类型: upload=后台上传文件, external=外部网盘/直链',
    `file_path` VARCHAR(255) NOT NULL COMMENT '本地存储相对路径(如 downloads/202611/putty.exe) 或 外部完整URL',
    `file_name` VARCHAR(150) NULL COMMENT '原始文件名 (用于下载时 Content-Disposition 响应头)',
    `file_size` BIGINT UNSIGNED NULL COMMENT '文件字节大小 (后台上传时自动计算)',
    `file_sha256` CHAR(64) NULL COMMENT '文件 SHA256 校验值 (上传时自动生成，供客户校验防篡改)',
    `access_level` ENUM('public', 'login_required', 'active_instance', 'product_bound') NOT NULL DEFAULT 'public' COMMENT '下载权限: 公开 | 需登录 | 需有在跑实例 | 需已购指定商品',
    `bound_product_id` INT UNSIGNED NULL COMMENT '当 access_level=product_bound 时绑定的商品ID',
    `downloads_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计下载次数 (原子累加)',
    `order_num` INT NOT NULL DEFAULT 0 COMMENT '排序权重 (越大越靠前)',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用, 0隐藏',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`bound_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
    INDEX `idx_status_cat_order` (`status`, `category`, `order_num` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资源与工具下载中心表';
```
> [!NOTE]
> **下载权限控制与高安全分发规范**：
> 1. **存储目录代码隔离**：后台上传文件必须存放于非 Web 根目录的 `storage/downloads/{YYYYMM}/{uuid}.{ext}`，严禁直接上传至 `public/`，杜绝文件伪装 RCE 与爬虫扫库。
> 2. **下载权限控制 (`access_level`)**：
>    - `public`：访客免登录公开下载；
>    - `login_required`：必须携带有效客户 Token 下载；
>    - `active_instance`：名下必须拥有至少一台状态为 `active` 的实例；
>    - `product_bound`：名下必须拥有 `bound_product_id` 指定商品的有效在跑实例（专属镜像、专属驱动分发场景）。
> 3. **Nginx `X-Accel-Redirect` 高性能分发**：对于本地 `upload` 文件，PHP 在完成鉴权与 `downloads_count = downloads_count + 1` 原子行锁累加后，返回 `X-Accel-Redirect` 响应头交由 Nginx 内核直接流式传输，零 PHP 内存损耗；外部 `external` 资源则直接执行 `302` 重定向。

<a id="table-migrations"></a>

### 1.27 `migrations`（Laravel 基础设施版本登记）

仅供标准迁移运行器记录文件名及批次。新增此表经过 TECH_STACK §7 边界审查，不增加业务实体；会话、缓存和 Laravel 普通队列表不安装。

```sql
CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='框架 schema 迁移版本登记表';
```

## 固定套餐续费实现说明

本批复用 instances 的 auto_renew、due_date、recurring_price/override_price、suspend_type，invoices 的 cycle_marker/price_snapshot 及 tasks 的 renew 枚举；仍为 27 张物理表，无新增 schema 迁移。周期标记为 `r:{instance_id}:{原到期日YYYYmmddHHMMSS}`，已付周期保留唯一凭证；取消/销毁保护退款清空标记。续费 price_snapshot 固化原/目标本地日期和独立上游原/目标日期，任务再冻结 needs_unsuspend；不把驱动凭据复制到快照。完整语义见 [续费契约](M2_RENEWAL_CONTRACT.md)。


### M2 配置销售 JSON 边界（2026-11-08）

复用 products.config_cache 的 schema/configurable/default_config/prices/order_namespace；prices 是默认选择参考售价。instances.config_snapshot 与 invoices.price_snapshot 冻结 config_options、可读 configuration、upstream_total/upstream_recurring、total/recurring_price、加价规则与 driver_context。instances.recurring_price 使用独立续费售价，不再把含初装费首期价写成续费价。已有固定快照缺少新字段时保留旧语义；无物理表、字段或迁移变化。完整约束见 [配置销售契约](M2_CONFIG_SALES.md)。

### 客户认证版本增量（2026-11-08）

`users.auth_version` 为认证撤销事实，000007 迁移在旧库增加默认 0 的 BIGINT UNSIGNED；密码更新与版本递增在同一用户行锁事务。兼容旧无 av 登录仅限版本 0，禁止自动回滚删除已有撤销记录。复用 notification_deliveries 的 pending context 密文暂存短效重置凭据，认领及终态清理，无新增表，仍 27 张。设计原因、影响模块及证据见 [客户自助](M2_CUSTOMER_SELF_SERVICE.md)。

### 管理员续费改价 JSON 与事务边界（2026-11-09）

复用 instances.recurring_price（原冻结价）和可空override_price（覆盖价），不追随当前商品价；未付renew账单作废与cycle_marker=NULL、审计同事务。admin_audit_logs.action=instance_renewal_price，context仅存evidence、before／after三种价格及cancelled_invoice_ids，最新审计ID参与版本以拒绝ABA。已付账单、原账期与资源不改写，27表／DDL保持，无迁移，见 [改价契约](M2_INSTANCE_RENEWAL_PRICE.md)。

### 工单提交身份增量（2026-11-09）

ticket_replies新增可空request_key／request_hash，以(user_id,request_key)与(admin_id,request_key)唯一；首条回复标识提单，后续公开回复／关闭也复用原实体，旧NULL行保持。客户与管理员作者二选一，查询只投影归属一致的type=reply；后台回复和结单同事务写ticket.reply／ticket.close审计（reply_id及前后状态，无正文副本）。无需新增表或单独幂等表；旧库先迁移000009，27表保持，见 [工单契约](M2_SUPPORT_TICKETS.md)。


### 工单邮件上下文与启用边界（2026-11-09）

原notification_deliveries.context增加kind=ticket_update的使用：只存ticket_id／reply_id／customer_id／原作者身份／activation，不复制正文；原user_id或admin_id二选一与target冻结收件人。fingerprint包含原回复ID和收件身份，原channel唯一键继续防重。system_settings.key=notify.ticket_mail仅存显式开关／管理员列表／服务端订阅期／UTC起点，SMTP密文仍唯一存notify.mail。普通消息与占位同事务、结单分支不占位，无历史消息扫描；无新DDL或迁移，仍27表，见[工单邮件契约](M2_TICKET_MAIL.md)。

### 邮件配置后台（2026-11-09）

复用 system_settings 的 notify.mail／notify.ticket_mail 与 admin_audit_logs；版本为原配置及对应审计序号的服务端签名，不加列。SMTP 元数据可由超级管理员读取，password 仅配置标志，整体密文不返回；审计仅记录公开配置、来源及密码保留／替换意图。工单起点仍唯一存在原订阅配置，普通保存不更新。27 表、DDL 及迁移均不变，见 [契约](M2_MAIL_SETTINGS.md)。
