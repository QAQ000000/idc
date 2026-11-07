# JianYun (简云)

> **极简、高扩展的现代化云产品聚合销售系统**
> 面向异构上游代理（智简魔方等）与多形态自营服务（Kangle、PVE、Sub2API、手工VPS）。

## 目标设计能力

- ⚡ **极致极简**：告别旧魔方 178 张表的臃肿泥潭，仅用 8 张核心领域表（26 张业务/支撑表 + 1 张框架迁移登记表）支撑 100% 真实业务。
- 🔌 **驱动插件化**：无论是魔方代理、自营虚拟主机还是 AI 额度分发，均统一抽象为 `Provider` 驱动。
- 🛡️ **事务发件箱 (Transactional Outbox)**：杜绝“先调上游接口导致网络超时丢任务”，确保支付、账务与履约百分之百一致。
- 🔄 **双阶段自动续费**：出账与代扣彻底解耦，无论何时充值余额，均能秒级自动代扣续费，杜绝欠费假死停机。
- 📈 **智能升降级与改价神器**：弹性云按天补差（降级 0 元不退款）；后台支持单实例一键改续费价并自动划扣当期差价。
- 🔒 **返佣防套利**：正常云消费退款成功时同事务冲正返利；迟到/多收钱包款退款保留原消费及佣金，详见 [用途隔离契约](docs/PAYMENTS_LEDGER.md)。

---

## 已确认技术栈

用户于 **2026-11-07** 确认：**PHP 8.4 + Laravel 13 + MySQL 8.0+ + Redis + 单一 Vue 3 前端 + Element Plus**。HTTP 首版采用普通 PHP-FPM，前端沿用 Vite、TypeScript、Vue Router、Pinia 与 REST API；优先复用成熟布局和组件，按完整业务链路交付。

M0 已接入 Laravel 13/PHP 8.4，并建立唯一 `web/` 前端工程。现有领域规则、插件隔离、支付 CAS 和同连接事务 Outbox 保留；全库 27 表包含原 26 张业务/支撑表与 1 张框架迁移登记表。适配及验收范围见 [技术栈定案](docs/TECH_STACK.md) 和 [路线图](docs/ROADMAP.md)。

## 当前实现与本地启动

当前 HTTP、CLI 和测试统一通过 Laravel 启动。已实现图片验证码、注册登录、固定套餐人工交付商品、下单预创建实例、余额付款、资金流水与 Outbox 同事务入库，以及公开商品、登录注册、下单和账单页面。真实上游驱动、第三方支付、Worker、自动续费、返佣和实例查看 API 尚未实现；付款后的实例和任务保持 `pending`，不会实际开机。

旧站数据迁移仍采用外置旧站插件导出目标 SQL、通用 MySQL 工具恢复独立空库，不集成旧库适配或迁移页面，详见 [割接规程](docs/MIGRATION_RUNBOOK.md)。

运行要求：PHP **8.4.x**，`pdo_mysql`、`bcmath`、`openssl`、`gd`、`curl`、`mbstring`、`redis` 及 Laravel 所需扩展，MySQL 8.0+、Redis 5+；前端 Node.js 22.12+。依赖精确版本由 `composer.lock` 与 `web/package-lock.json` 锁定。

1. `composer install`。服务器 Composer 需支持 PHP 8.4 和当前 Composer 插件/API，不使用旧版 Composer 2.0。
2. 从 `.env.example` 创建本地 `.env`，配置独立开发 MySQL/Redis 与随机 `APP_KEY`。Laravel 自动读取 `.env`；外部进程环境优先。部署用户须可写 bootstrap/cache 和 storage/framework（cache/data、views、sessions）目录，不能用全站可写权限替代。生成密钥：`php -r 'echo "base64:" . base64_encode(random_bytes(32)) . PHP_EOL;'`。**已有密文和 Token 的库必须保留原 APP_KEY，不运行 key:generate 覆盖。**
3. 新建空库后执行 `php artisan db:init`，只允许空库；由标准迁移运行器初始化完整 schema 与版本登记。已有 MVP 库先备份并完成历史 001/002 SQL 的审核升级，再执行 `php artisan migrate --force`，不运行 db:init。MySQL DDL 不支持整批事务回滚，异常中断须核对实际结构后恢复，不能盲目重跑或回滚资金数据。
4. `APP_ENV=local php artisan demo:seed` 创建演示商品。演示充值继续使用 `php artisan demo:recharge USER_ID 100.00 UNIQUE_REFERENCE`，仅允许 CLI local/test。
5. 本地 API：`php artisan serve --host=127.0.0.1 --port=8080`，检查 `GET /health`。生产 HTTP 使用普通 PHP-FPM，入口 `public/index.php`；不使用开发服务器或常驻 HTTP 模式替代生产 FPM。
6. 前端：`npm --prefix web ci`，`npm --prefix web run dev`。默认代理 API 到 `http://127.0.0.1:8080`，可通过启动进程的 `API_PROXY_TARGET` 指定独立后端。构建输出为 `web/dist/`，部署时由 Web 服务器提供 SPA 静态文件并将 `/api/`、`/health` 转给 PHP-FPM；SPA 路由回落到 index.html，API 错误不能回落为页面。
7. 接入系统 cron 每分钟运行 `php artisan schedule:run`，回收过期未付订单；仅定义调度并不表示已在服务器安装 cron。本轮未修改共享服务、业务数据库或系统计划任务。

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

当前人工交付商品在 `config_cache` 保存 `prices.monthly` / `prices.annually` 十进制字符串与 `fixed_config` 固定规格；暂不接受自定义配置、优惠券或返佣商品。报价有效期 30 分钟；过期余额付款会被拒绝，需取消重下。通过 Laravel 调度每分钟运行 `php artisan invoices:expire` 回收过期未付实例与库存（每次最多 500 笔）。

实例在待交付期间 `start_date/due_date` 均为 `NULL`。后续 Worker 确认实际交付成功后，通过 `InstanceActivationService::completeCreate()` 带任务令牌与租约原子激活并起租；自然月/年到期日对齐目标月末。已有 MVP 库需先备份，再审阅执行 `database/migrations/001_delivery_dates.sql`，新库直接使用更新后的 `schema.sql`。本次代码修改不自动迁移现有数据库。

快照台账采用 `(instance_id, type, remote_snapshot_id)` 唯一键，创建未决时允许远端 ID 为 `NULL`，不同上游实例及快照/备份类型可使用相同远端编号。已有库对应迁移为 `database/migrations/002_snapshot_identity.sql`，执行前暂停快照相关调度、核对未决任务并备份；本项目不会自动执行该迁移。快照驱动和调度器目前仍是规划能力。

反向代理部署时配置 `TRUSTED_PROXIES` 为实际代理 IP 或 CIDR 列表（例如 `127.0.0.1,::1,10.0.0.0/24`）；默认不信任转发头。登录审计与验证码统一从可信 `X-Forwarded-For` 链右侧剥离代理，返回最近的非受信跳点；代理必须覆盖或正确追加真实来源地址。不会直接采纳 `CF-Connecting-IP`，Cloudflare 部署需由受信边缘规范化到 XFF 或 Web 服务器的 `REMOTE_ADDR`。畸形转发链回退连接来源 IP。

前端验证：`npm --prefix web run lint`、`npm --prefix web run typecheck`、`npm --prefix web run build`。本轮已在浏览器验证访客转登录返回、注册、下单、余额付款、取消、越权拒绝、余额不足与 390px 页面布局。需要人工浏览器复验时，可运行 `python3 scripts/test_integration.py --browser`，它只在隔离库创建演示账号并暂时关闭该库的登录/注册验证码；按回车后清理环境。默认集成测试仍验证启用验证码的真实生成与单次验签。浏览器环境不是公开体验站或生产配置。

### 隔离集成测试

```bash
python3 scripts/test_integration.py
# redis-server 不在 PATH 时：
REDIS_SERVER=/absolute/path/to/redis-server python3 scripts/test_integration.py
```

要求 Python 3.9+、Docker、PHP 和本地 `redis-server`；脚本使用 `mysql:8.0.35`（可通过 `MYSQL_TEST_IMAGE` 覆盖为兼容的 MySQL 8 镜像），自动创建独立容器、随机端口 Redis 和多进程 PHP HTTP 服务，执行真实接口/数据库检查后清理资源。测试显式指定临时环境目录与配置缓存路径，不加载部署 `.env`，不连接现有业务库。还会启动独立私有 PHP-FPM 池验证配置缓存前后的健康、登录与匿名请求隔离，测试结束销毁该池；找不到 FPM 时设置 `PHP_FPM=/absolute/path/to/php-fpm`。

覆盖并发下单请求去重、同键不同参数拒绝、有限库存占用上限、非法密码、框架嵌套事务和旧 MVP 升级数据保留，以及并发付款只扣一次、最后库存并发预留、Outbox 写入失败整笔回滚、越权阻断、验证码场景隔离与单次消费、过期报价和账本只追加约束；并验证同上游不同商品的锁隔离、可信代理 IPv4/IPv6 与伪造链、交付起租的月末/闰年运算、失效令牌/租约保护、插件替换与失效拒绝、快照跨实例/类型编号隔离及旧库迁移。IP 解析可独立运行 `php tests/unit.php`；插件解析与核心依赖边界可独立运行 `php tests/plugins.php`。Worker、退款、通知和割接协议的故障矩阵尚待对应功能实现后演练，这些静态契约修正不等于运行验收完成。

---

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
