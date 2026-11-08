# 简云 · 调度、Worker 守护与运行验收

本规程只接入现有 Laravel 调度、Outbox Worker 与日志，不替代生产 HTTP、数据库备份或完整割接。续费异常按“告警管理员、不自动退款”处理，见 [续费契约](M2_RENEWAL_CONTRACT.md)。

## 运行单元

模板位于 [deploy/systemd](../deploy/systemd/worker.service.in)，由 [渲染命令](../scripts/render_systemd.py) 生成三个带环境名称的单元：

| 单元 | 职责 |
|---|---|
| `NAME-worker.service` | 独立执行 `outbox:work`，异常或正常退出后等待 5 秒自动重启，启动后读取 MySQL 原任务 |
| `NAME-schedule.service` | 一次执行 `schedule:run`，同名 oneshot 未结束时不会叠加另一次运行 |
| `NAME-schedule.timer` | 每个自然分钟触发一次 schedule 服务；关机漏跑的分钟恢复后补触发一次，不重放全部历史分钟 |

定时器只负责每分钟唤起，业务频率仍唯一维护在 `routes/console.php`：过期单每分钟、续费出账每小时、余额代扣每 15 分钟。应用时区为 UTC；不复制业务 cron 表达式到另一套调度器。已安装 timer 的同一环境不再同时配置 cron 或 `schedule:work`。重复扫描仍由账单唯一键、付款 CAS 和 MySQL 锁兜底，不能依赖单进程替代资金保证。

Worker 被终止后不会清理或重置业务占用；过期 running 租约只允许接管同任务调查，不重发不明结果的续费或付款。普通停止不是上游请求撤销证明；发布重启也须保留原任务。失败人工核验任务重启后继续保留，不自动退款。

## 配置与安装

要求 Linux/systemd、与 HTTP 相同的 PHP 8.4、已安装依赖，以及已验收的数据库/Redis 配置。运行用户必须能读应用及原 APP_KEY，并可写 `storage/framework`、`bootstrap/cache`；正式部署使用专用非 root 用户，不通过全站可写解决权限问题。

准备 root 管理、0600 权限的 `/etc/jianyun/runtime.env`。它使用 systemd EnvironmentFile 语法，不作为 shell 脚本 source。推荐只指定外置 Laravel 配置目录及独立缓存路径，让 `/etc/jianyun/.env` 保持应用配置的单一来源：

```ini
APP_ENVIRONMENT_PATH=/etc/jianyun
APP_CONFIG_CACHE=/srv/jianyun/current/bootstrap/cache/config.php
```

Laravel 从该目录加载原 `.env`，也可按部署方案直接在 EnvironmentFile 提供进程环境；不得把凭据写进 unit、Git、命令参数或输出。配置缓存须由同一配置生成；已有密文环境保持原 APP_KEY，不重新生成。不同环境必须使用不同 env 文件、缓存路径和 unit 名称。

先渲染、检查，再安装。以下路径为示例，必须替换成实际应用、PHP 和运行用户：

```bash
python3 scripts/render_systemd.py --name jianyun-prod \
  --app /srv/jianyun/current --php /usr/bin/php8.4 --user jianyun \
  --env-file /etc/jianyun/runtime.env --output /tmp/jianyun-prod-units
systemd-analyze verify /tmp/jianyun-prod-units/*
sudo install -m 0644 /tmp/jianyun-prod-units/* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now jianyun-prod-worker.service jianyun-prod-schedule.timer
```

渲染器不读取密钥、不安装或启动服务。接管已有手工 Worker 时，先核对其 PID、cwd、exe、参数和环境归属，再停止该项目旧进程；不能按名称批量杀 PHP 或重启共享守护进程。M2 验收环境因原凭据目录位于受保护的 root 状态目录，沿用既有 root 身份，不代表正式部署的运行用户策略。

代码或进程环境更新后，用对应环境的 `systemctl restart NAME-worker.service` 加载新配置；仅 daemon-reload 不会替换运行中 PHP 代码或环境。配置缓存变更按 README 执行，更新后检查健康接口及任务列表。调度每次启动新 PHP 进程，不使用 Laravel 普通 queue:work。

## 日志与值守

Worker stdout/stderr 与 schedule 输出统一进入 journal。定时命令使用框架 after 回调取得捕获输出，再写父进程 stderr，避免 Laravel 默认丢弃子命令结果。框架在 storage/logs 中为三个命令各保留一份覆盖写的末次输出，历史由 journal 保留，不再维护第二份追加日志或同步工具。运行用户也须可写 storage/logs；不能把子命令直接重定向到 `/dev/stdout`，journal 的 socket 输出与普通文件描述符并不等价。

```bash
systemctl status NAME-worker.service NAME-schedule.timer --no-pager
systemctl list-timers NAME-schedule.timer --no-pager
systemctl show NAME-worker.service -p MainPID -p NRestarts
systemctl show NAME-schedule.service -p ExecMainStatus -p Result
journalctl -u NAME-worker.service -u NAME-schedule.service --since today -o cat
php artisan outbox:inspect
```

inspect 必须使用同一环境配置；上例命令仅在配置已正确加载的目录执行。若配置只由 systemd EnvironmentFile 注入，可以通过一次性服务安全读取同一份配置，无须 source 密钥文件（替换成实际用户和路径）：

```bash
sudo systemd-run --quiet --wait --pipe --collect \
  --property=User=jianyun --property=WorkingDirectory=/srv/jianyun/current \
  --property=EnvironmentFile=/etc/jianyun/runtime.env \
  /usr/bin/php8.4 /srv/jianyun/current/artisan outbox:inspect
```

日志等级在 Laravel 日志正文中，不能只用 journal 的 `-p` 过滤器判断应用 warning/critical。管理员需关注未确认续费、人工核验、调度失败、连续重启与 timer 不再触发，并结合持久任务列表处理。Laravel 的 schedule:run 自身退出码为 0 不保证每个子命令成功；子命令失败另写含命令名的 `Scheduled command failed` ERROR，必须检查该记录和调度输出中的 FAIL。邮件/TG仍未实现，不把“写入 journal”声称为管理员已读。

journal 的轮转、磁盘上限与跨重启保留沿用主机 journald 策略；部署时确认其持久目录和容量。本配置不修改共享 journald 设置、不删除主机日志、不引入另一份应用日志轮转。服务停止保留任务、资金、配置及历史 journal。

停用本环境时先 stop/disable timer，再 stop/disable worker；需要重启保留未决任务，不能把 running 改 pending。卸载只移除该环境的三个 unit，daemon-reload 后保留配置和数据库。

## 独立运行验收

```bash
REDIS_SERVER=/absolute/path/to/redis-server python3 -B scripts/test_integration.py --runtime
```

需要 root 与运行中的 systemd；普通集成测试不要求 systemd，不默认开启此选项。脚本使用独立 MySQL/Redis/HTTP 上游夹具，在 `/run/systemd/system` 安装随机命名的临时单元，结束后停止并删除这些单元，不接触 M2 验收库或正式库。

验收包括：真实 systemd 解析、进程守护 SIGKILL 自动重启、租约仍有效时不接管、租约过期后只读确认原续费、未知结果的 warning/critical 进入 journal、人工待办跨重启保留，调度子命令故障的 ERROR 与事务回滚，以及真实自然分钟 timer 执行 `schedule:run` 并回收过期订单。测试只加速独立库中的租约和调查窗口，不改宿主时钟。

每小时出账和 15 分钟代扣另外使用**仅测试进程的受控时钟**，实际执行 Laravel 调度及子命令，验证 UTC :00/:15/:30/:45 和普通分钟的触发差异、关闭自动续费、重复调度防重及输出采集。这不是连续等待一个小时/一刻钟的墙钟验收；开机启动配置也不等于已经重启整台共享主机。

本批不迁移 schema、不实现充值秒级唤醒或非财务暂停自动续费，正式部署仍需单独完成 HTTP、运行用户、备份及割接验收。


## 2026-11-08 验收记录

- 现有 M2 环境已安装并启用 `jianyun-m2-worker.service`、`jianyun-m2-schedule.timer`，另有 timer 调用的 `jianyun-m2-schedule.service`。已核对原手工 Worker 的 cwd、命令、DB 和 APP_KEY 后停止该单个进程；新服务使用相同数据库及密钥，受控重启后保持 active/running。
- 服务使用外置受保护 runtime.env；它是本次接管生成的进程配置快照，不进入仓库。原 env.json 及历史验收材料保留，后续更新服务配置应以当前 unit 指向的 runtime.env 为准并重启对应进程，不新增配置同步工具。API、前端、Redis 和验收 MySQL 的现有运行方式保持不变，此项不是整站生产托管验收。
- M2 timer 于 **2026-11-08 00:56（Asia/Shanghai）** 实际触发，schedule 服务成功退出，journal 收到 `Processed 0 expired invoices.` 及调度完成记录。 **01:00 自然整点**又观察到 invoices:expire、renewals:generate、renewals:debit 依次成功完成，出账/代扣均为 0，证明真实 timer 已按注册频率唤起三条命令；非零金额业务效果仍以独立库演练为证。已核对主机 journald 的活动日志文件位于持久 `/var/log/journal`；未修改其共享保留策略。
- 服务启用且完成一次受控重启后，资源 host:4250 的 active 状态及 2027-01-07 15:33:12 UTC 到期日保持；原两张 paid 账单、两条 success 任务及 token、资金流水数量均与接管前基线相同，没有新增购买、续费或退款。
- 最终独立回归通过 15 项单元、23 项插件、119 项原集成、45 项 M2、68 项续费和 **22 项运行检查**；配置缓存前后的私有 PHP-FPM 身份隔离通过，schema 仍为 27 表。临时 systemd 单元、MySQL/Redis/HTTP 进程在测试后清理；现有 M2 的常驻服务保留启用。

上述重启证据为进程/服务重启，未重启整台共享主机。真实 M2 当前没有临期实例，因此未用真实资金做新的自动代扣；小时出账/15 分钟代扣以独立库受控时钟下的实际命令结果验收。邮件/TG和充值秒级唤醒继续待办，非财务暂停仍交人工。


## M2 后台入口接入（2026-11-08）

现有前端 127.0.0.1:18211 提供 `/admin/login` 与 `/admin/tasks`，API 仍使用 127.0.0.1:18210。无需数据库迁移、新服务或第二应用；框架中间件与原任务服务负责身份/门禁。已有 Worker/schedule 单元和原 APP_KEY 继续使用，本批未对真实待办执行处理。

验收环境初始化独立 `m2-admin` 超级管理员，邮箱 `m2-admin@example.invalid` 仅作本环境占位（尚无外部通知），启用真实通知前更换为经确认邮箱。随机凭据位于 `/root/.local/state/jianyun-m2-acceptance/admin.json`（0600，父目录 0700，未入 Git），使用私有 stdin 的 admin:create 写入原 admins 并追加本地初始化审计。真实认证只读查询已验证当前原待办可见；网页裁决、重放、过期操作与并发只在独立 fixture 验收。正式环境按 README 另行创建账号，不复制测试账号或此环境凭据。

仅启用 super_admin 可以提交裁决，管理员需为证据真实性负责；上游取消不证明扣款退回或底层资源清理。续费未知只读调查与告警，无自动退款。外部推送未接入，后台仍需有人值守；失败/成功人工裁决可从原审计查证，Worker 过程继续查 journal。

## 可选云控制台直连

按用户最新定案，本站只提供 noVNC 页面/资源、权限校验和连接参数，画面及键鼠由浏览器直连独立云 WebSocket。不需要 Workerman、PHP 中继、额外监听端口/守护单元或 WS 反向代理。

1. 在 HTTP API 的受保护配置中设置精确 `CONSOLE_TARGETS=受信云控制台主机:443`（逗号分隔）。必须与 Provider 财务 API 主机名不同；生产只允许 wss、公网地址、有效 TLS。域名由运营核准，不能自动信任响应；浏览器自己解析 DNS，API 的公网预检不等于 DNS 固定。`CONSOLE_TEST_LOOPBACK` 仅隔离测试有效。已有 Redis 只承担签发限流，不再需要 GETDEL 票据。
2. 如有站点 CSP，允许 `connect-src` 到已核准云 WS 主机；允许本网站 Origin 的工作由云系统负责，不能通过改本站 CORS 代替。TLS 校验/Origin 拒绝时失败关闭，不降级加载上游财务 HTML。勿把简云主 Token 发给云系统。
3. 上一版已部署中继时，先验证直连，再停用并退役项目专属 `<name>-console.service` 及本站 `/console/ws/` 代理；保留 Worker、schedule 与共享 Redis。清除旧 CONSOLE_LISTEN/CONSOLE_ORIGINS/CONSOLE_PID_FILE，保留目标白名单；旧页面须刷新。本仓库已删除旧中继脚本、模板和生成器 --console 选项，不应再启动旧单元。
4. 客户进入 `/instances/:id/console` 显式连接。页面/静态资源应来自本站，VNC WS 应直达云域名；API 响应不得含财务域名/账号凭据/host_token/主机密码。云连接密码只在页面内存，页面 URL/本地存储不携带。日志、APM 不采集响应连接参数或 WS query。
5. 页面每 15 秒检查资格/绑定，退出或到达主 Token 到期/15 分钟上限会主动断开。但云系统实际凭据 TTL、复用和强制撤销仍由上游控制；不要把页面行为描述成本站服务器能强制切断直连。需强制撤销时另行验证上游能力。

M2 已授权切换直连并退役原专属中继；正式环境单独部署。无数据库迁移和资金/实例/Outbox 状态重写。当前只支持已验收魔方标准 WS 模式，详见 [VNC 契约](M2_INSTANCE_VNC.md)。
