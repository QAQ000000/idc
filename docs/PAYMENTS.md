# JianYun (简云) · 支付网关聚合与资金安全规范 (Payments & Security)

**易支付配置增量**：超级管理员通过版本化配置入口管理新收银开关；保存与收银认领在同一配置行锁下排序，尝试提交后仍可付款。回调不再检查enabled，继续使用原密钥验签及原结算幂等；任一历史尝试锁定网关、商户号和密钥，CLI不能覆盖，密钥轮换另批。无迁移，退款仍人工。字段、锁序及验证见[配置契约](M2_PAYMENT_SETTINGS.md)。

**人工已完成变配的补差**：独立管理员预览／余额结清，不使用普通支付／网关入口。新upgrade账单unpaid→paid CAS与正额钱包划扣、流水／余额凭证（cloud_consumption）、消费累计、未来改价、旧未付续费单取消及人工完成审计同事务；余额不足／写入失败整体回滚。零补差只保存paid账单与审计，不造零额收款／流水；不投上游任务、不自动退款或绕过返佣限制，见[契约](M2_MANUAL_ADJUSTMENT.md)。

**M2 充值实现增量**：独立充值单现已复用易支付收银与验签回调，正常充值用途 wallet_recharge，同事务落实收凭证、账单 unpaid→paid CAS、钱包余额与 recharge 流水。取消后／金额不符／额外真实款项按 late_wallet_credit 单独入钱包，不改父单；重复通知不覆盖原用途。充值不累计消费、不返佣、不创建实例或 Outbox，不支持余额给自身充值。停用账户仍可接收已验签的归属明确入款，登录／支出禁用不变；钱包容量或流水失败整笔回滚，网关不得收到成功确认。详细输入、锁序、兼容及证据见 [充值与资金查询](M2_WALLET_FINANCE.md)。

本文档定义了 JianYun 的支付领域编排、异步回调原子防重入机制，以及敏感凭据的应用层信封加密设计。

**充值唤醒增量**：正常充值账本提交后仅投 Redis 客户提示，异步复用原自动代扣，支付回调不扫描续费或调用上游。失败／回滚不发提示，Redis 异常不推翻已提交收款；迟到／额外实收和补偿不触发本批提示。并发不同充值单的 SQL-only 结算支持框架最多 5 次数据库并发冲突尝试，完整回滚后才重试，不重调网关／上游；重试耗尽仍返回失败供网关重试，不确认丢款。各凭证幂等与原 15 分钟兜底保持，见 [续费增量](M2_RENEWAL_CONTRACT.md#充值到账后的异步唤醒)。

---

## 1. 支付网关插件接口契约 (`PaymentGatewayInterface`)

所有支付插件放置在 `plugins/payments/` 目录下，实现 `App\Plugins\Payments\PaymentGatewayInterface`。

**接口签名与支付 DTO 的唯一文档来源是 [PLUGIN_DEV.md · 支付网关插件开发规范](PLUGIN_DEV.md#payment-gateway-interface)**；本文不再维护接口定义副本。当前插件负责收银台与验签，账单 CAS、钱包变更、返佣和 Outbox 由支付领域服务编排，插件不得自行推进业务状态。退款由管理员手动办理，本期不增加自动网关退款调用/反查；原退款编排设计仅作未来另行授权的参考，见 [人工退款策略](PAYMENTS_LEDGER.md#manual-refund-policy)。

---

## 2. 异步回调防重入与迟到/多收款分流铁律 (Anti-Reentrant & Late-Credit Callback)

第三方支付平台（如支付宝、易支付等）因网络抖动，经常会在短时间内发送 3~5 次相同的异步通知；同时，客户可能在账单超时取消（`cancelled`）或已通过其他渠道/余额完成支付（`paid`）后，又完成了此前打开的第三方收银台扫码。

**绝对禁止仅用 `UPDATE invoices ... WHERE status='unpaid'` 的 `rowCount() === 0` 盲目丢弃回调（否则超时取消后到账或跨渠道重付的真金白银会被静默吞没）**！必须严格先按 `(channel, gateway_trade_no)` 判重，再按账单当前状态分流：

> **以下是待实现支付编排器的规划伪代码，不是可直接运行的现有接口。** `$flow` 的方法用于明确事务顺序和分支，当前代码库未实现这些方法或第三方支付回调。验签、实收金额/币种/商户及账单归属校验必须由服务端完成；不采信客户端的“可履约”标记。

```php
// 规划伪代码：$notice 已通过对应网关验签，包含可信交易号及十进制实收额。
Database::transaction(static function (PDO $db) use ($flow, $notice): void {
    // 先只读取关联ID，再依次锁实例、同实例任务、相关账单、收款凭证；
    // 锁后重检关联不变。查询采用当前锁定读，不在持锁期间调用上游。
    $state = $flow->lockPaymentState($db, $notice->invoiceId);
    $invoice = $state->invoice;
    $record = $flow->lockReceipt($db, $notice->gatewayId, $notice->tradeNo);
    $flow->assertReceiptMatchesInvoice($notice, $invoice, $record);
    if ($flow->receiptAlreadyAccounted($record)) {
        return; // 同一笔已入账实收重放，不能再次加钱或派任务。
    }

    if ($invoice->status !== 'unpaid') {
        $flow->creditLateReceiptToWalletOnce($db, $notice, $invoice);
        return; // 仅本凭证与钱包；父账单和实例保持原状。
    }

    // 优先使用续费遇到不可逆销毁的专用SC-L02保护，不能再落入通用入钱包分支。
    if ($invoice->type === 'renew' && $state->hasNonCancellableTermination) {
        $flow->creditTerminationBlockedRenewalOnce($db, $notice, $invoice);
        return; // 同事务 invoice unpaid->refunded/清cycle_marker + refund_to_balance一次。
    }

    // 这些判断都取服务端锁定状态，必须在CAS paid之前完成。
    $quoteValid = $flow->serverQuoteIsValid($notice, $invoice, $state);
    $instanceEligible = $flow->instanceAllowsPayment($invoice, $state);
    $blocked = $state->hasUnresolvedTask
        || $state->hasCloudRefundPending
        || $state->hasRefundTerminationPending;
    if (!$quoteValid || !$instanceEligible || $blocked) {
        $flow->creditLateReceiptToWalletOnce($db, $notice, $invoice);
        return; // 即使父账单仍unpaid也不履约；保留其状态与cycle_marker。
    }

    // 仅允许取消尚未执行的纯欠费销毁；CAS失败则回滚并重新判定，不能盲目续费。
    $flow->cancelPendingBillingTerminationOrRetryTransaction($db, $invoice, $state);
    $stmt = $db->prepare("UPDATE invoices SET status='paid', paid_at=UTC_TIMESTAMP(), payment_method=? WHERE id=? AND status='unpaid'");
    $stmt->execute([$notice->gatewayId, $invoice->id]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Payment state changed; rollback and re-evaluate');
    }

    // paid CAS影响1行才正常结算：记录success凭证并固化收款用途；
    // recharge入钱包；正常云消费累计消费、返佣及create/renew/upgrade Outbox均同事务。
    // renew还要固化目标到期日及是否解挂；new付款不提前起租。
    $flow->settleNewlyPaidInvoiceAndOutbox($db, $notice, $invoice, $state);
});
```

上述规划方法的约束：

- `lockPaymentState()` 必须在实例行锁内锁定并检查**全部同实例**的未决任务（`running/investigating`、`failed + MANUAL_AUDIT_REQUIRED`，不按租约过滤），以及所有云消费 `refund_pending` 账单和已成功退款待执行的销毁任务；只检查当前这张未付续费单会漏掉另一张原消费单的退款冻结。充值单无实例则跳过实例门禁。
- `serverQuoteIsValid()` 对 `new/upgrade` 校验服务端快照、可信实收额与应付额一致、库存/销售策略与 `quote_expired_at`，不得仅依赖账单仍为 unpaid；过期但尚未被清理器取消也不能正常履约。`renew/recharge` 按其固定报价契约处理，不能强套 30 分钟过期。已验签但金额不符的真实收款归为不可正常履约并安全入钱包，不能因 CAS 未执行而丢弃；不支持的币种或不能确定归属的款项须单独冻结核验，不得凭客户端数据入账。所需外部预检先于短事务完成，其结果在持锁时校验有效性。
- `instanceAllowsPayment()` 对新购要求占位实例仍为 `pending` 且未交付；对续费要求已交付、未销毁且符合续费策略；对变配要求实例状态与能力允许该操作。合规/滥用暂停可按政策续费但绝不能解挂；退款审核冻结及退款销毁待执行时禁止正常续费/变配。
- `creditLateReceiptToWalletOnce()` 将凭证记为 `late_credited_to_balance`、用途 `late_wallet_credit`，保留原网关报文，并以网关交易唯一键及稳定账本键同事务记 `late_payment_credit`。**不改父账单/实例/佣金/消费累计、不派续费任务**。非销毁 blocker 存在时父账单仍可为 unpaid，`cycle_marker` 保留；解除门禁后由正常付款/代扣流程再结算。
- `creditTerminationBlockedRenewalOnce()` 仅处理尚为 unpaid 且因不可逆/主动销毁不能续费的账单：同事务 CAS 该账单为 `refunded` 并清空 `cycle_marker`，将本次实收记为 `late_credited_to_balance`、用途 `late_wallet_credit`、`payload.credit_reason='termination_blocked_renewal'`，记一笔 `refund_to_balance`。不得先写 `late_payment_credit` 再写退款；已有凭证在入口直接幂等返回。此处款项未购买新周期，后续退回原网关仍按钱包入账凭证退款处理，不按云消费退订处理。
- **余额/自动代扣**使用同一组报价、实例、退款及任务门禁。非销毁 blocker 或退款冻结存在时保留未付账单等待，**不扣钱包、不投 Outbox**，因此也没有一笔需要再“退回”的扣款。SC-L02 适用于已实际收取款项的保护；未扣款代扣不能凭空给用户加钱。

### 2.1 收款用途与退款边界

- `payment_records.payload.settlement_purpose` 由服务端随收款入账同事务固化，原网关报文继续保留。该字段不能由请求参数指定，也不能被重复回调覆盖；缺失的历史凭证须依据原始入账流水核对，无法核定则人工处理。
- `late_wallet_credit` 表示该笔实收只进入钱包，**不购买父账单对应的云资源**。其父账单可能是已付云消费单、已取消订单或充值单；退款时仅处理该凭证与此前预扣的钱包款，禁止修改原 `invoices`、`instances`、`commissions`、`total_spent`，禁止投递销毁。
- `wallet_recharge` 仅指独立充值账单的正常主收款；`cloud_consumption` 仅指正常消费主收款。两者与迟到/多收款的认领、成功和拒绝恢复流程，统一遵守 [PAYMENTS_LEDGER.md](PAYMENTS_LEDGER.md) §3。拒绝时只恢复本次实际修改的原状态，不按父账单类型盲写 `paid`，不向从未预扣的钱包补钱。
- 退款终态 CAS 必须包含凭证 ID、稳定退款号及 `refund_status='pending'`；唯有影响 1 行才能在**同一事务内**执行该用途允许的账单/钱包/佣金/任务副作用，任一失败则整体回滚。重复通知与并发反查只读取既有结果；插件返回成功也不能绕过这道门禁。

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

## M2 当前收单边界

已实现 balance 与 epay 新购及固定套餐续费付款。易支付通知支持 GET/POST `/api/v1/payments/epay/notify`；渠道表单由已登录账单所有者 `POST /api/v1/invoices/{id}/pay {gateway: epay}` 取得。页面返回不结算，签名正确但非 TRADE_SUCCESS 不结算。

外部直付不改变钱包，payment_records 是实收凭证，账单、累计消费与 create Outbox 同事务落库；transactions 只记真实钱包/佣金变化。重复回调不重复履约。迟到、取消后付款、金额不符与额外真实付款，在归属、币种可确认时按独立凭证幂等入钱包，不改原账单/实例/消费累计；不支持的币种和无法归属的通知返回 fail 留待网关重试/人工对账，绝不猜测归属。

未交付永久取消的钱包补偿见 [TASKS_OUTBOX.md](TASKS_OUTBOX.md#m2-运行与永久取消)，这是管理员显式触发的退到钱包，不能视为原路退款。原路退款由管理员手动办理，不列入当前自动化交付。未知上游结果必须先核验，不允许为退款直接解除实例占用。

固定套餐续费沿用同一验签与实收幂等入口。正常支付原子顺延本地原到期日并投 renew；不可逆/主动销毁时，真实实收按 SC-L02 一次 refund_to_balance、账单转 refunded 及清周期标记。其他 blocker/退款冻结/非财务暂停导致不能正常履约时，实收只入钱包且保留未付续费单，余额/自动代扣则不扣钱。重复回调不再顺延；具体接口与当前限制见 [续费契约](M2_RENEWAL_CONTRACT.md)。
