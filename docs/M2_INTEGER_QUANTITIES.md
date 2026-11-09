# 魔方整数数量配置：类型15／18

## 范围与协议证据

承接[枚举联动批次](M2_ENUM_DEPENDENCIES.md)，用户指定先覆盖商品563的类型15／18。开发与验收仅使用独立环境；563仍为零库存解析样本，不在M2导入、刷新、付款或开通。本批不重启M2，无表、DDL、迁移或新依赖，保持27表、一单一实例。

依据仓库脱敏真实样本 `tests/fixtures/zjmf-product-563.json` 与本机上游源码副本 `/www/wwwroot/sakuraidc.cc` 只读核对：

| 字段 | 类型 | 整数范围 | 步长 | 子项区间（闭区间） |
| --- | --- | --- | --- | --- |
| 278144 IP数量 | 15 | 1–4 | 1 | 1179010：1–1；1179011：2–4 |
| 278145 带宽 | 18 | 50–1000 | 10 | 1179012：50–50；1179013：51–1000 |

本机魔方 `app/common.php:4519` 的 `judgeQuantity`、`judgeQuantityStage` 将15／18均定义为数量及阶梯计价类型；`app/openapi/controller/CartController.php:978` 的settle处理按数量匹配子项闭区间、记录子项ID与qty，再调用quantityStagePrice。**数量值与计价子项ID不同**，不得拿1179013替代带宽60，也不因类型15名为IP数量而按本地单价乘数量。

`public/themes/cart/default/configureproduct.tpl` 的数量输入使用option ID作为键、qty_minimum默认值；`assets/js/configureproduct.js:208` 的numberKeyup2要求 `n % qty_stage == 0` 或等于全局最小／最大值，qty_stage=0按1处理。**步长基准为0，允许上下限例外**，不是 `(n-minimum) % step`；合成51–999、step10例子允许51／60／999，拒绝61。上游API只做钳制／回退的部分不能代替本地严格校验，简云不静默修正客户输入。

核价保持 `/cart/get_total` 的 `configoption[字段ID]=数量`，下单保持 `/cart/settle` 的 `cart_data[configoptions][字段ID]=数量`；枚举仍传子项ID。两处整单均固定qty=1。字段上下限和子项区间来自既有真实样本，未声称本轮重新确认真实库存或实际交付。

## 安全表单与服务端门禁

魔方专用解析位于插件IntegerQuantity；核心只识别通用integer表单，无具体插件引用。字段保留id／type=integer／label／default／choices=[]，追加minimum／maximum／step／step_base=0／allow_endpoints=true／ranges；ranges每项为id／minimum／maximum，不含子项价格或原始响应。原select结构和无新增规则的旧hash不变。

首批只接独立整数数量项，和原单父无环枚举依赖共存。范围及步长必须是非负整数（步长0归一为1），参数最多10位，与原canonical界限一致；区间必须覆盖完整上下限且互不重叠，隐藏、缺口、反向、越界、重复ID、损坏规则明确拒绝。此约束保护价格区间的确定性；不猜测缺失区间价格，也不依赖上游静默修正。其他数量类型、自定义字段、type20及数量作为联动条件／结果继续拒绝，另批按真实样本设计。

ConfigOptions按字段类型验证；拒绝浮点JSON、非整数文本、符号／科学记数法、越界、步长不符、遗漏／额外字段和子项ID冒充数量。整数JSON与数字字符串统一为原canonical字符串。合法数量仍须上游实时核价，前端不推算阶梯价；首期与续费价分别使用上游结果。

## 报价、历史与执行

范围／步长／区间ID及边界进入安全表单schema_hash，复用原商品快照指纹与报价确认；最终数量进入原config_options。driver_context可选configuration_quantities冻结完整安全integer字段，与原configuration_rules共存，经原账单、实例快照和Outbox传递，不新建配置树或表。区间顺序按下界规范化，无重叠区间重排不改hash；区间身份或边界、步长变化须重新报价确认。

数量或枚举变化立即使原页面确认失效；旧在途报价由原序号丢弃，未补全不能核价／提交。服务端再次拒绝旧确认或非法组合。Worker首次购买前仍核对冻结Schema及价格；已提交后超时只读调查，不因新范围或步长变化重购。原checkout_key在成功落单后先恢复原单，参数不同则拒绝。刷新不重写历史账单配置、实例冻结续费价或原任务身份。

## 页面与兼容

沿用Element Plus原ConfigOptionRenderer和后台快照预览。数量使用带numeric键盘提示的文本输入，保留客户实际输入并明确范围／步长／异常，不在输入中自动钳制、截断小数或替客户选别的值。非法输入立即作废报价、阻止提交；有效输入触发实时核价。概览／冻结摘要展示实际数量，后台默认参考配置也展示数量，不显示“待选择”或计价子项ID。

本批改变的是前一批“完整563因数量拒绝解析”的能力边界；零库存仍阻止可售预览／下单。既有select快照兼容，无schema迁移；启用前让HTTP、前端和原业务Worker加载同版本代码，按运行规程优雅重启原Worker。此次没有部署／重启M2。真实销售待563补货或另行指定同类型有库存商品、资金授权及实际规格验收；隔离响应只能证明协议传递。

## 验证记录

实际执行 `REDIS_SERVER=/www/server/redis/src/redis-server python3 scripts/test_integration.py --browser`：**2069项／31套检查**通过（原1993＋数量专项76），配置缓存前后私有PHP-FPM身份／权限检查通过。专项覆盖原563完整解析与零库存预览拒绝、区间／边界／0基准步长／端点例外、JSON整数／非法小数与子项ID、损坏Schema／数量联动拒绝、真实HTTP预览／默认下架导入／原确认恢复、非法配置无资金变更、首期／续费独立核价、数量／规则冻结及原单恢复。

隔离PID2563为原合成枚举图加563真实数量字段，32／30及40／35仅为测试首期／续费价；真实563原始形状独立保留，库存为0。步长与区间变更会拒绝旧报价；付款后首次购买前变化停止并核验；创建丢响应、期间规则变化后仍只读找回原资源，原键恢复同单、仅扣一次且只有一个Outbox，实际settle载荷数量不是子项ID且qty=1。刷新保留历史账单及实例续费价。此处均是协议／故障夹具，不是真实资金或实际规格证据。

Chromium **81项**通过（客户62＋后台19）：默认数量展示、非法输入保持原值且不请求报价／不能提交、数量变化重新核价、旧报价乱序丢弃、与枚举链共存、真实下单提交后模拟丢响应并以原键／原参数恢复同账单及冻结摘要；后台显式重预览／保存／审计、support只读及默认数量展示。

1440桌面、320／390手机、768／820竖屏平板及390×440缩小高度覆盖异常／恢复、数字输入焦点、长选项展开／收起、吸底提交／后台保存可达且无横向溢出；缩小高度只模拟键盘占位，不代表移动真机软键盘或跨浏览器验收。代表320客户异常和390后台截图已目视检查。脚本／结果和截图保存在忽略的[验收目录](../output/playwright/integer-quantity/client-results.txt)，另有[后台结果](../output/playwright/integer-quantity/admin-results.txt)、[320客户异常](../output/playwright/integer-quantity/phone320-invalid.png)、[768客户页](../output/playwright/integer-quantity/tablet768.png)、[390后台](../output/playwright/integer-quantity/admin-phone390.png)。

PHP语法、Python语法、Vue类型／ESLint／Vite构建、字典同步及diff检查通过。原Element Plus公共包超过500kB提示保留，无新依赖。完整日志 `/tmp/jianyun-quantity-integration.log`，构建／Lint日志 `/tmp/jianyun-quantity-build.log`、`/tmp/jianyun-quantity-lint.log`。无M2运行变更；启用与真实销售边界见上节。
