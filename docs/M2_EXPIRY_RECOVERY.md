# 到期状态核对与欠费续费恢复（2026-11-09）

## 责任与证据

本批只对现有魔方代理增加可选 ServiceStatusDriverInterface。上游负责按其合同、配置和时区执行到期停机、解除暂停及资源释放；简云负责本地账期／收款、可靠续费和只读核对。不因本地时间到期下发 suspend、terminate、开关机或独立 unsuspend；自动销毁另立专项。不能承诺上游一定何时停机或保留资源，运营须核实供应商保留期。

源代码证据：本机魔方副本 `app/home/controller/CartController.php::hostInfo` 只返回 domainstatus／账期，不包含暂停原因；`HostController::getHeader` 在所属账号校验后提供原资源的 suspendreason_type。`app/admin/command/Cron.php::host` 到期停机调用 `Host::suspend(id,"due",...)`；后者仅模块成功后写 Suspended／due 原因并向代理传递。副本证明接口与分类依据，不能证明真实供应商当前规则或字段完整性。

驱动按冻结 provider／host／商品／原账期查询；暂停时额外核对 header 的 id／productid／billingcycle／nextduedate／Suspended 一致。仅结构化 `suspendreason_type=due` 归为 billing；flow／uncertifi／self 为非欠费，其余或缺失为未知。不解析中文、提示词或按日期猜原因；不会把原始 header、用户名、密码或财务域名返回页面／日志。

## 状态分工

- 本地权益：从本地 due_date 实时投影 valid／expired；不把上游日期覆盖本地、不回滚已购账期，不因到期把本地资源标成暂停或销毁。
- 本地服务记录：保留 instances.status／suspend_type；已交付后暂停不再显示“开通中”。Active 仅代表本地记录，页面不再将其概括为“服务有效”。
- 上游观察：原 config_snapshot 的 service_status 只保存白名单状态、规范化暂停分类、上游日期、UTC 核对时间、核验标志和结果码，明确是最近一次观察。不复制凭据、正文、远端原始字段或商品 Schema。
- 电源：继续原独立运行状态接口。关机不证明欠费，Active 不证明虚拟机开机、服务可用或系统可登录。

确认上游 Suspended 时，可将本地 active 同步为 suspended；只有 due 才是 billing。原因不明／非欠费标记 manual 和持久 review_required；不覆盖独立 compliance／abuse／人工限制。由本模块标记的“未知暂停”后来读到明确 due，可重新归为 billing；独立人工／安全限制不因此放开。上游 Active 单独不能解除本地暂停，Cancelled／Terminated／缺失／查询失败也不会作本地销毁或改资金。

## 核对、并发与核验入口

客户在实例详情显式“核对上游服务状态”，后台超级管理员可核对原实例；其他管理员只读。所有管理员可在原实例查询页筛选“仅需核验服务状态”并关联原账单／资金／任务，财务边界不变。核验事实保存在原实例，不伪造 suspend Outbox 或虚构一项已经执行的停机动作。首次／不同核验原因另记脱敏 warning；重复查询不反复报同一警。

核对先短事务检查原实例及资金／任务门禁，释放锁后查询；最后再锁同一实例，核对身份、配置、账期、状态、Provider 配置以及原任务状态／执行令牌版本。并发付款／改价／凭据变更／任务推进或其他核对先提交时，旧结果不应用；未决任务和资金冻结保留原占用。网络不在事务中，核对不改账期、价格、资金、凭据或任务。

原 outbox:work 在无充值唤醒和可执行 Outbox 时核对一个临期（未来7天内）、已暂停或需核验资源；距上次至少15分钟，空候选退避15秒。不保证严格15分钟完成，金融任务繁忙时顺延。使用 MySQL 的原观察时间和门禁，不增 Redis 锁、队列、表或额外 daemon。并发 Worker 允许重复只读查询，版本门禁保证不会互相覆盖或重复履约。`--once` 保持原充值唤醒／Outbox 行为，不追加状态核对；人工单资源命令为 `php artisan instances:reconcile-status INSTANCE_ID`。

## 欠费恢复

新续费报价前先核对；原周期已有账单继续返回原单，不因查询故障另建。原支付门禁仍拒绝已知未知／非欠费核验状态；真实迟到／额外实收仍按原凭证入钱包，不吞款、不重复续费。

驱动新出账预检和实际 renew 执行都重读暂停原因，防止“出账时正常，扣款后上游已安全暂停”绕过门禁。没有任何远端变更前明确非欠费／未知即停止并交原人工核验；网络失败无未执行证明时沿用原调查策略，不能重发可能执行的请求。

确认 billing 后复用唯一原续费单、余额／易支付、原付款与记账／账期／renew Outbox 同事务。付款只延长本地已购权益，不清 suspended。只有原资源的远端目标账期和 Active 均确认后，由原 fenced Worker 或经证据审计的人工 fulfill 回填 Active 与最新观察；暂停恢复未确认、响应丢失或超时则继续原任务调查／人工核验，不重付、不另发解挂、不自动退款。独立非财务本地限制不能被迟到完成覆盖。

## 接口与兼容

客户 `POST /api/v1/instances/:id/service-status` 只读上游并落规范观察，归属取独立客户会话；后台 `POST /api/v1/admin/instances/:id/service-status` 仅启用 super_admin。响应 no-store，含 applied／outcome、本地状态和上述白名单权益／观察，无原始快照。原实例列表／详情补 entitlement_state／service_observation／service_status_supported；列表仍不调用上游。管理员列表增加 service_review=1 筛选，分页与空状态复用原入口。

调整原“魔方客户端无法可靠区分原因，因此所有非财务暂停人工”中的适配缺口：现在仅信任结构化 due，非财务／未知仍人工。原配置快照的销售参数不改，观察为可变运营信息，不重写历史账单、任务或生成规则；快照／任务版本变化会使在途旧操作确认失效，要求重新查询。完整接口仅在插件手册维护，无新迁移，仍27表。

## 验收与运行边界

完整独立 MySQL／Redis／HTTP 套件通过1514项业务检查（含44插件、67项本批到期恢复检查）；收尾追加3项纯权益投影检查后，最终单元18项，当前业务检查合计1517项。原独立systemd运行26项、配置缓存前后私有FPM身份隔离通过。前端typecheck／lint／build、PHP语法、27表字典同步及diff检查通过；构建保留既有公共包体积提示。

本批67项覆盖逾期但Active不推导停机、电源独立、结构化due／未知／非财务、保留独立人工／合规／滥用限制、错误ID／商品／日期／状态／类型与查询失败、Cancelled／Terminated不本地销毁；原单重复充值→唤醒／扫描→唯一renew→保持暂停→上游Active确认恢复、丢响应只读调查，以及不确定服务下的真实协议收款只入钱包一次。并发改期／任务令牌／Provider配置／管理员降权使旧查询不可落库，退款／未决任务保持占用。原常驻Worker空闲核对、--once不新增核对及重复扫描节流亦已实际执行；均为本地协议夹具，不能据此宣称真实CDN恢复通过。

Chromium客户19项＋后台17项（共36项）通过：客户三类状态、显式核对、503错误与重试、原续费入口和列表；后台筛选／空状态／重置、角色按钮／财务隔离和详情。1440／768／390／320px无页面横向溢出，表格内部滚动；电源操作保持在状态说明之前，未知暂停不推定为已确认合规事件，已交付无IP不再显示等待交付。未验证真机触控、物理软键盘或其他浏览器。

本机记录（不入Git）：`/tmp/jianyun-expiry-integration.log`、`/tmp/jianyun-expiry-targeted.log`、`/tmp/jianyun-expiry-customer-ui.log`、`/tmp/jianyun-expiry-build.log`，截图在 `output/playwright/expiry/`。客户到期页 `customer-expired-1440/768/390/320.png`、暂停／核验／错误 `customer-billing-768.png`／`customer-review-390.png`／`customer-query-error-390.png`；后台 `admin-review-list-1440/768/390/320.png`、`admin-review-empty-768.png`、`admin-instance-review-390/768.png` 及 `admin-support-review-768.png`。页面路径为本人实例→权益／上游观察→显式核对，后台实例查询→核验筛选→原详情；未增付款或停机入口。

本轮未重启 M2 Worker，未操作真实上游／资金或发送真实邮件。M2 状态核对需加载本版 Worker 后再核对，真实欠费恢复仅限已授权体验 CDN（商品391），本地实际 paused／充值／原单代扣及上游恢复须单独保留证据；不将旧“本地 active、上游 Suspended”的充值验收计为本批暂停恢复通过。真实邮件启用仍另行安排。
