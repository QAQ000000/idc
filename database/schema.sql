-- JianYun 基础建表脚本 (MySQL 8.0+)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL UNIQUE COMMENT '登录邮箱',
    `phone` VARCHAR(30) NULL UNIQUE COMMENT '手机号',
    `password` VARCHAR(255) NOT NULL COMMENT '密码哈希',
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

CREATE TABLE IF NOT EXISTS `tasks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `instance_id` BIGINT UNSIGNED NOT NULL,
    `invoice_id` BIGINT UNSIGNED NULL,
    `idempotency_key` VARCHAR(100) NOT NULL UNIQUE COMMENT '业务幂等防重键 (如 instance_create:inv_1024)',
    `action` ENUM('create', 'renew', 'upgrade', 'suspend', 'unsuspend', 'terminate', 'snapshot_create', 'snapshot_delete', 'backup_create', 'backup_delete', 'power') NOT NULL,
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

CREATE TABLE IF NOT EXISTS `notification_deliveries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fingerprint` VARCHAR(100) NOT NULL COMMENT '业务消息唯一指纹',
    `channel` VARCHAR(30) NOT NULL COMMENT '渠道标识 (如 mail, sms, telegram)',
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '接收用户 ID',
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

CREATE TABLE IF NOT EXISTS `ticket_replies` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NULL COMMENT '客户ID (客户回复时必填)',
    `admin_id` INT UNSIGNED NULL COMMENT '管理员ID (管理员回复或内部便签时必填)',
    `type` ENUM('reply', 'internal_note', 'transfer_log') NOT NULL DEFAULT 'reply' COMMENT '消息类型: reply客户可见回复 / internal_note内部私有便签 / transfer_log转派流水',
    `transfer_to_admin_id` INT UNSIGNED NULL COMMENT '转派目标管理员ID',
    `content` TEXT NOT NULL,
    `attachments` JSON NULL COMMENT '附件文件路径列表',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`transfer_to_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单回复表';

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

-- =========================================================================
-- 物理强制触发器：强制保证 transactions 资金流水账本
-- 1. balance_after = balance_before + amount (兼容 MySQL 5.7 与 8.0 的内核级平衡检查)
-- 2. 绝对只追加 (Append-Only): UPDATE 与 DELETE 均被内核拦截
-- =========================================================================
DROP TRIGGER IF EXISTS `trg_transactions_check_balance`;
DROP TRIGGER IF EXISTS `trg_transactions_no_update`;
DROP TRIGGER IF EXISTS `trg_transactions_no_delete`;

DELIMITER ;;
CREATE TRIGGER `trg_transactions_check_balance` BEFORE INSERT ON `transactions`
FOR EACH ROW BEGIN
    IF ROUND(NEW.balance_after, 2) != ROUND(NEW.balance_before + NEW.amount, 2) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Transaction ledger balance integrity violation: balance_after must equal balance_before + amount';
    END IF;
END;;

CREATE TRIGGER `trg_transactions_no_update` BEFORE UPDATE ON `transactions`
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transactions ledger is strictly append-only: UPDATE is forbidden';
END;;

CREATE TRIGGER `trg_transactions_no_delete` BEFORE DELETE ON `transactions`
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transactions ledger is strictly append-only: DELETE is forbidden';
END;;
DELIMITER ;

-- =========================================================================
-- 物理强制触发器：强制保证 admin_audit_logs 管理员审计日志
-- 绝对只追加 (Append-Only): UPDATE 与 DELETE 均被内核拦截
-- =========================================================================
DROP TRIGGER IF EXISTS `trg_admin_audit_logs_no_update`;
DROP TRIGGER IF EXISTS `trg_admin_audit_logs_no_delete`;

DELIMITER ;;
CREATE TRIGGER `trg_admin_audit_logs_no_update` BEFORE UPDATE ON `admin_audit_logs`
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin audit log is strictly append-only: UPDATE is forbidden';
END;;

CREATE TRIGGER `trg_admin_audit_logs_no_delete` BEFORE DELETE ON `admin_audit_logs`
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin audit log is strictly append-only: DELETE is forbidden';
END;;
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;

-- Laravel 标准 schema 版本登记；不承载业务或资金事实。
CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='框架 schema 迁移版本登记表';
