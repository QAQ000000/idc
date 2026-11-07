# JianYun (简云) · 推广返佣与提现体系规范 (Affiliate & Withdrawal)

## 1. 业务流程与生命周期

```mermaid
flowchart TD
    A["买家支付订单 Invoices"] --> B["生成冻结返利 commissions<br/>(status='pending', 统一定义15天无理由退款冻结期)"]
    
    B -- 15天内买家申请退款 --> C["佣金自动级联作废<br/>(status='cancelled')"]
    B -- 满15天且无退款 --> D["解冻转入可提现推广金<br/>(users.available_commission)"]
    
    D --> E{"推广人申请提现"}
    
    E -- 通道 A: 提到网站可用余额 --> F["系统秒级自动划转<br/>严格校验影响行数，即时到账 balance<br/>用于站内新购/续费"]
    
    E -- 通道 B: 站外支付渠道打款 --> G["用户自由填写备注<br/>(支付宝账号、姓名、手机号等)"]
    G --> H["原子预扣提现金额<br/>(防止并发多次提现)"]
    H --> I{"管理员后台审核"}
    I -- 审核通过并打款 --> J["填写打款流水号，标记 approved"]
    I -- 审核驳回 --> K["填写驳回原因，资金原子退回 available_commission"]
```

### 1.1 专属邀请码与绑定机制
1. **专属邀请码 (`users.affiliate_code`)**：每个用户拥有全局唯一的专属邀请码 `VARCHAR(32) UNIQUE`（如 `aff_8f9c10`），由系统在用户注册时生成或允许用户设置自定义别名。
2. **推荐关系绑定 (`users.affiliate_id`)**：
   - 买家访问带有 `/?aff={affiliate_code}` 推广链接时，应用层在 Cookie/Session 写入 30 天有效期的推荐归属。
   - 注册落地时，将推荐人的 `users.id` 写入买家的 `users.affiliate_id`（带有外键约束 `fk_users_affiliate`，`ON DELETE SET NULL`）。
   - **反作弊铁律**：严禁自推自买（注册与结算时校验 `affiliate_id != id`），同一 IP / 身份证盲索引碰撞时阻断佣金结算。

### 1.2 分品类差异化返佣比例 (`products.commission_rate`)
- 返佣比例不再全站一刀切，下放至商品维度：`products.commission_rate DECIMAL(5,2)`（例如 `15.00` 代表 15%，`0.00` 代表特价机/不参与返佣）。
- **佣金计算基数**：严格按账单扣除优惠券后的实际净实收金额（Net Paid Amount）计算：
  $$\text{CommissionAmount} = \text{bc\_round}\left(\frac{\text{NetPaid} \times \text{CommissionRate}}{100}, 2\right)$$
- **数据库防重入约束**：`commissions` 表设置 `UNIQUE KEY uk_invoice_affiliate (invoice_id, affiliate_user_id)`，从数据库底层彻底杜绝同一账单向同一推荐人多次计入佣金。

---

## 2. 双提现通道设计规范

### 2.1 通道 A：划转至本站可用余额（To Balance / 零等待秒级到账）
- **商业价值**：激励推广者将返现直接转为站内消费资本（用于续费现有服务器或开通新节点），留存资金并降低平台的现金流压力。
- **执行机制**：
  - **免人工审批，事务级秒到，遵循悲观行锁锁定与全账本流水物理平衡**。
  - 核心原子 CAS 事务（伪代码逻辑）：
    ```php
    DB::transaction(function() use ($userId, $amount) {
        // 1. 悲观行锁锁定用户资产行
        $user = DB::selectOne("
            SELECT balance, available_commission FROM users WHERE id = :uid FOR UPDATE
        ", ['uid' => $userId]);

        if (bccomp($user->available_commission, $amount, 2) < 0) {
            throw new InsufficientCommissionException("可提现佣金不足或并发冲突");
        }

        // 2. 原子扣减可提现推广金并增加钱包可用余额
        $affected = DB::update("
            UPDATE `users` 
            SET `available_commission` = `available_commission` - :amt,
                `balance` = `balance` + :amt 
            WHERE `id` = :uid AND `available_commission` >= :amt
        ", ['amt' => $amount, 'uid' => $userId]);

        // 3. 双向记入全账本流水表 (双分录，触发器物理校验 balance_after = balance_before + amount)
        // 3.1 推广账户出账流水
        DB::insert("
            INSERT INTO `transactions` 
            (`user_id`, `account_type`, `type`, `amount`, `balance_before`, `balance_after`, `idempotency_key`, `remark`)
            VALUES (:uid, 'available_commission', 'affiliate_to_balance', -:amt, :comm_before, :comm_after, :idem_key1, '推广返佣转出至钱包余额')
        ", [...]);

        // 3.2 钱包账户入账流水
        DB::insert("
            INSERT INTO `transactions` 
            (`user_id`, `account_type`, `type`, `amount`, `balance_before`, `balance_after`, `idempotency_key`, `remark`)
            VALUES (:uid, 'wallet', 'affiliate_to_balance', :amt, :wallet_before, :wallet_after, :idem_key2, '推广返佣转入钱包可用余额')
        ", [...]);

        // 4. 记入提现记录表 (直接标记为 approved 已成功)
        DB::insert("
            INSERT INTO `commission_withdrawals` (`user_id`, `amount`, `destination`, `status`, `processed_at`) 
            VALUES (:uid, :amt, 'balance', 'approved', NOW())
        ", ['uid' => $userId, 'amt' => $amount]);
    });
    ```

### 2.2 通道 B：站外渠道人工打款（To External / 自由备注与合规审核）
- **商业价值**：支持站外现金提现，满足头部推介人与代理商的获利变现诉求。
- **自由备注支持**：
  - 系统不写死复杂的银行卡或外部接口，给用户提供自由灵活的文本备注框 `channel_info`。
  - 用户按提示填写：`【收款方式: 支付宝 / 银行卡】【姓名: 张三】【账号: 13800138000】【联系电话: 13800138000】`。
- **两阶段防超提安全流转**：
  1. **提现发起阶段（原子预扣）**：
     - 必须先悲观锁并原子扣减 `available_commission`，彻底封死用户在审核期间并发发起第二笔提现的漏洞：
       ```sql
       UPDATE `users` SET `available_commission` = `available_commission` - :amount 
       WHERE `id` = :user_id AND `available_commission` >= :amount;
       ```
     - 插入记录，初始状态为 `pending`。
  2. **管理员审核与打款阶段**：
     - **打款确认**：管理员通过企业/个人支付宝转账后，在后台输入“转账流水号/批次号”，点击确认打款，记录更新为 `approved`。
     - **驳回处理**：若用户填写的姓名与账号不符，管理员填写驳回理由，系统自动执行**原子反冲回滚**：
       ```sql
       UPDATE `users` SET `available_commission` = `available_commission` + :amount 
       WHERE `id` = :user_id;
       ```
       状态更新为 `rejected`，资金即刻退回用户推广账户。

---

## 3. 数据库表结构规范

> [!NOTE]
> **DDL 单一事实来源**：`commission_withdrawals` 完整字段定义以 `database/schema.sql` 为唯一权威（字典释义见 [DATABASE.md](DATABASE.md) §1.16），本规范不再内嵌 DDL 副本，避免双源漂移。关键字段提示：
> - 站外提现收款：`channel_type`（alipay / bank / manual）、`account_name`、`account_no`（AES-256-GCM 加密存储）、`channel_info`（自由备注）；
> - 审批流转：`admin_id`、`admin_remark`、`processed_at`。
