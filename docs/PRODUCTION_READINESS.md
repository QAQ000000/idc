# 首版冻结、独立正式环境与邀请试售

**当前安排（2026-11-08 更新）**：用户选择直接在现有 M2 测试环境手动验收。独立正式部署和 3–5 人邀请试售暂缓，不是当前测试前置；本页模板、演练证据及未来部署步骤保留参考，不据此自动推进部署。当前访问入口与工作安排见 [README](../README.md#当前安排m2-测试环境手动验收)。

## 范围与交付状态

2026-11-08 以 `255d807` 的已验收功能冻结首版；本批只增加部署模板、安装演练和运营规程，不扩展业务 API、表或驱动。本文是正式部署入口，运行单元细节仍以 [运行规程](DEPLOYMENT_RUNTIME.md) 为准，真实业务样本以 [路线图](ROADMAP.md)、[配置销售](M2_CONFIG_SALES.md) 和 [客户自助](M2_CUSTOMER_SELF_SERVICE.md) 为准。

本批完成准备和隔离演练，不代表正式环境已经部署、域名已切换或已开放试售。生产域名、服务器、证书、商户回调可达性、独立上游账户和管理员收件人，均需实际部署时登记确认。M2 的服务、库、Redis、密钥及已交付资源不搬迁、不改写；旧账单 3 的实收继续留在 M2 管理员核验，不转为试售订单或生产余额。

| 首版保留 | 暂不扩展 |
|---|---|
| 单 Vue/Element Plus、注册/登录、本人账单与密码自助 | 多端、营销、佣金、新上游和高级选配 |
| 现有魔方驱动、易支付、付款与 Outbox、冻结报价/续费价 | 自动退款/补偿、未知结果自动重购或重发 |
| 固定/独立枚举商品、续费出账和 15 分钟代扣 | 充值秒级唤醒、非财务暂停自动续费 |
| 商品后台、异常任务核验、状态/电源、本站 VNC 页面直连云控制台 | 通用重装、变配、快照、全生命周期承诺 |
| 单邮件人工核验告警、主动密码重置 | 多渠道、营销邮件、默认开启客户临期提醒 |

VNC 继续采用既定边界：页面/静态资源本站，WSS 直连独立云控制台，浏览器可见本次云连接地址/凭据；财务域名和账户密钥留服务端，不重建数据中继。只有登记并验证的云控制台目标才写入 `CONSOLE_TARGETS`。

## 部署拓扑与配置归属

```text
客户 → HTTPS Nginx → web/dist（单 Vue）
                  → /api/*、/health → 专属 PHP 8.4 FPM UNIX socket
支付网关 → /api/v1/payments/epay/notify → 同一 FPM/业务库
专属 Outbox Worker、mail Worker、分钟 timer → 同一正式库/原有领域服务
验证码/限流 → 正式环境专属 Redis；邮件 → 经确认 SMTP
```

PHP 8.4.x + 支持该版本的 Composer 2（不使用旧 2.0）、MySQL 8.0+、Redis、Nginx、systemd；构建机 Node.js 22.12+。扩展以 `composer check-platform-reqs --no-dev` 为准。构建/发布读取锁文件，不在服务器执行依赖升级。生产不用 `artisan serve`、Vite 开发服务器或第二套 Node 后端。

建议目录（名称可调整，但所有渲染参数必须一致）：

| 路径/对象 | 用途与权限 |
|---|---|
| `/srv/jianyun-prod/releases/RELEASE`、`current` | 版本与当前软链；代码由发布者管理，运行用户不可改代码 |
| `/srv/jianyun-prod/shared/storage` | `framework/cache/data`、`framework/sessions`、`framework/views`、`logs`；运行用户可写 |
| `current/bootstrap/cache` | 当前版本私有可写缓存，目录 0700/文件 0600；配置缓存含明文解密密钥 |
| `/etc/jianyun-prod/.env` | 唯一应用配置，root:运行组 0640、父目录 0750；不进 Git/网站根目录 |
| `/etc/jianyun-prod/runtime.env` | 仅三条配置路径，交给 systemd；不是应用配置副本，不用 shell source |
| `/etc/jianyun-prod/http` | 生成的 FPM pool/master、FPM unit 和 Nginx site |
| `jianyun` 用户、专属 DB/DB 用户、专属 Redis | 不复用 M2/root DB 账号、Redis 实例或服务名；DB 用户只授权本库 |
| `/run/jianyun-prod/fpm.sock`、`/var/log/jianyun-prod` | 专属 socket 与日志；Nginx 组可连 socket，日志目录由 root 管理且应用用户不可写，限制读取/不得公开下载 |

Redis 的 DB 编号不是权限隔离。本机演练借用临时 Redis 的 DB 1；实际生产应使用独立实例/凭据。FPM pool `clear_env=yes`，只传三个路径；HTTP/CLI 从同一 `.env` 和当前版本配置缓存读取。不要在系统服务环境中残留第二组 APP_KEY/DB 配置覆盖 `.env`。非 root Worker；FPM master 创建 socket 后由 pool 降权运行。

## 新环境安装顺序

1. 先保持域名/防火墙封闭，备份计划及值守人就位。创建专属系统用户、空 MySQL 库/本库用户和 Redis，核验 PHP CLI 与 FPM 为同一 8.4 版本且扩展一致。MySQL 迁移需要建表/触发器权限；启用 binlog 时由 DBA 先审阅 schema 触发器，再选择专门的迁移账号或在专属实例上配置 log_bin_trust_function_creators；本库授权本身不足以绕过错误 1419，不给应用全局 SUPER。演练只在一次性容器启用该开关。不要恢复 M2 SQL，不运行演示充值或 seed。
2. 导出经审阅提交的干净版本到新 release，保留 `composer.lock` 和 `web/package-lock.json`。在构建目录执行：

   ```bash
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   composer check-platform-reqs --no-dev
   npm --prefix web ci
   npm --prefix web run build
   ```

   后续不需要运行 Node。设置 `current` 指向该 release；代码只读，只放行表中的写目录。Nginx 根目录必须是 `current/web/dist`，不是项目根或 `public`。
3. 复制 [app.env.in](../deploy/production/app.env.in) 到私有 `.env`，替换所有示例值。为**全新库**生成独立随机 APP_KEY；已有密文的环境必须保留旧 key，不能自动 key:generate。包含空格/特殊字符的 `.env` 值按 dotenv 语法引用，不能直接拼接 shell。生产 `APP_ENV=production`、`APP_DEBUG=false`、APP_URL 为真实 HTTPS origin，CORS 默认同源、TRUSTED_PROXIES 默认空，仅在真实代理存在时设置确切地址。
4. 复制 [runtime.env.in](../deploy/production/runtime.env.in)，三个路径与 FPM 参数一致。以下运维 helper 明确传路径，不从 `.env` 导出密钥到命令行（PHP 路径须替换为本机实际值）：

   ```bash
   jy() {
     runuser -u jianyun -- env \
       APP_ENVIRONMENT_PATH=/etc/jianyun-prod \
       APP_CONFIG_CACHE=/srv/jianyun-prod/current/bootstrap/cache/config.php \
       LARAVEL_STORAGE_PATH=/srv/jianyun-prod/shared/storage \
       /usr/bin/php /srv/jianyun-prod/current/artisan "$@"
   }
   umask 0077
   jy db:init
   jy migrate --force
   jy admin:create < /etc/jianyun-prod/admin.json
   ```

   `admin.json` 由 [模板](../deploy/production/admin.json.in) 私下填入唯一登录名、随机密码和真实管理员邮箱，0640 且运行用户可读。没有默认账号；重复初始化拒绝覆盖。成功后移出运行目录并按凭据策略保管/销毁，绝不写 Git。`db:init` 只允许空库；已有库只备份并 migrate，DDL 中断须核实实际结构，不能盲目 down/reinit。
5. 从 [连接模板](../deploy/production/connections.json.in) 私下配置已批准上游和唯一支付渠道：`jy connections:install < 私有连接.json`。这会只读预检后新建 Provider、**立即上架固定商品并启用支付**，不是更新已有绑定；不可重复执行来改凭据。站点和 Worker 此时仍须封闭。商品 391/¥0.01 只是已验收样本示例，当前供应/价格必须重新预览核验；商品 14 通过后台既有导入预览（默认下架），不把配置商品塞进固定商品安装命令。
6. 从 [邮件模板](../deploy/production/mail.json.in) 填写经确认的 SMTP 及本次新建管理员 ID：`jy notifications:configure ADMIN_ID < 私有邮件.json`。使用 465/smtps 或经服务商确认的 starttls；服务器需可信 CA，不能关闭证书校验。此命令仅加密保存和审计，不发送。告警与主动密码重置共用现有 mail Worker，真实客户临期提醒先关闭。正式发信须另有明确收件人与验收发送授权；M2 的两封验收授权不延伸到生产。
7. 生成证书、DNS 与 Nginx/FPM 配置。证书须匹配实际域名，自动续期任务和到期监控由部署侧负责；首次 ACME 需先以仅开放 challenge 的临时站点取得证书，完整 HTTPS 模板不适用于尚无证书的启动。示例参数仅是路径示意，必须替换域名、PHP 路径、Nginx 运行组与受邀公网 IP：

   ```bash
   python3 scripts/render_http.py --name jianyun-prod \
     --app /srv/jianyun-prod/current --user jianyun --web-group www-data \
     --env-dir /etc/jianyun-prod --storage /srv/jianyun-prod/shared/storage \
     --socket /run/jianyun-prod/fpm.sock --fpm /usr/sbin/php-fpm8.4 \
     --cert /etc/letsencrypt/live/shop.example.com/fullchain.pem \
     --cert-key /etc/letsencrypt/live/shop.example.com/privkey.pem \
     --mime-types /etc/nginx/mime.types --log-dir /var/log/jianyun-prod \
     --acme-root /var/lib/jianyun-acme --domain shop.example.com \
     --invite-cidr 192.0.2.10/32 --output /etc/jianyun-prod/http
   python3 scripts/render_systemd.py --name jianyun-prod \
     --app /srv/jianyun-prod/current --php /usr/bin/php --user jianyun \
     --env-file /etc/jianyun-prod/runtime.env --output /etc/jianyun-prod/units
   jy config:cache
   ```

   生成器只写文件，不安装/启动。路径使用保守字符集，不含空格；网络参数禁止注入。预建日志/ACME目录，设置 Nginx 可读的静态文件和 socket 组权限。`site.conf` 只包含到现有 Nginx `http {}`；不可替换共享主配置。FPM 的 master.conf 引用生成目录，部署后不要移动其中某一个文件。
8. 核验 `php-fpm -t -y /etc/jianyun-prod/http/master.conf`、`nginx -t`、`systemd-analyze verify`；安装专属 FPM unit 及既有四个 unit，`daemon-reload`。先启动 FPM，只对管理员 IP 开放站点，确认 HTTPS、SPA 深链、验证码、管理员登录、权限及下架状态，再启用 Worker、mail 和 schedule.timer。正式运行命令和日志查看沿用[运行规程](DEPLOYMENT_RUNTIME.md)，不要同时安装第二份 cron。

渲染器会将 current 解析为具体版本目录；每次发布必须重新渲染 HTTP/四个运行 unit、重新安装并 daemon-reload，经 nginx -t 后重载专属站点配置，不能只换 current 软链。配置缓存重建和发布切换后必须重启**正式环境专属** FPM、Worker、mail，核验进程 cwd/版本/配置路径及 timer。不能只重载 Nginx 就认为长进程已经加载新代码；不执行共享服务或 M2 的 stop/restart。

## HTTPS、回调与日志验收

- 支付渠道 endpoint、notify_url、return_url 使用正式可达 HTTPS 地址；不能沿用 M2 的 127.0.0.1:82。异步回调是 `GET/POST /api/v1/payments/epay/notify`（query 或表单，不是 JSON），成功必须返回原样 `success`。回跳页面不是付款证据。商户后台回调地址、DNS/TLS、网关真实重试均须部署后核实。
- 试售 IP 访问限制同时作用于页面和 API；`--invite-cidr` 可重复传入。仅不传该参数时才无 IP 门禁。支付回调精确 location 单独 `allow all`，上层防火墙/CDN/WAF 也须放行此路径，否则会漏收回调。ACME challenge 保持可访问。若有 CDN/代理，先在可信边缘落实邀请门禁和真实 IP，不能把代理出口 IP 放行后宣称已限制受邀客户。
- 页面、API、重置邮件链接同 origin；`Authorization` 必须经 FastCGI 转发。生产证书不能用演练自签证书代替。未知 API 返回 JSON 404、未知静态资源返回 404，不能回退为 HTML。`.env`、PHP 源文件不可下载。
- Nginx access log 不记 query/body/Authorization，重置 token 只在 URL fragment；应用不记原始错误或凭据。Nginx error log 在传输异常时仍可能含请求 URI，限权存储、限制读取、设轮转和保留期；采集器不得新增原始请求体或 header。Worker/mail/schedule journal 同样要采集并限制访问，磁盘和持久人工待办需有人巡检。
- 用已授权的生产验收订单验证：原报价与首期/续费价、真实回调只结算一次、原任务只交付一次、远端归属/规格、异常核验网页入口；只在明确金额和付款授权后做，不调用真实扣款来验证模板。

## 备份、恢复与发布回退

备份必须同时保留 MySQL 一致性快照（含触发器）、对应代码/迁移版本、原 APP_KEY 和恢复所需私有配置。DB 中的上游/支付/SMTP/主机密码密文离开原 key 无法恢复。备份加密异机保存、限制下载并记录保留期/责任人；首次开放前恢复到另一空库，核对行数、资金汇总、任务/账期和密文可解，不把“备份命令退出 0”当作可恢复。

发布前关闭新购入口并等待正在执行的任务到达可判定状态；停目标环境 Worker 后记录仍在途/未知任务，先快照再迁移，切换版本/重建缓存/重启后从原任务恢复。未知结果不得清空或重置任务。MySQL DDL 不是整批可回滚事务；代码回退仅限与当前 schema/Token 撤销语义兼容的已验证版本，不执行 `migrate:fresh`/自动 down，不用旧库快照覆盖新收款。若恢复只能丢弃新收款，必须先停止接单并人工对账。

停止销售首先是下架/访问门禁，不能同时屏蔽回调、调查、存量续费或已付交付。必要暂停金融进程时保留支付记录/任务事实并安排值守恢复；不要以停站代替异常结案。手动退款保留真实流水及经办记录，不自动退款，不伪造成功终态。

## 3–5 名受邀客户的试售步骤

1. 指定每天值守时段、主责/备援管理员与联系渠道；告警邮件只是入口，必须巡检 `/admin/tasks`、邮件待核验和日志。暂不承诺无人值守或 24 小时自动修复。
2. 首轮只允许同一已验收魔方上游的商品 14、已验收的一组月付配置、Debian 12、root/22；规格与证据边界见客户自助契约。商品 391 仅在重新确认可供后作为可选体验项。默认不批量上架其他商品，不把某组配置验收推广到全 Schema；吞吐/线路/长期稳定性、其他镜像未承诺，数据盘默认不代挂载。
3. 当前系统没有邀请代码或按配置白名单的商品锁定机制。首轮由 IP 门禁限制受邀人，管理员逐笔核对配置后才通知其付款；页面可选配置超出样本时不邀请购买，发现误付按已有任务/人工流程处理。若需要向不受控公众开放，必须另行决定商品限制能力，不能以隐藏链接替代约束。
4. 部署前重新只读报价；新购报价与实例续费价以订单冻结为准，不沿用历史 ¥16 的口头保证。设定每人 1 单、首轮总单量/预算，经运营确认后逐单放行。没有系统限购就不宣称已自动限额，管理员分批加 IP 并巡检订单。
5. 首单真实支付/开通和基础可用性完成后再邀请下一位。失败/未知转原任务调查，不重购/盲目重启；管理员确认已履约只回填，款项处置由人工记录。续费失败只通知管理员，不新建自动退款；邮件未知保留单次占位，不重发轰炸。
6. 观察至少一个完整日的告警、调度与备份，并安排临期/续费验证；支付交付出现重复扣款/归属错配/未知任务无人处理时立即暂停新售。试用反馈先修真实缺陷，不自动解冻首版功能范围。

## 可重复安装演练与本批证据

```bash
REDIS_SERVER=/absolute/path/to/redis-server \
  python3 scripts/test_integration.py --runtime --deployment
# 默认 Composer 过旧时，设置 COMPOSER_BIN=/absolute/path/to/new-composer.phar
# 修正部署模板时可用 --deployment-only 单独重跑安装，不能冒充完整业务回归
python3 scripts/sync_schema_docs.py --check
git diff --check
```

部署演练需要 root、运行中的 systemd、Nginx、匹配的 PHP-FPM、Composer、npm、OpenSSL 和 Docker。它仅在临时测试容器中新建 `jianyun_release_test`/`jianyun_restore_test`，使用受限 DB 用户、非 root 应用进程、临时 TLS/Redis 命名空间和本地上游/SMTP 夹具；从锁文件重新安装后端/前端，重建配置缓存，空库初始化并拒绝覆盖，初始化管理员，再经真实 HTTPS/Nginx/FPM 完成注册/权限/账单/重复回调/Worker 交付与 TLS 邮件、恢复密文和邀请门禁。新进程/容器自动清理，不读取 M2 配置、不占 80/443、不 reload 共享 Nginx，不向真实上游或邮箱发送业务请求。依赖下载仅访问包仓库。

本机演练只能证明模板在当前软件版本及协议夹具下成立；生产域名、公共证书、真实网关/SMTP、备份异地存储、机房重启、实际商品供应和受邀用户收件仍是部署验收事项。真实生产启用和试售尚未执行。


### 2026-11-08 本机验收记录

- 现有业务 **694** 项、独立守护/调度故障演练 **25** 项通过；前端 lint、typecheck、干净副本 npm ci/build，27 表字典同步及 diff 检查通过。
- 新增安装演练 **66** 项通过（Python 29 + PHP 三阶段 12/20/5）：production 配置缓存、受限库账号、27 表/6 条迁移/5 个保护触发器、拒绝非空初始化和管理员覆盖、HTTPS/308/静态资源/API、默认验证码与权限、原单重放、重复 GET/POST 收款、真实非 root systemd Worker/mail/调度、TLS SMTP 单封投递、另一空库恢复全表逐行摘要/触发器及原 key 解密、邀请门禁与公开回调、日志不含回调参数。
- 本机使用 PHP CLI/FPM 8.4.21、Composer 2.11.1、MySQL 8.0.35、Nginx 1.30.4、Node 24.16.0。默认系统 Composer 2.0.14 无法完成本次锁文件安装，演练通过 COMPOSER_BIN 指向已有独立新版 PHAR，没有替换共享程序。本机面板 Nginx 编译了 Lua，隔离前缀需要显式指向它的现有库路径；这仅是测试主配置的适配，不增加应用依赖。
- 新部署检查针对 production 模式的独立副本与协议夹具；临时 TLS 只信任本次自签证书，本地 SMTP 不发外网。MySQL 1419 的触发器创建前置已写入安装步骤；既有 M2 API 与三项守护/调度仍健康，不执行其数据库迁移、重启或业务变更。
- 本批没有业务/schema/接口变更，也没有将完整 M2、生产割接或其他延期能力勾为完成。
