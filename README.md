# JianYun (简云)

> **极简、高扩展的现代化云产品聚合销售系统**
> 面向异构上游代理（智简魔方等）与多形态自营服务（Kangle、PVE、Sub2API、手工VPS）。

## 核心设计特性

- ⚡ **极致极简**：告别旧魔方 178 张表的臃肿泥潭，仅用 8 张核心领域表（全系统共精简收敛为 26 张物理表）支撑 100% 真实业务。
- 🔌 **驱动插件化**：无论是魔方代理、自营虚拟主机还是 AI 额度分发，均统一抽象为 `Provider` 驱动。
- 🛡️ **事务发件箱 (Transactional Outbox)**：杜绝“先调上游接口导致网络超时丢任务”，确保支付、账务与履约百分之百一致。
- 🔄 **双阶段自动续费**：出账与代扣彻底解耦，无论何时充值余额，均能秒级自动代扣续费，杜绝欠费假死停机。
- 📈 **智能升降级与改价神器**：弹性云按天补差（降级 0 元不退款）；后台支持单实例一键改续费价并自动划扣当期差价。
- 🔒 **返佣防套利**：退款与返利实时级联冲正，彻底杜绝自推自买套现。

---

## 当前实现与本地启动

第一阶段 API 闭环已实现：图片验证码、注册登录、固定套餐人工交付商品、下单预创建实例、余额付款、资金流水与 Outbox 同事务入库。真实上游驱动、第三方支付、Worker、自动续费、返佣和前端尚未实现。付款后的实例和任务均保持 `pending`，不会实际开机。

运行要求：PHP 8.2+（`pdo_mysql`、`bcmath`、`openssl`、`gd`、`curl`）、MySQL 8.0+、Redis 5+。数据库结构以 `database/schema.sql` 为准。

1. 将 `.env.example` 复制为本地 `.env`，填写独立开发数据库的连接信息与随机 `APP_KEY`。生成密钥：`php -r 'echo "base64:" . base64_encode(random_bytes(32)) . PHP_EOL;'`。
2. 将配置注入进程环境（当前不自动加载 `.env`；简单配置可在 Bash 使用 `set -a; source .env; set +a`，含空格或特殊字符的值需正确引用）。
3. 对已创建的**空数据库**运行 `php scripts/console.php db:init`；此命令拒绝对非空数据库执行，不是增量迁移器。
4. `APP_ENV=local` 时运行 `php scripts/console.php demo:seed`，得到模拟商品 ID。
5. 运行 `php -S 127.0.0.1:8080 -t public public/index.php`，检查 `GET /health`。

注册前请求 `GET /api/v1/captcha/config?scene=register`，显示 `data.img_base64` 并填写图片字符。注册请求中的 `captcha_payload` 为 `{ "challenge_id": "返回的ID", "answer": "图片字符" }`；登录使用独立的 `scene=login` 挑战。挑战有效期 5 分钟，尝试一次即销毁。默认所有认证场景启用图片验证码，可通过 `system_settings.key=captcha_scenes` 的 JSON 值按场景配置 `enabled`、`mode=always`、`driver=builtin_image`，其他驱动/模式尚未安装，配置后会拒绝请求。

注册后用返回的用户 ID 执行 `php scripts/console.php demo:recharge USER_ID 100.00 UNIQUE_REFERENCE` 注入演示余额；同一个参考号重复执行不会重复入账。演示充值仅允许 CLI 且 `APP_ENV=local/test`，无公开充值接口。

携带 `Authorization: Bearer <token>` 请求 `POST /api/v1/orders/checkout`：

```json
{
  "product_id": 1,
  "billing_cycle": "monthly",
  "config_options": {},
  "hostname": "demo-host",
  "password": "ExampleRootPass!23",
  "use_balance": true
}
```

`product_id` 使用 seed 返回值；`use_balance=true` 在下单同事务中付款，余额不足则整个下单回滚。省略或设为 `false` 返回未付账单，再通过 `POST /api/v1/invoices/{id}/pay`、载荷 `{ "gateway": "balance" }` 付款。`GET /api/v1/invoices/{id}` 查看状态；`POST /api/v1/invoices/{id}/cancel` 取消未付账单并归还预留库存。金额均使用十进制字符串，客户端提交金额不参与定价。

当前人工交付商品在 `config_cache` 保存 `prices.monthly` / `prices.annually` 十进制字符串与 `fixed_config` 固定规格；暂不接受自定义配置、优惠券或返佣商品。报价有效期 30 分钟；过期余额付款会被拒绝，需取消重下。每分钟运行 `php scripts/console.php invoices:expire` 回收过期未付实例与库存（每次最多 500 笔）。

### 隔离集成测试

```bash
python3 scripts/test_integration.py
# redis-server 不在 PATH 时：
REDIS_SERVER=/absolute/path/to/redis-server python3 scripts/test_integration.py
```

要求 Python 3.9+、Docker、PHP 和本地 `redis-server`；脚本使用 `mysql:8.0.35`（可通过 `MYSQL_TEST_IMAGE` 覆盖为兼容的 MySQL 8 镜像），自动创建独立容器、随机端口 Redis 和多进程 PHP HTTP 服务，执行真实接口/数据库检查后清理资源。测试不加载部署 `.env`，不连接现有业务库。

覆盖并发付款只扣一次、最后库存并发预留、Outbox 写入失败整笔回滚、越权阻断、验证码场景隔离与单次消费、过期报价和账本只追加约束。

---

## 架构文档导航

- [协同开发指南与工程宪法](AGENTS.md)
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
│   ├── Controllers/
│   ├── Models/
│   └── Services/
├── plugins/                   # 驱动与插件扩展目录
│   ├── providers/             # 上游服务驱动 (zjmf, easypanel, sub2api, manual)
│   ├── payments/              # 支付网关插件 (alipay, epay, balance)
│   └── notifications/         # 消息通道 (mail, sms, tg)
├── database/                  # 数据库迁移与初始化 SQL
├── scripts/                   # 运维工具与旧库迁移导入脚本
└── config/                    # 系统配置
```
