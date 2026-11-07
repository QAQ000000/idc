# M2 可配置商品销售契约（2026-11-08）

本批由固定套餐扩展至同一魔方财务上游的独立枚举选项商品。使用现有 products.config_cache、instances.config_snapshot、invoices.price_snapshot 与 create Outbox；不新增表、不拆上游配置树、不增加第二套后台配置系统。用户指定 SakuraIDC 商品 14、暂不加价，本批只读核价和隔离验收，不授权新订单、上游扣款或正式部署。

## 配置与边界

可选 `ConfigurableDriverInterface::describeForm(array $snapshot): array` 只负责将原始 JSON 投影为公开字段：`id/type/label/choices/default`。核心通过接口取得表单，不认识魔方字段或具体插件类；原始快照不直接公开。当前支持魔方 `option_type` 1、2、5、6、8、10、12、13 的独立枚举，统一用 Element Plus select 展示；选项值仍为上游子项 ID。高级联动、自定义字段、数量/滑块和隐藏配置不支持时拒绝导入/销售，不能静默忽略。

选项必须完整，每个 ID 只能选择其自身允许的子项；额外键、嵌套值、其他字段的子项被拒绝。服务端将键排序、ID/值规范为整数 ID 与十进制字符串，消除 JSON 键顺序和整数/字符串表达差异造成的重复购买意图。仍保留原 checkout_key 的用户范围唯一约束与同用户事务串行化。

`products:snapshot` 从 stdin 接受既有 provider_id、remote_pid、name、type、billing_cycles（默认 monthly）、markup_type/value（默认 none/0.00）及可选 config_options，先只读拉取 Schema 和默认配置报价，保存 JSON。指定 product_id 时刷新同一商品；禁止改变 Provider 或远端 PID，保留订单命名空间，不修改历史实例和账单。同步能力由人工命令触发，本批未实现 Redis/SWR 自动刷新，也未声称毫秒性能。默认配置参考价不是最低价，目录明确标注其含义。

```bash
php artisan products:snapshot < /safe/local/product.json
```

```json
{"provider_id":1,"remote_pid":14,"name":"洛杉矶轻量弹性云","type":"cloud","billing_cycles":["monthly"],"markup_type":"none","markup_value":"0.00"}
```

命令会修改指定部署库的商品目录；本轮只在独立测试库执行该命令。真实 M2 库没有新增商品或刷新原固定套餐。

## 报价确认与冻结

1. `GET /products/{id}/config` 读本地快照，返回安全字段、默认选择和 schema_hash。表单文本按普通文本呈现，不执行上游 HTML。
2. `POST /products/{id}/calculate` 在事务外预检当前 Schema、选择、CNY、账期和库存。`get_total` 使用单数 `configoption`；结算使用 `cart_data.configoptions`。返回 total、recurring_price、规范配置及 quote_token。当前拒绝上游促销/折扣口径和非正续费成本，避免把未核实的价格当作续费价。
3. 首期成本取 products.total（含初装费），周期续费成本取 products.signal_price。二者分别按本地规则加价：none 原价、fixed 各加固定额、percent 各按百分比加价。BCMath scale=6 运算后十进制 half-up 到分，校验 DECIMAL 上限；不使用 float/round。旧固定套餐没有 signal_price 时保留原先首期等于续费成本的兼容行为。
4. 确认令牌为 5 分钟时限和 HMAC，绑定商品指纹、规范选择、账期、首期/续费售价和预检成本/上下文。新选配订单必须携带；前端每次选配立即清除旧报价。提交时重新只读预检，锁内再次比对产品指纹与确认令牌；变化返回 409，不能静默按新价购买。
5. 事务内冻结配置、可读标签、两类上游成本、首期/续费售价、加价规则和驱动上下文，实例 recurring_price 取续费售价。账单响应和实例页面只公开安全价格与配置摘要，不公开成本、凭据或原始 Schema。新购未付账单仍保留 30 分钟付款期限。
6. 有效 checkout_key 的成功重放先返回原订单，不依赖今天的 Schema、上游状态或已过期令牌；改动业务载荷则拒绝。报价令牌不加入购买意图摘要，不妨碍响应丢失后的找回。
7. 支付后，Worker 仍使用原 Outbox 与冻结配置；执行前重新验证配置 Schema、首期及续费成本。变化在首次提交上游前停止并留管理员核验；已提交后超时继续原调查协议，不以重新报价为由再次购买或支付。目录刷新不改原订单、实例权益及续费价。

仅确认账单和资产身份、上游 Active 与原有履约信息；当前 hostinfo 不提供全部选配硬件配置的独立证明。本批验证了参数完整透传，不把这一证据称作对真实硬件配置的二次核验。

## 冻结契约变更与兼容

原因：固定套餐无法支持动态选配，首期可能包含初装费，原 recurring_price=total 会高估续费；用户看到的报价与下单时的新报价也需要确认边界。

影响：Provider 可选接口及 PreflightResult 新增可空 recurringAmount；Catalog/Billing/商品快照 CLI、魔方驱动、API、前端、测试及相关摘要。现有驱动不必实现表单扩展；旧固定商品客户端可省略 quote_token，携带时同样校验。旧订单快照缺失新字段保留原语义；已有 checkout_hash 只在非空配置规范化时可能变化，先前固定商品均为空配置而不变。无需 schema 迁移或历史重定价。升级不得原地换 Provider 账号。

## 验证与剩余边界

真实只读 API（2026-11-08）：商品 14 有 13 个枚举选项；默认配置首期/续费均 ¥16.00，仅 CPU 改为 2 核后均 ¥18.00，Schema 一致；未调用 settle/apply_credit，未触碰 host:4250。报价为当时观测，不是长期价格承诺。

隔离协议使用商品 14 的去价格/去后台字段 Schema 样本，并特意构造不同首期/续费成本测试初装费。自动检查覆盖加价半分舍入、越界、无效/跨字段/嵌套配置、Schema/首期/续费价变化、确认令牌、规范化重放、一次扣款/Outbox、完整透传、交付前拒绝和刷新后历史快照不变。保留原固定新购、续费、支付、故障恢复和 PHP-FPM 隔离回归。

真实选配商品的支付/实际开通未验收；高级联动、数量字段、促销、其他驱动、后台商品 UI、自动快照刷新和充值秒级唤醒继续延期。续费失败仍只告警/持久待办并交管理员核验，不增加自动退款。


最终验收：`REDIS_SERVER=/www/server/redis/src/redis-server python3 -B scripts/test_integration.py --runtime` 通过 15 项单元、23 项插件、119 项原集成、45 项 M2、68 项续费、43 项选配及 22 项运行检查；包括六路并发只生成一单/扣款/任务、选配开通丢响应后只读恢复、目录刷新后原实例按独立续费价续费。私有 PHP-FPM 配置缓存前后身份隔离通过。前端 lint/typecheck/build、数据库字典同步、Markdown 目标与 diff 静态检查通过；构建保留既有大包体积提示。Playwright 实测 13 字段、CPU 切换核价、下单后账单/实例摘要与 390px 宽度无横向溢出。测试容器/进程/临时 systemd 单元均已清理；真实 M2 服务仍 active，账单仍仅原两张，host:4250 到期仍 2027-01-07 15:33:12 UTC。
