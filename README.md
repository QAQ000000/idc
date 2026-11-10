# JianYun (简云)

**客户快速选配**：有效完整快照支持本站后端本地预览，失败条件显式转在线；提交仍实时复核，变价重新确认，原请求键／资金／Outbox不改。默认关闭；M2刷新范围为本地商品2、3，其中3对应上游553并保持下架，客户仍只能预览在售商品。速度和恢复边界见[契约](docs/M2_LOCAL_PREVIEW.md)，新增双数量范围见[553验收](docs/M2_553_ACCEPTANCE.md)。

**计划刷新与快照发布**：显式本地商品ID范围、有界批次、完整规则校验与并发发布；失败保留旧快照，相同内容不改报价版本。时间／结果独立观察，可选独立systemd service/timer，默认关闭，客户calculate／checkout在线复核；无迁移，启用／证据见[契约](docs/M2_PRODUCT_REFRESH.md)。

**魔方本地计价验证**：插件内计价器与只读对价CLI，支持所识别的月付／年付、枚举和15／18阶梯，快照／币种／矩阵／价格政策不完整时整组回退在线；客户本地预览及计划刷新已由上方独立批次接入，提交继续实时核价。支持条件、对价及证据边界见[契约](docs/M2_LOCAL_PRICING.md)。

**选购响应与库存（2026-11-10）**：本地表单先展示、价格独立加载；400ms防抖＋单在途合并核价，目录／选配分开显示本地配额与短期上游库存，缺货和查询失败分别提示。无迁移、未购买；M2 Worker加载及整页手测另验，见[契约与证据](docs/M2_CHECKOUT_RESPONSIVENESS.md)。

**数量商品真实验收准备（2026-11-10）**：M2原HTTP／前端与正常停启的业务Worker已加载数量基线；只读选得389的2 IP低规格月付候选（首期／续费各¥78），563仍零库存。未导入、下单或付款，真实交付与规格待验，见[记录](docs/M2_QUANTITY_SALES_ACCEPTANCE.md)。

**整数数量配置（开发范围）**：魔方类型15／18与枚举联动共存，数量／子项ID分开、前后端严格校验及上游核价，沿用原冻结和Outbox；商品563仍零库存，真实销售与规格待验，无迁移，本批未进行M2运行接入，见[契约](docs/M2_INTEGER_QUANTITIES.md)。

**魔方枚举联动第一批**：单父无环枚举依赖复用原预览／导入、动态选配、实时核价与Outbox；父变化清除失效后代，服务端拒绝非法组合，规则冻结且旧报价失效。真实样本563现可解析15／18数量，库存0仍不销售；纯枚举合成扩展只用于隔离验收，见[契约](docs/M2_ENUM_DEPENDENCIES.md)。

**验证码策略后台**：超级管理员在`/admin/captcha`分别管理登录、注册和找回密码，首批只用内置图片／always。登录策略同时影响客户与管理员；客户登录10次／分钟、注册5次／分钟／可信IP，关闭验证码后继续限流。独立版本、同事务审计及丢响应只读核对，配置／驱动／Redis异常不放行，无迁移／Worker变更；M2用户基础手测通过，见[契约](docs/M2_CAPTCHA_SETTINGS.md)。

**站点基础配置**：超级管理员在`/admin/site`修改站名、本站静态Logo、公司主体、版权及备案。统一用于导航、商品页、页脚与标题，无需重建；公开接口仅白名单读取基础资料，失败使用默认值，草稿不影响公开展示。无表／迁移／Worker变更，M2接入仍待验，见[契约](docs/M2_SITE_SETTINGS.md)。

**客户账号运营增量**：超级管理员可在原客户详情 `/admin/customers/:id` 顶部弹窗启停客户／显式重置密码，保留版本、依据、同事务审计和旧会话撤销。停用不影响资源、已付任务或旧支付入账；原自动续费设置保留，停用期间不新增余额代扣。无新迁移，M2接入另验，见[契约](docs/M2_CUSTOMER_ACCOUNTS.md)。

**易支付配置后台**：超级管理员进入 `/admin/payments` 首次配置、启停新收银；保存带版本与审计、密钥不回显，历史支付尝试锁定商户身份。停用仍接收旧单回调，不丢迟到／多收款，无迁移；实现及隔离验收边界见[配置契约](docs/M2_PAYMENT_SETTINGS.md)。M2支付配置未改动。

**通知配置页治理**：邮件配置／工单订阅两区独立保存、保留非敏感草稿；409／丢响应先只读核对，密钥离区即清。145项页面检查与边界见 [通知页记录](docs/NOTIFICATION_SETTINGS_PAGE.md)，无迁移／Worker变更，M2工单邮件启用仍单独安排。

**客户实例页治理**：概览／系统维护／账期与续费分区，继续保护首屏VNC、电源、未决任务持续跟踪及按需凭据。实现、兼容与独立验收见 [客户样板](docs/CLIENT_INSTANCE_PAGE_SAMPLE.md)；无迁移或Worker变更，公共骨架后续再提炼。

**后台实例页样板**：实例详情按概览／服务核对／价格补差／账单分区，顶部保留摘要与异常；原面板挂载、独立提交和补差原键恢复保持。工程约束与实际验收见 [样板记录](docs/ADMIN_INSTANCE_PAGE_SAMPLE.md)。后台样板当批未涉及客户页或通知配置；后续客户页记录见上，均不需迁移／重启Worker。

**到期核对与欠费恢复**：魔方负责实际停机／解挂／释放，简云只读核对；本地权益、上游服务与电源分别展示。仅结构化 `due` 允许欠费续费，未知／非财务暂停保留核验；原续费确认目标账期与 Active 后才恢复。27表无迁移；M2 原Worker已加载，体验CDN真实due暂停→充值／原单代扣→上游目标日期及Active→本地恢复已验，见[契约](docs/M2_EXPIRY_RECOVERY.md)。

**邮件配置后台增量**：`/admin/notifications` 仅当前超级管理员读写，复用原配置服务、版本校验、同事务审计和密文存储；密钥默认保留且不回显。工单订阅单独确认／保存，普通配置不切换订阅起点，无新增迁移；M2 工单启用与真实收件仍待单独验收，见[配置契约](docs/M2_MAIL_SETTINGS.md)。

> **极简、高扩展的现代化云产品聚合销售系统**
> 面向异构上游代理（智简魔方等）与多形态自营服务（Kangle、PVE、Sub2API、手工VPS）。

## 目标设计能力

- ⚡ **极致极简**：告别旧魔方 178 张表的臃肿泥潭，仅用 8 张核心领域表（26 张业务/支撑表 + 1 张框架迁移登记表）支撑 100% 真实业务。
- 🔌 **驱动插件化**：无论是魔方代理、自营虚拟主机还是 AI 额度分发，均统一抽象为 `Provider` 驱动。
- 🛡️ **事务发件箱 (Transactional Outbox)**：杜绝“先调上游接口导致网络超时丢任务”，确保支付、账务与履约百分之百一致。
- 🔄 **双阶段自动续费**：出账与代扣彻底解耦，无论何时充值余额，均能秒级自动代扣续费，杜绝欠费假死停机。
- 📈 **实例改价与人工补差**：后续续费价可单独调整；上游实际变配已完成时，管理员预览并确认当前单期余额补差，降价不自动退款。自动上游变配仍待办。
- 🔒 **返佣防套利**：正常云消费退款成功时同事务冲正返利；迟到/多收钱包款退款保留原消费及佣金，详见 [用途隔离契约](docs/PAYMENTS_LEDGER.md)。

---

## 已确认技术栈

用户于 **2026-11-07** 确认：**PHP 8.4 + Laravel 13 + MySQL 8.0+ + Redis + 单一 Vue 3 前端 + Element Plus**。HTTP 首版采用普通 PHP-FPM，前端沿用 Vite、TypeScript、Vue Router、Pinia 与 REST API；优先复用成熟布局和组件，按完整业务链路交付。

M0 已接入 Laravel 13/PHP 8.4，并建立唯一 `web/` 前端工程。现有领域规则、插件隔离、支付 CAS 和同连接事务 Outbox 保留；全库 27 表包含原 26 张业务/支撑表与 1 张框架迁移登记表。适配及验收范围见 [技术栈定案](docs/TECH_STACK.md) 和 [路线图](docs/ROADMAP.md)。

## 当前安排与验收记录

用户于 2026-11-08 完成现有 M2 功能手动验收，确认顺序为：公共布局＋商品页＋实例详情页 → 余额充值＋后台资金查询 → 按反馈逐页整理。第一批已实现，用户已明确本轮第二批充值与资金查询范围；实现与隔离验证已完成，M2 真实资金验收和真机反馈单独记录，无需等待全站视觉精修。设计、页面状态与验收记录见 [前端整理设计](docs/FRONTEND_MODERNIZATION_DESIGN.md)。独立正式部署与 3–5 人邀请试售继续暂缓，原业务范围以 `255d807` 为基线，增量单独记录。现有部署模板和演练记录保留为未来参考，见 [正式部署规程](docs/PRODUCTION_READINESS.md)。

本机入口：[商品与客户控制台](http://127.0.0.1:18211/)、[管理员登录](http://127.0.0.1:18211/admin/login)。沿用原 M2 账号、数据库与服务；此环境连接真实支付和上游，付款、开通及实例操作仍具有真实业务效果。运行信息见 [M2 入口](docs/DEPLOYMENT_RUNTIME.md#m2-后台入口接入2026-11-08)。

本批按实例详情 → 结账 → 后台任务顺序整理竖屏操作路径：VNC／电源前移、辅助内容折叠，结账常驻价格／原提交入口，任务筛选抽屉与生效标签。手机与竖屏平板、异常与键盘模拟、改造前后路径和本机截图见 [验收记录](docs/MOBILE_PORTRAIT_UX.md)。原下单、权限、资金与任务门禁保持，无数据库迁移或 Worker 重启需求；真机触控和软键盘仍需手测。

客户实例详情已新增限定魔方云模块的自助重装：上游镜像、清盘二次确认、非财务 Outbox 与异常管理员核验入口。密码由上游生成；正常受理自动跟踪，空闲预检／明确受理／观察结构化重装类型后恢复空闲并取得原资源凭据才自动结束同步。页面区分“执行已结束”和“已验证系统成功”，未知、漏观察或超时才转异常处理。原资源、账期、续费价和资金保持；已有库须先迁移 000008，再重启业务 Worker。M2 已于 2026-11-09 完成迁移／重启，用户授权实例 3 的 Ubuntu 真机已通过公网 SSH／DNS／HTTPS；但上游只给通用“任务处理中”，自动结案未通过；本批移除中文匹配并交付结构化类型兼容补丁，正常自动闭环仍需更上层供应商提供字段。任务 #9 自然超时进入核验后，已凭真实登录证据经原入口人工回填凭据、解除占用并留审计 #7，未补造执行观察，不计为自动验收通过。详见 [重装契约](docs/M2_INSTANCE_REINSTALL.md#m2-运行接入与真实-ubuntu-验收2026-11-09)。

用户已确认三批页面整理收口。正常充值提交后提前触发原自动续费已完成实现、隔离验证及 M2 接入：原 Worker 已受控重启，体验 CDN 的真实 ¥0.01 充值单 #6 提前结算原续费单 #5，唯一任务 #8 确认上游续费，15 分钟扫描仍保留。用户确认上游手动改过账期，并另行授权本地校准，审计 #6 及账期／资金／防重证据见 [M2 验收](docs/M2_RENEWAL_CONTRACT.md#m2-运行接入与真实-cdn-验收2026-11-08)，与 [隔离结果](docs/M2_RENEWAL_CONTRACT.md#充值到账后的异步唤醒) 分开记录；正式环境仍另行部署。本批管理员客户／实例只读查询已接入 `/admin/customers`、`/admin/instances`，复用原资金／任务详情，金额和收款仍限财务／超级管理员，详见 [查询契约](docs/M2_ADMIN_QUERIES.md)。上游接入后台 `/admin/providers` 已新增列表／详情、超级管理员连接配置和只读验证，并衔接原商品导入；凭据不回显、已有绑定不变，见 [接入契约](docs/M2_PROVIDER_CONNECTIONS.md)。

**第三批结账与账单**：结账展示选配、首期／续费价和报价截止时间，过期后重新获取并核对；账单按类型／资金状态筛选，单独展示本单履约进度与钱包入款。消费迟到款也可查询，充值后进入钱包、消费付款后进入对应实例；读取失败和处理结果未知有独立提示。设计、兼容及实际验收见 [第三批记录](docs/FRONTEND_MODERNIZATION_DESIGN.md#第三批结账与账单页面)。

**实例详情加载**：本地门禁与展示、操作准备分开，VNC先可用；电源上次观察带时间先显示、最新status独立更新，镜像展开后短缓存，操作仍实时准备。M2再次进入观察729–775ms、缓存镜像227ms；最新状态仍需约5.7–7.3秒，非缓存结果的1秒保证。Token复用、在途合并及验收见[当前契约](docs/M2_INSTANCE_DISPLAY_CACHE.md)，首次按需加载历史见[原记录](docs/M2_INSTANCE_LOADING.md)。

## 当前实现与本地启动

**管理员账号管理**：`/admin/accounts` 供超级管理员新增、启停及分配四种固定角色、显式重置他人密码；所有启用管理员在 `/admin/account/security` 验证旧密码改密。安全变更与不可改审计同事务，旧登录撤销、重新启用不复活，行锁保护最后一名可登录超管。复用现有27表，无迁移；不改真实账号作验收，见[契约](docs/M2_ADMIN_ACCOUNTS.md)。

**工单邮件提醒**：新工单／客户追加通知指定管理员，管理员公开回复通知原客户；结单不发普通回复邮件。复用原通知表与mail Worker，短事务占位、防重、发送前重核与未知人工核验；显式启用起点且不补历史，默认关闭，M2尚未启用，无新增迁移，见[工单邮件契约](docs/M2_TICKET_MAIL.md)。

**最小客服工单**：客户 `/tickets` 提单、关联本人实例、公开回复和关闭；管理员 `/admin/tickets` 筛选、定位原客户／实例、公开回复与结单留痕。纯文本、分页、持久提交键防重与并发结单版本校验；只沟通，不代替资金／资源业务入口。仍27表，已有库启用前须迁移000009；M2已完成迁移与只读接入检查，正式环境另行部署，见 [工单契约](docs/M2_SUPPORT_TICKETS.md)。

**管理员实例续费改价**：现有 `/admin/instances/:id` 显示原冻结价、覆盖价和有效价，超级管理员带版本与依据修改／恢复；改价、未付续费单作废、周期标记释放与审计同事务完成。已付账单／账期不变，旧收银台迟到款按原凭证入钱包；该入口不补差或上游变配，27表无迁移，见 [本批契约](docs/M2_INSTANCE_RENEWAL_PRICE.md)。

**手工变配补差**：原实例详情新增预览／余额结清入口，只接受已人工完成且计价依据充分的当前单个已付周期；原账单和实际秒数计价，同期既往补差不重收，未来覆盖价同步更新。资金、旧未付续费单取消和审计同事务，不投上游任务；客户／财务账单显式显示人工已结清。多期预付、历史改期等依据不足先人工核账，27表无迁移，见[契约](docs/M2_MANUAL_ADJUSTMENT.md)。

**充值与资金查询**：客户 `/wallet` 可查余额、创建独立充值账单并继续易支付付款、查看本人钱包流水；原账单页展示到账结果。后台 `/admin/finance` 仅启用 finance/super_admin 可读，关联原账单、付款尝试／实收凭证和钱包流水，支持客户、网关交易号及 UTC 时间筛选。纯充值不产生实例、消费、佣金或 Outbox；取消后／额外实收独立入钱包。无新增表或迁移，验收及兼容边界见 [充值与资金查询契约](docs/M2_WALLET_FINANCE.md)。

当前 HTTP、CLI 和测试统一通过 Laravel 启动。已实现图片验证码、注册登录、固定套餐人工交付商品、下单预创建实例、余额付款、资金流水与 Outbox 同事务入库，以及公开商品、登录注册、下单和账单页面。M2 已增加可靠新购 Worker、魔方财务固定/独立枚举配置商品驱动、易支付收单、实例列表/详情及未交付永久取消的钱包补偿。固定月/年套餐续费、提前出账、充值提交后异步唤醒与每 15 分钟余额代扣已接入；返佣、原路退款及枚举子集以外的高级联动配置仍未实现。商品 14 独立新单已完成 ¥16.00 实付、开通回填与 VNC 基础规格核验，旧单保留待核验；新单创建丢响应后的上游原账单由人工单次支付恢复，后续授权重装后已验证该实例 Debian 12 的 root/22 SSH 与基础 DNS/HTTPS，吞吐和长期稳定性仍未验收，见 [客户自助证据](docs/M2_CUSTOMER_SELF_SERVICE.md)。密码与新订单主机名规则已跟随上游 Schema，详情见 [配置销售契约](docs/M2_CONFIG_SALES.md)。不以模拟测试代替真实资金链路。

旧站数据迁移仍采用外置旧站插件导出目标 SQL、通用 MySQL 工具恢复独立空库，不集成旧库适配或迁移页面，详见 [割接规程](docs/MIGRATION_RUNBOOK.md)。

退款由管理员手动办理，本期不开发自动退款、自动补偿或网关退款调用/反查，也不将其作为上线前置。保留实际退款凭证与经办记录；旧账单 3 的 ¥16.00 仍未结清。已有支付资金保护与人工裁决能力保留，详见 [人工退款策略](docs/PAYMENTS_LEDGER.md#manual-refund-policy)。

运行要求：PHP **8.4.x**，`pdo_mysql`、`bcmath`、`openssl`、`gd`、`curl`、`mbstring`、`redis` 及 Laravel 所需扩展，MySQL 8.0+、Redis 5+；前端 Node.js 22.12+。依赖精确版本由 `composer.lock` 与 `web/package-lock.json` 锁定。

1. `composer install`。服务器 Composer 需支持 PHP 8.4 和当前 Composer 插件/API，不使用旧版 Composer 2.0。
2. 从 `.env.example` 创建本地 `.env`，配置独立开发 MySQL/Redis 与随机 `APP_KEY`。Laravel 自动读取 `.env`；外部进程环境优先。部署用户须可写 bootstrap/cache 和 storage/framework（cache/data、views、sessions）目录，不能用全站可写权限替代。生成密钥：`php -r 'echo "base64:" . base64_encode(random_bytes(32)) . PHP_EOL;'`。**已有密文和 Token 的库必须保留原 APP_KEY，不运行 key:generate 覆盖。**
3. 新建空库后执行 `php artisan db:init`，只允许空库；由标准迁移运行器初始化完整 schema 与版本登记。已有 MVP 库先备份并完成历史 001/002 SQL 的审核升级，再执行 `php artisan migrate --force`，不运行 db:init。MySQL DDL 不支持整批事务回滚，异常中断须核对实际结构后恢复，不能盲目重跑或回滚资金数据。
4. `APP_ENV=local php artisan demo:seed` 创建演示商品。演示充值继续使用 `php artisan demo:recharge USER_ID 100.00 UNIQUE_REFERENCE`，仅允许 CLI local/test。
5. 本地 API：`php artisan serve --host=127.0.0.1 --port=8080`，检查 `GET /health`。生产 HTTP 使用普通 PHP-FPM，入口 `public/index.php`；不使用开发服务器或常驻 HTTP 模式替代生产 FPM。
6. 前端：`npm --prefix web ci`，`npm --prefix web run dev`。默认代理 API 到 `http://127.0.0.1:8080`，可通过启动进程的 `API_PROXY_TARGET` 指定独立后端。构建输出为 `web/dist/`，部署时由 Web 服务器提供 SPA 静态文件并将 `/api/`、`/health` 转给 PHP-FPM；SPA 路由回落到 index.html，API 错误不能回落为页面。
7. 为目标环境接入每分钟的 `schedule:run` 和独立 Worker。当前提供 systemd timer/守护配置，也可由部署侧选择 cron，二者不同时启用；正式环境尚待部署。现有 M2 验收环境已接入 systemd 并验证重启与日志采集，详见 [运行部署规程](docs/DEPLOYMENT_RUNTIME.md)。

`php scripts/console.php ...` 保留为 Artisan 的薄兼容入口，不维护第二套命令执行器。配置变更后清理/重建 Laravel 配置缓存；日志仅记录异常类型，API 始终返回统一 JSON 错误，不输出 SQL、请求体或凭据。`APP_CONFIG_CACHE` 及环境目录可由隔离测试覆盖，不读取部署 `.env`。

注册前请求 `GET /api/v1/captcha/config?scene=register`，显示 `data.img_base64` 并填写图片字符。注册请求中的 `captcha_payload` 为 `{ "challenge_id": "返回的ID", "answer": "图片字符" }`；登录使用独立的 `scene=login` 挑战。挑战有效期 5 分钟，尝试一次即销毁。默认所有认证场景启用图片验证码，可通过 `system_settings.key=captcha_scenes` 的 JSON 值按场景配置 `enabled`、`mode=always`、`driver=builtin_image`。驱动由 `CaptchaDriverRegistry` 读取插件清单并解析，新增实现无需修改核心服务；当前只安装内置图片驱动，未安装驱动或未支持模式会拒绝请求。

注册后用返回的用户 ID 执行 `php artisan demo:recharge USER_ID 100.00 UNIQUE_REFERENCE` 注入演示余额；同一个参考号重复执行不会重复入账。演示充值仅允许 CLI 且 `APP_ENV=local/test`，无公开充值接口。

携带 `Authorization: Bearer <token>` 请求 `POST /api/v1/orders/checkout`：

```json
{
  "product_id": 1,
  "billing_cycle": "monthly",
  "config_options": {},
  "hostname": "demo-host",
  "password": "ExampleRootPass!23",
  "use_balance": true,
  "checkout_key": "first-purchase-unique-request-0001"
}
```

`checkout_key` 为可选、区分大小写的 16–64 位字母/数字/下划线/连字符，同用户同键重放返回原订单（包含已取消终态），不同参数返回 409；新一次购买必须换键。旧客户端不带键保持兼容，但不获得跨下单请求去重保证。首方前端在同一表单网络失败重试时保留键，修改表单视为新购买意图；当前键仅保留在页面内存，刷新/关闭后无法自动找回未收到的订单响应。有限库存未付占用每用户最多 3 笔，可取消/支付已有订单释放；不限库存和即时成功余额付款不占此额度。

`product_id` 使用 seed 返回值；`use_balance=true` 在下单同事务中付款，余额不足则整个下单回滚。省略或设为 `false` 返回未付账单，再通过 `POST /api/v1/invoices/{id}/pay`、载荷 `{ "gateway": "balance" }` 付款。`GET /api/v1/invoices/{id}` 查看状态；`POST /api/v1/invoices/{id}/cancel` 取消未付账单并归还预留库存。金额均使用十进制字符串，客户端提交金额不参与定价。

当前人工交付商品在 `config_cache` 保存 `prices.monthly` / `prices.annually` 十进制字符串与 `fixed_config` 固定规格；人工商品暂不接受自定义配置；魔方独立枚举选配见下方 M2 配置销售契约。优惠券和返佣商品仍未启用。报价有效期 30 分钟；过期余额付款会被拒绝，需取消重下。通过 Laravel 调度每分钟运行 `php artisan invoices:expire` 回收过期未付实例与库存（每次最多 500 笔）。

实例在待交付期间 `start_date/due_date` 均为 `NULL`。后续 Worker 确认实际交付成功后，通过 `InstanceActivationService::completeCreate()` 带任务令牌与租约原子激活并起租；自然月/年到期日对齐目标月末。已有 MVP 库需先备份，再审阅执行 `database/migrations/001_delivery_dates.sql`，新库直接使用更新后的 `schema.sql`。本次代码修改不自动迁移现有数据库。

快照台账采用 `(instance_id, type, remote_snapshot_id)` 唯一键，创建未决时允许远端 ID 为 `NULL`，不同上游实例及快照/备份类型可使用相同远端编号。已有库对应迁移为 `database/migrations/002_snapshot_identity.sql`，执行前暂停快照相关调度、核对未决任务并备份；本项目不会自动执行该迁移。快照驱动和调度器目前仍是规划能力。

反向代理部署时配置 `TRUSTED_PROXIES` 为实际代理 IP 或 CIDR 列表（例如 `127.0.0.1,::1,10.0.0.0/24`）；默认不信任转发头。登录审计与验证码统一从可信 `X-Forwarded-For` 链右侧剥离代理，返回最近的非受信跳点；代理必须覆盖或正确追加真实来源地址。不会直接采纳 `CF-Connecting-IP`，Cloudflare 部署需由受信边缘规范化到 XFF 或 Web 服务器的 `REMOTE_ADDR`。畸形转发链回退连接来源 IP。

前端验证：`npm --prefix web run lint`、`npm --prefix web run typecheck`、`npm --prefix web run build`。本轮已在浏览器验证访客转登录返回、注册、下单、余额付款、取消、越权拒绝、余额不足与 390px 页面布局。需要人工浏览器复验时，可运行 `python3 scripts/test_integration.py --browser`，它只在隔离库创建演示账号并暂时关闭该库的登录/注册验证码；按回车后清理环境。默认集成测试仍验证启用验证码的真实生成与单次验签。浏览器环境不是公开体验站或生产配置。

### PHP 格式与框架复用

安装开发依赖后执行 `composer lint`（`vendor/bin/pint --test`），修改格式用 `composer format`。Pint 版本由锁文件固定；采用 Laravel 预设的保守格式规则，关闭改写语法标记、导入和字面量的规则，避免格式整理混入自动重构。业务修改提交前与原有专项检查一起运行，不连接业务库。

### 隔离集成测试

```bash
python3 scripts/test_integration.py
# redis-server 不在 PATH 时：
REDIS_SERVER=/absolute/path/to/redis-server python3 scripts/test_integration.py
```

要求 Python 3.9+、Docker、PHP 和本地 `redis-server`；脚本使用 `mysql:8.0.35`（可通过 `MYSQL_TEST_IMAGE` 覆盖为兼容的 MySQL 8 镜像），自动创建独立容器、随机端口 Redis 和多进程 PHP HTTP 服务，执行真实接口/数据库检查后清理资源。测试显式指定临时环境目录与配置缓存路径，不加载部署 `.env`，不连接现有业务库。还会启动独立私有 PHP-FPM 池验证配置缓存前后的健康、登录与匿名请求隔离，测试结束销毁该池；找不到 FPM 时设置 `PHP_FPM=/absolute/path/to/php-fpm`。

覆盖并发下单请求去重、同键不同参数拒绝、有限库存占用上限、非法密码、框架嵌套事务和旧 MVP 升级数据保留，以及并发付款只扣一次、最后库存并发预留、Outbox 写入失败整笔回滚、越权阻断、验证码场景隔离与单次消费、过期报价和账本只追加约束；并验证同上游不同商品的锁隔离、可信代理 IPv4/IPv6 与伪造链、交付起租的月末/闰年运算、失效令牌/租约保护、插件替换与失效拒绝、快照跨实例/类型编号隔离及旧库迁移。IP 解析可独立运行 `php tests/unit.php`；插件解析与核心依赖边界可独立运行 `php tests/plugins.php`。M2 另外通过真实 HTTP 协议夹具覆盖响应丢失与 SIGKILL Worker 恢复、幂等收单与未交付补偿；原路退款及割接故障矩阵仍未验收；单邮件通知按下文独立验收。

---

## 开发 Agent 工具

已将 Laravel Boost 作为开发依赖接入，项目 Codex MCP 配置通过隔离启动器提供框架信息及文档检索，数据库工具仅向显式绑定的独立开发/测试库开放。原有检查脚本与领域约束保留，不增加业务 AI 功能或生产服务。启动、权限与验收见 [开发 Agent 指南](docs/DEVELOPMENT_AGENTS.md)。

## 管理后台：异常任务与人工核验

沿用同一前端，打开 `/admin/login`，任务入口 `/admin/tasks`。启用的管理员可以筛选、分页查看异常任务及账单/实例/上游上下文和审计；只有启用的 `super_admin` 能提交原有人工核验动作，操作要求证据和页面所读执行令牌，越权、重放或过期状态会被拒绝。客户与管理员账号/Token 分离。

首次账号由环境操作者执行 `php artisan admin:create < /安全路径/admin.json`，JSON 字段为 `username,email,password,role`，role 为 super_admin/finance/support/operator，密码 12–72 字节。仅创建新账号、不重置既有账号、无默认密码或公开注册；通过私有 stdin 提交，不在命令行参数或 Git 中放密码。配置已有 second factor 的账号本批拒绝登录，后续单独接入验证流程。管理员登录复用 login 验证码及每 IP 每分钟 10 次密码校验限流，需现有 Redis。

本批无新增表或迁移，沿用当前环境/APP_KEY。页面“永久取消并退到钱包”仅是既有、需显式核验的未交付新购动作，不是渠道退款，不适用于续费；退款继续管理员手动办理。没有变更真实旧单与客户款项。详细接口和 UI 边界见 [API](docs/API.md#admin-operations)、[前端契约](docs/FRONTEND.md#admin-pages)。

隔离网页验收：`REDIS_SERVER=/path/to/redis-server python3 -B scripts/test_integration.py --browser`。该脚本创建独立测试库/服务，测试账号 `browser-admin` / `BrowserAdmin!2026`、只读 `browser-support` / `BrowserSupport!2026` 仅在该隔离库有效，包含两条人工核验样本。不得将测试 fixture 导入业务库；退出时按提示回车清理。

## 商品管理、上下架与加价

后台 `/admin/products` 提供商品列表/详情、上游绑定、可售账期、默认配置参考价和审计。所有启用管理员可读，只有 super_admin 可编辑名称/销售状态/none、fixed、percent 加价，以及从已配置上游导入或刷新快照。后台导航与登录回跳沿用同一 Vue 应用，无第二后台。

`/admin/products/import` 先只读预览 Schema、配置及首期/续费参考报价，再确认导入，默认下架；检查详情后通过编辑弹窗上架。已有商品详情可改默认选配/账期后预览刷新，保留 Provider/PID 与订单命名空间。旧页面冲突或上游变化拒绝，写入与审计同事务。参考价不代替购买时实时核价。

调价/刷新只影响后续目录与新购，不改历史账单、实例冻结续费价、资源归属或任务；下架/停售不阻断已付交付、存量续费、原单异常调查。冻结未付账单仍按原期限/金额付款。原 CLI products:snapshot 保留，无新增表或迁移；旧快照缺参考成本时需只读补取再调价，不猜测基价。详见 [接口与兼容](docs/API.md#admin-products)、[页面](docs/FRONTEND.md#admin-product-pages)。

## 架构文档导航

- [协同开发指南与工程宪法](AGENTS.md)
- [技术栈定案与框架迁移边界](docs/TECH_STACK.md)
- [系统全景架构设计](docs/ARCHITECTURE.md)
- [契约一：支付、多收、退款与资金账本协议](docs/PAYMENTS_LEDGER.md)
- [契约二：Outbox 幂等、执行租约与未知结果恢复协议](docs/TASKS_OUTBOX.md)
- [契约三：实例生命周期、报价锁定期与快照调度契约](docs/LIFECYCLE_PRICING.md)
- [契约四：生产割接平滑迁移与对账稽核实施规程](docs/MIGRATION_RUNBOOK.md)
- [统一单前端架构设计规范](docs/FRONTEND.md)
- [数据库设计字典与 DDL](docs/DATABASE.md)
- [RESTful API 契约与接口字典](docs/API.md)
- [模块化插件与驱动开发手册](docs/PLUGIN_DEV.md)
- [支付网关聚合与资金安全规范](docs/PAYMENTS.md)
- [模块化多渠道通知中心设计](docs/NOTIFICATIONS.md)
- [模块化验证码中心设计](docs/CAPTCHA.md)
- [精简营销发券与防重审计规范](docs/MARKETING_ENGINE.md)
- [客服工单与管理员内部协同规范](docs/TICKETS.md)
- [云快照与备份自主计划任务调度规范](docs/BACKUP_SCHEDULER.md)
- [推广返佣与提现体系规范](docs/AFFILIATE.md)
- [全栈SEO与长尾流量获客规范](docs/SEO.md)
- [研发路线图与交付里程碑](docs/ROADMAP.md)

---

## 目录结构

```text
/www/wwwroot/jianyun/
├── AGENTS.md                  # Agent 协同开发指南与行为基准 (最高宪法)
├── README.md                  # 项目概览
├── docs/                      # 架构文档、数据字典与路线图
│   ├── ARCHITECTURE.md
│   ├── DATABASE.md
│   └── ROADMAP.md
├── app/                       # 核心业务域代码
│   ├── Http/                  # Laravel 控制器与中间件
│   ├── Plugins/               # 插件契约与注册器
│   └── Services/              # 领域服务
├── plugins/                   # 驱动与插件扩展目录
│   ├── providers/             # 上游服务驱动 (zjmf, easypanel, sub2api, manual)
│   ├── payments/              # 支付网关插件 (alipay, epay, balance)
│   └── notifications/         # 消息通道 (mail, sms, tg)
├── web/                       # 单一 Vue/Element Plus 应用
├── routes/                    # Laravel API 与命令/调度
├── bootstrap/                 # 框架装配
├── artisan                    # 框架命令入口
├── composer.json
├── composer.lock
├── database/                  # 数据库迁移与初始化 SQL
├── scripts/                   # 运维、schema 文档同步与隔离测试工具
└── config/                    # 系统配置
```

## M2 配置与运行

先完成数据库升级，再通过本地受保护 JSON 的标准输入安装一个全新的上游账户/商品/支付渠道：`php artisan connections:install < /safe/local/connections.json`。不要在命令参数、Git 或日志写入密钥。示例（仅占位）：

```json
{
  "provider": {"driver":"zjmf","name":"上游节点","endpoint":"https://upstream.example","credentials":{"username":"account@example.test","password":"API_SECRET"}},
  "product": {"remote_pid":391,"name":"CDN体验","type":"cdn","monthly_price":"0.01"},
  "payment": {"driver":"epay","endpoint":"https://pay.example","pid":"MERCHANT_PID","type":"alipay","notify_url":"https://shop.example/api/v1/payments/epay/notify","return_url":"https://shop.example","credentials":{"key":"MERCHANT_SECRET"}}
}
```

该命令只预检，不购买；新建 Provider 固定资源命名空间，凭据 AES 加密，重复安装已有支付设置会回滚，不原地换上游账号。`connections:install` 保持固定 CNY 商品安装入口，月价必须与上游预检一致。已有 Provider 的选配商品使用 `products:snapshot`，支持独立枚举选项与 none/fixed/percent 加价，详见 [M2 配置销售契约](docs/M2_CONFIG_SALES.md)。回调 URL 必须能被网关服务器访问，return_url 必须能被付款用户浏览器访问；密钥配置与应用 APP_KEY 由部署侧备份。

用独立受管理进程运行 `php artisan outbox:work`，`--once` 处理一次候选。需要值守并监控 critical 日志，用 `php artisan outbox:inspect` 查看任务和 token。人工材料通过 stdin 交给 `php artisan outbox:resolve TASK_ID ADMIN_ID`，具体要求见 [Outbox](docs/TASKS_OUTBOX.md#m2-运行与永久取消)。运行安装步骤和现有 M2 守护接入证据见 [运行部署规程](docs/DEPLOYMENT_RUNTIME.md)。

前端“易支付付款”每次发起独立尝试并提交标准 POST 收银台表单，旧尝试即使迟到付款也能幂等归属，避免重复提交同一商户订单被网关拒绝；付款返回后刷新账单和实例；“我的实例”展示待付、开通、调查、人工核验、交付、取消与补偿状态。CDN 允许没有 IP。密码只在归属校验后的凭据接口返回，不进实例列表或任务日志。

当前验收分开记录：隔离环境通过 15 项单元、22 项插件、119 项原集成和 45 项 M2 检查，另通过配置缓存前后的私有 PHP-FPM 身份隔离；前端 lint、类型检查、构建及实例页面桌面/390px 浏览器交互通过。SakuraIDC 商品 391 月付 0.01 CNY、无配置字段。易支付成功凭证 `H202611072309326429` / ¥0.01 已验签，账单与 Outbox 原子结算，上游资源 `host:4250` 曾为 Pending，实时复核发现上游已改名并变为 Active；Worker 按已记录的账号范围稳定资源 ID 核对商品、账期及首付金额后自动恢复，任务 success、实例 active。首购起租为 `2026-11-07 15:33:12 UTC`，首购确认到期为 `2026-12-07 15:33:12 UTC`；所有者详情与凭据接口已实测，凭据值不入文档。

**首条固定套餐真实支付与交付闭环已完成**。后续订单的远端名称为稳定生成的 8 位数字/大小写字母，并随订单快照冻结。原订单保留旧身份，没有重复购买；无远端 ID 的调查仍严格按冻结名称匹配。本轮未伪造回调或用演示余额替代渠道付款；付款结论依据网关验签成功凭证，未进行银行侧独立对账。高级联动/数量配置、其他驱动、原路退款及完整生命周期不在此首链验收范围。

## 固定套餐续费与自动代扣

实例详情可续一个原账期、查看续费单和切换余额自动续费。请求 `POST /api/v1/instances/{id}/renew` 必须携带页面所见 `base_due_date`（UTC `YYYY-MM-DD HH:mm:ss`），省略 use_balance 仅出账，付款复用原余额/易支付接口。重复同原周期请求返回原单；取消未付续费不取消实例。固定续费价格没有新购的 30 分钟过期。

付款事务按原本地到期日自然月/年顺延，单条 renew Outbox 执行实际续费。付款状态和上游确认状态分别显示，不能以本地日期延长或收到渠道跳转代替上游确认。现阶段只自动处理 active 或 billing 暂停，其他暂停交人工；上游自身自动续费需关闭。未知结果只读调查，不重复续费/扣款。

`php artisan renewals:generate` 每小时提前 7 天生成唯一账单；`php artisan renewals:debit` 每 15 分钟扫描自动续费已开启、门禁通过且余额充足的未付单。两个命令独立，代扣只入队不调用上游；正常充值提交后发送 Redis 客户提示，由原 Worker 提前复用同一代扣服务；只处理本人原单，不自动出账、不在回调调用上游。Redis 故障或提示丢失仍靠 15 分钟扫描继续原单，详见 [充值唤醒](docs/M2_RENEWAL_CONTRACT.md#充值到账后的异步唤醒)。正式环境仍须部署侧接入 Laravel schedule:run 和独立 outbox:work；M2 验收环境已通过 systemd timer 与 Worker 服务接入，未修改正式环境或共享服务。新增代码加载前重启受管理 Worker，无数据库结构升级。

契约、人工裁决及异常资金边界见 [M2_RENEWAL_CONTRACT.md](docs/M2_RENEWAL_CONTRACT.md)。

真实续费验收（2026-11-08）：易支付成功凭证 `H202611072358567224` / ¥0.01 已验签，本地账单 2 paid、任务 2 success，远端续费单 `invoice:811244`，原资源 `host:4250` 仍 Active。本地到期由 `2026-12-07 15:33:12` 顺延至 `2027-01-07 15:33:12 UTC`；上游到期由 `2026-12-07 15:10:19` 顺延至 `2027-01-07 15:10:19 UTC`，分别保留原日期基准。所有者实例接口已确认 renewal_status=renewed，无重复新购或再次续费。资金结论依据网关验签成功凭证，未作银行侧独立对账。

本轮通过 15 项单元、23 项插件、119 项原集成、45 项 M2 和 52 项续费检查，以及配置缓存前后的独立 PHP-FPM 身份隔离；前端 lint/类型/构建通过；浏览器复验登录、自动续费关闭、续费出账、余额付款、上游确认及 390px 无横向溢出。续费响应丢失、SIGKILL、非财务暂停及销毁冲突在独立夹具验证，没有对真实资源执行破坏性暂停/销毁。


续费异常采用管理员核验：扣款成功但上游未成功时保留 paid 账单、款项和原任务，不自动退款或取消资源。首次异常立即写含账单/实例/任务编号的 warning，调查不逐分钟刷屏，人工门禁升级 critical；通过 `outbox:inspect` 查看持久待办。管理员可用 `action=investigate` 保留原上游单号继续只读调查；已续费只回填。当前增加单邮件人工核验告警，TG 延期；SMTP 接受不代表必达/已读，日志与列表仍需值守，规则见 [续费契约](docs/M2_RENEWAL_CONTRACT.md#续费异常通知与人工核验2026-11-08)。


运行验收入口：`REDIS_SERVER=/absolute/path/to/redis-server python3 -B scripts/test_integration.py --runtime`（需 root/systemd，使用独立临时单元与数据库）。22 项运行检查通过，覆盖实际分钟 timer、受控时钟的小时/15 分钟调度、SIGKILL 自动重启与只读恢复、日志及人工待办保留。正式环境单独部署；不把加速租约/受控时钟演练当作整机重启或完整小时墙钟验收。


M2 独立枚举配置销售已接入安全 Schema 投影、Element Plus 动态表单、服务端预检/加价、5 分钟报价确认、首期与续费价分别冻结及原 Outbox 参数透传。商品 14 首批完成真实只读/隔离验收；后续独立新账单 4 已实付 ¥16.00，实例 3 / host:4253 已由原 Worker 回填 Active，VNC 实测 1 CPU、1 GB 内存、60/30 GiB 双盘与 Debian 12。原单 3 / host:4252 在上游已为 Cancelled，本地仍已付待核验，未自动退款。新单创建响应丢失后，调查只找回原资源，上游原账单经人工单次支付后继续原任务，不代表自动续付异常已完整验收。新增付款前密码校验和按上游默认规则生成，新主机名规则随 Schema 冻结，历史调查不改身份。SSH 可达性、网络性能及完整阶段二仍未验收；配置范围、操作命令、契约兼容及证据见 [配置销售契约](docs/M2_CONFIG_SALES.md)。


### 实例运行状态与电源操作

客户实例详情新增运行状态查询、按实际产品能力开放的开机/关机/重启、确认弹窗和原任务结果跟踪；管理员原异常入口支持 power 任务核验。所有写操作先落 Outbox，超时或崩溃不盲目重发重启，不改资金、历史订单与实例权益；本站 VNC 页面通过 noVNC 直连独立云控制台。**已有数据库启用前必须运行 `php artisan migrate --force` 扩展任务枚举，再重启 Worker**。M2 验收环境已完成该迁移与 Worker 重启，真实实例 3 已执行关机/开机/重启，命令均被接受，关开状态已回查；原账单与资金保持不变。实现、兼容与验证见 [电源契约](docs/M2_INSTANCE_POWER.md)。

### 本站 VNC

实例详情 → VNC 控制台（`/instances/:id/console`），同一 Vue 本地打包 noVNC；浏览器直连云系统 WS，画面和键鼠不经简云中转。配置 `CONSOLE_TARGETS=受信云控制台主机:443`，必须与财务 API 主机名不同，生产要求 WSS/公网地址。财务账号/密钥、主机密码和 host_token 不返回；云地址及本次连接凭据会在浏览器可见，页面 URL 不携带它们。无 PHP 中继、额外守护进程/WS 反代或新业务表。页面期限和持续校验不能替代上游凭据撤销能力；详见 [控制台契约](docs/M2_INSTANCE_VNC.md) 和 [部署规程](docs/DEPLOYMENT_RUNTIME.md)。


### 单邮件告警与到期提醒

已实现管理员人工核验告警与客户未来 7 天到期提醒，邮件链接分别进入原任务详情/实例详情。先 `php artisan migrate --force`，再通过私有 stdin 使用 `notifications:configure ADMIN_ID` 加密配置 SMTP；两个场景可独立启用。现有分钟调度调用 `notifications:sweep` 补扫及恢复，独立 `notifications:work` 发送，避免 SMTP 拖住出账、代扣和金融 Worker。部署增加同环境的 mail.service，模板生成器现在输出四个单元。

按 fingerprint/channel 唯一占位并持久认领；发送前重查完整到期时间、归属/邮箱及原任务核验轮次，旧消息保留并跳过。SMTP 接受只记 success，不代表收件箱必达或已读；网络结果未知或发送进程崩溃转 pending_manual_check，notifications:inspect/resolve 用证据受审计收口，不提供重发。客户真实提醒须显式启用，详细配置/运行及本轮证据见 [通知契约](docs/NOTIFICATIONS.md)。

### 客户账单与密码自助（2026-11-08）

同一客户控制台新增“我的账单”和“账户安全”：本人分页/状态筛选、刷新后继续原账单；修改密码、验证码保护的邮件找回、15 分钟单次成功重置。成功改密递增认证版本，旧登录及同版本旧重置链接失效，不自动登录；重置凭据不作登录、不明文存库或进 URL 查询串。仍为 27 表，只增加 users.auth_version。**已有库须先 `php artisan migrate --force`，再加载新 HTTP 代码及重启独立邮件 Worker**；金融 Worker 无新增执行分支。旧无 av 客户 Token 仅在版本 0 兼容。页面、兼容、隔离验收与真实 VPS 支持范围见 [客户自助契约](docs/M2_CUSTOMER_SELF_SERVICE.md)。正式部署/邀请试用后续安排。

本批实际核验：694 项业务检查 + 25 项运行检查、私有 FPM 配置缓存前后身份隔离、前端类型/lint/构建及桌面/390px 网页流程通过。用户另行授权实例 3 重装后，已通过 Debian 12 / 公网 root:22 SSH、DNS 和两个 HTTPS 站点验证；本站仅加密回填新实例密码，冻结价格/账期/资金/原任务保持。数据盘未挂载，吞吐/线路/长期稳定性仍未验收，当时通用重装页面/任务能力仍延期；当前限定魔方云重装已独立实现并经隔离验证，见 [重装契约](docs/M2_INSTANCE_REINSTALL.md)，不能借用这次历史维护作为新功能真实验收。详见上述本批契约。
