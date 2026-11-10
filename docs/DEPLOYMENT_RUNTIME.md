# 简云 · 调度、Worker 守护与运行验收

本规程只接入现有 Laravel 调度、Outbox Worker 与日志，不替代生产 HTTP、数据库备份或完整割接。续费异常按“告警管理员、不自动退款”处理，见 [续费契约](M2_RENEWAL_CONTRACT.md)。

正式环境的完整安装顺序、Nginx/PHP-FPM、HTTPS/回调、备份恢复和邀请试售以 [正式部署入口](PRODUCTION_READINESS.md) 为准；下方 M2 记录保留其原批次边界。

## 运行单元

模板位于 [deploy/systemd](../deploy/systemd/worker.service.in)，由 [渲染命令](../scripts/render_systemd.py) 生成四个带环境名称的单元：

| 单元 | 职责 |
|---|---|
| `NAME-mail.service` | 独立执行 notifications:work；SMTP 不阻塞金融 Worker/调度，重启不重新认领 sending |
| `NAME-worker.service` | 独立执行 `outbox:work`，异常或正常退出后等待 5 秒自动重启，启动后读取 MySQL 原任务 |
| `NAME-schedule.service` | 一次执行 `schedule:run`，同名 oneshot 未结束时不会叠加另一次运行 |
| `NAME-schedule.timer` | 每个自然分钟触发一次 schedule 服务；关机漏跑的分钟恢复后补触发一次，不重放全部历史分钟 |

定时器只负责每分钟唤起，业务频率仍唯一维护在 `routes/console.php`：过期单每分钟、续费出账每小时、余额代扣每 15 分钟；通知来源补扫和悬空认领恢复每分钟，不在调度进程内发送邮件。应用时区为 UTC；不复制业务 cron 表达式到另一套调度器。已安装 timer 的同一环境不再同时配置 cron 或 `schedule:work`。重复扫描仍由账单唯一键、付款 CAS 和 MySQL 锁兜底，不能依赖单进程替代资金保证。

**充值唤醒增量**：原 Worker 每轮先消费一个 Redis 客户提示，复用原代扣服务检查其已有续费单，再处理原 Outbox；无新增 unit 或调度。HTTP 与 Worker 须使用同一 APP_KEY、DB 和 Redis 环境。更新目标环境后按本规程核验归属并重启原 Worker；不用迁移数据库、重置任务或补发已收款通知。Redis 故障会记录 `Renewal wake-up unavailable; scheduled MySQL debit remains active`，当前进程退避 60 秒但继续原 Outbox；信号丢失仍由 15 分钟扫描恢复。该 warning 仅表示提前扫描降级，不表示充值失败或需要重复付款，见 [完整边界](M2_RENEWAL_CONTRACT.md#充值到账后的异步唤醒)。

**本批 M2 已加载（2026-11-08）**：21:11:04（Asia/Shanghai）已核对归属并正常重启原 Worker，PID 819426 → 916250、active/running、NRestarts=0；HTTP／Worker 密钥、DB、Redis 一致，健康接口正常。21:15 自然 timer 的代扣为 0，21:15:15 用户真实充值 ¥0.01 后，原 Worker 同秒代扣 CDN 原续费单 #5，任务 #8 于 21:15:32 观察到 success、关联上游 811257；无须等到 21:30 扫描。用户确认上游手动改过账期并明确授权本地校准，校准审计 #6、历史权益记录及完整资金／防重证据单列在 [本次验收](M2_RENEWAL_CONTRACT.md#m2-运行接入与真实-cdn-验收2026-11-08)。仅现有 M2 接入，无新 unit／迁移或正式环境部署；不复用这次测试校准作为日常调整客户权益的授权。

Worker 被终止后不会清理或重置业务占用；过期 running 租约只允许接管同任务调查，不重发不明结果的续费或付款。普通停止不是上游请求撤销证明；发布重启也须保留原任务。失败人工核验任务重启后继续保留，不自动退款。

## 可选商品刷新计划（默认关闭）

`PRODUCT_REFRESH_IDS`显式指定本地商品ID；空值不处理任何商品。用原渲染器`--product-refresh`额外生成NAME-products.service／timer，按[发布契约](M2_PRODUCT_REFRESH.md#独立计划模板与启用)单独核对和安装这两个unit，不能覆盖／重启原四个服务。独立oneshot每批最多2商品、每商品10分钟，同名timer／MySQL锁防重叠，不在金融schedule:run或Outbox中等待上游。运行观察只写原settings及新成功快照，客户仍在线核价；M2启用范围另验，无迁移或Worker重启要求。

停止timer／service会保留旧快照与running观察；不要重置任务。关注journal中的每商品失败和详情snapshot_refresh新鲜度，命令结束不等于所有商品刷新成功。定时器正常、异常中断／恢复可用独立验收`python3 scripts/test_integration.py --product-refresh-runtime`（另设REDIS_SERVER）验证，不接触M2单元。

### 2026-11-10 M2本地预览及数据库恢复

M2已显式纳入本地商品2并开启PRODUCT_LOCAL_PREVIEW；只安装新增products.service／timer，按原runtime.env更新配置缓存，客户端仍保留calculate和checkout在线复核。实页7次preview／0次calculate、6组选配含防抖890–922ms，见[客户预览记录](M2_LOCAL_PREVIEW.md#页面实测)。

期间原M2数据库容器被误删，用户授权重新部署；已从11月9日15:12:22完整备份恢复27表／5触发器，独立jianyun-m2-mysql以仓库外mysql-data持久目录和原回环端口运行。**备份之后的资金／任务缺口尚未核对**，原金融Worker、schedule.timer与mail Worker已停止并禁用自动启动；HTTP／前端和只读商品刷新可验。此状态取代下文历史“原Worker已运行”的当前运行结论。重新开启业务进程前核对真实收款／资源／账期及通知订阅，不能自动按旧数据补扣、重发或声称历史记录完整。私有恢复证据不进入Git，无真实购买、退款或上游写入。

## 到期状态核对接入（M2 已验收）

无迁移、unit、Redis任务或新调度。原 outbox:work 每轮先充值提示及履约任务；两者均无工作才核对一个临期／暂停／核验资源，同资源至少15分钟、空候选进程内退避15秒。金融繁忙会延后核对，不能承诺精确15分钟SLA；--once 保持原唤醒／Outbox行为，不追加状态核对。显式单资源命令 `php artisan instances:reconcile-status ID` 仍会写运营观察，须按环境授权执行，不作纯查看命令。

2026-11-09 15:04:38（Asia/Shanghai）已按归属和空闲门禁正常停启原 Worker，PID 1004092→1092819，重启前后关键表哈希不变，HTTP／Worker 环境一致。用户在上游设置真实 due 暂停并授权本地账期校准（审计#9）后，体验CDN391／4250于15:17:12真实充值#10／¥0.01、15:17:13代扣原续费单#9、15:17:30唯一任务#10确认上游811262。远端Active和目标日期确认后本地恢复；重复原凭证通知与额外扫描无资金／任务变化。仅此CDN已验，供应商保留期、其他资源和自动销毁不在验收内；无迁移或共享服务重启。完整证据与校准边界见[契约](M2_EXPIRY_RECOVERY.md#m2-运行接入与体验-cdn-真实恢复2026-11-09)。

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
sudo systemctl enable --now jianyun-prod-worker.service jianyun-prod-mail.service jianyun-prod-schedule.timer
```

渲染器不读取密钥、不安装或启动服务。接管已有手工 Worker 时，先核对其 PID、cwd、exe、参数和环境归属，再停止该项目旧进程；不能按名称批量杀 PHP 或重启共享守护进程。M2 验收环境因原凭据目录位于受保护的 root 状态目录，沿用既有 root 身份，不代表正式部署的运行用户策略。

代码或进程环境更新后，用对应环境的 `systemctl restart NAME-worker.service` 加载新配置；仅 daemon-reload 不会替换运行中 PHP 代码或环境。邮件发送代码更新时另重启同环境 NAME-mail.service，不能把 sending 重置 pending。配置缓存变更按 README 执行，更新后检查健康接口及任务列表。调度每次启动新 PHP 进程，不使用 Laravel 普通 queue:work。

## 日志与值守

Worker stdout/stderr 与 schedule 输出统一进入 journal。定时命令使用框架 after 回调取得捕获输出，再写父进程 stderr，避免 Laravel 默认丢弃子命令结果。框架在 storage/logs 中为每个调度命令各保留一份覆盖写的末次输出，历史由 journal 保留，不再维护第二份追加日志或同步工具。运行用户也须可写 storage/logs；不能把子命令直接重定向到 `/dev/stdout`，journal 的 socket 输出与普通文件描述符并不等价。

```bash
systemctl status NAME-worker.service NAME-mail.service NAME-schedule.timer --no-pager
systemctl list-timers NAME-schedule.timer --no-pager
systemctl show NAME-worker.service -p MainPID -p NRestarts
systemctl show NAME-schedule.service -p ExecMainStatus -p Result
journalctl -u NAME-worker.service -u NAME-mail.service -u NAME-schedule.service --since today -o cat
php artisan outbox:inspect
php artisan notifications:inspect
```

inspect 必须使用同一环境配置；上例命令仅在配置已正确加载的目录执行。若配置只由 systemd EnvironmentFile 注入，可以通过一次性服务安全读取同一份配置，无须 source 密钥文件（替换成实际用户和路径）：

```bash
sudo systemd-run --quiet --wait --pipe --collect \
  --property=User=jianyun --property=WorkingDirectory=/srv/jianyun/current \
  --property=EnvironmentFile=/etc/jianyun/runtime.env \
  /usr/bin/php8.4 /srv/jianyun/current/artisan outbox:inspect
```

日志等级在 Laravel 日志正文中，不能只用 journal 的 `-p` 过滤器判断应用 warning/critical。管理员需关注未确认续费、人工核验、调度失败、连续重启与 timer 不再触发，并结合持久任务列表处理。Laravel 的 schedule:run 自身退出码为 0 不保证每个子命令成功；子命令失败另写含命令名的 `Scheduled command failed` ERROR，必须检查该记录和调度输出中的 FAIL。单邮件已实现，TG 未实现；邮件结果未知同样需检查持久列表，不把 journal 或 SMTP 接受声称为管理员已读。

journal 的轮转、磁盘上限与跨重启保留沿用主机 journald 策略；部署时确认其持久目录和容量。本配置不修改共享 journald 设置、不删除主机日志、不引入另一份应用日志轮转。服务停止保留任务、资金、配置及历史 journal。

停用本环境时先 stop/disable timer，再 stop/disable worker 和 mail；需要重启保留未决任务，不能把 running 改 pending。卸载只移除该环境的四个 unit，daemon-reload 后保留配置和数据库。

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


## 单邮件接入

先备份并运行迁移 2026_11_08_000006，再从私有 stdin 配置 notify.mail（完整格式见通知契约）。保留原 APP_KEY；SMTP 配置整体密文保存到数据库，不添加 unit 内的 SMTP 密钥或第二套环境副本。管理员目标来自当前 admins.email，示例占位邮箱不能启用。配置名仅单一 mail，本批不建设动态后台设置页。

安装生成器新增的 NAME-mail.service 并启用：`systemctl enable --now NAME-mail.service`。原 schedule 每分钟补扫 notifications:sweep；SMTP 由 mail 进程完成，因此其阻塞或崩溃不会卡住出账/代扣和金融 Outbox。已部署的前三个 unit 不必因模板增加而重装共享服务；只安装新邮件单元并检查归属。停止 mail 保留 pending/sending；未知 sending 满 15 分钟由补扫转核验，不自动重发，启停均不能删除占位。

以 notifications:inspect 查看消息编号/状态/指纹，关联任务仍从原网页核验。发送未知时使用 notifications:resolve 提交当前超级管理员和 SMTP 日志证据，只关闭通知事实，不重发、不处理原任务、不退款。`success` 只证明 SMTP 接受/人工确认，不证明收件箱收到。部署验收应分别检查四个单元、SMTP/TLS、持久防重和真实收件，不能仅看调度退出码。


2026-11-08 本批 M2 已完成 000006 迁移和通知部署：只新增并启用 jianyun-m2-mail.service（相同 PHP/cwd/runtime.env），原 Worker 和分钟 timer 保留。管理员邮箱已按用户确认更换并留审计，配置密文在 notify.mail；task_alerts=true、due_reminders=false。真实任务 3 的告警已有 success/SMTP_ACCEPTED，原待核验任务未裁决；模拟客户到期提醒只在独立库向授权邮箱发一封。两封均已获 SMTP 接受，用户随后确认两封均已收到；不向真实客户测试发送。

本批完整隔离回归 632 业务 + 25 运行检查通过；后者增加邮件守护 SIGKILL 重启、悬空 claim 转核验和原未知投递保留。备份、真实投递凭证及本地配置不入 Git；上线备份和差异核对确认原用户资金、账单/付款记录、实例配置/到期日及任务不变。正式环境未部署。

## 客户自助认证迁移（2026-11-08）

启用本批 HTTP 代码前备份并运行 `php artisan migrate --force`（000007 增加 users.auth_version，默认 0，原无 av Token 保持兼容），然后重启本环境独立邮件 Worker 以加载密码重置场景；不重置真实用户密码、不改 SMTP 凭据、不扩大客户提醒开关。需要回滚代码时保留认证版本列及递增历史，不能回滚到忽略版本撤销的旧鉴权实现。M2 已完成备份/迁移/邮件 Worker 重启，正式环境仍另行部署。验收与 VPS 可用性限制见 [客户自助契约](M2_CUSTOMER_SELF_SERVICE.md)。

## 客户重装运行接入（M2 已执行，2026-11-09）

已有库先备份并用目标环境 PHP／私有配置运行 `php artisan migrate --force`，新增 000008 仅扩展 tasks.action=reinstall，保留 27 表与历史任务。按本规程核验原 Worker 的归属后优雅重启，不重置任务／令牌，不重发未知操作。HTTP 与 Worker 的 APP_KEY／DB 保持原值；已有 power、续费与资金门禁继续适用。

M2 已在无执行中任务时停启原 Worker，私有 MySQL 8 完整备份含 27 表／5 触发器后运行 000008；02:16:18（Asia/Shanghai）新 Worker 启动，最终 PID 997094、active/running、NRestarts=0。核实原 HTTP／Worker 的 APP_KEY、DB、Redis 和配置缓存一致；迁移前后仅 migrations 增加登记，原任务及令牌不变，分钟 timer 自然触发与健康接口正常。宿主旧 mysqldump 首次备份失败时没有执行迁移，并先恢复 Worker；成功备份后才继续。未重启共享 HTTP、邮件、Redis 或数据库服务，正式环境未部署。

用户另行指定实例 3／host:4253、Ubuntu 20.04 并允许清空系统盘后，从客户网页只提交一次重装，原 Worker 已受理任务 #9 并持续只读跟踪。公网 SSH 已证明 Ubuntu 20.04.4／root:22 可登录，DNS／HTTPS 正常；但真实上游仅返回通用“任务处理中”，缺少自动 execution_ended 所需的重装执行证据，**自动凭据同步及解除占用未通过**。任务保持 investigating，旧凭据读取 409；一小时窗口及后续人工异常规则保持，不伪造成功或重发。运行启用、执行结束与实际登录三类证据分开记录，详见 [本次重装验收](M2_INSTANCE_REINSTALL.md#m2-运行接入与真实-ubuntu-验收2026-11-09)。其他实例清盘仍需另行授权。

## M2 结构化重装识别加载（2026-11-09）

02:50（Asia/Shanghai）核对原业务Worker PID997094的cwd／PHP／命令及环境后，在任务9两次只读查询之间、租约为空且下次查询尚未到期的空闲窗口停止原进程并启动原单元。新PID1004092，active/running、NRestarts=0；旧进程已退出，APP_KEY、DB、Redis和配置缓存环境一致，健康接口200。没有强杀、改调查时钟／任务载荷或补造执行观察，未重启邮件、HTTP及共享服务。27表、迁移、实例／资金与原绑定保持；上游本地副本9768518尚未同步正式站，供应商字段仍待提供。此次只加载驱动68bde51，不是再次清盘或真实自动闭环验收。任务9继续原一小时窗口后按原核验入口处理，最终结果以重装契约追加证据为准。

任务9随后于03:21:14自然转原人工核验，03:22:23经原CLI及当前超级管理员fulfill完成异常结案，审计#7、令牌170→171；真实SSH复核、新凭据密文、本人凭据入口与统一占用检查通过，原资金／资源／账期／价格及历史任务保持。没有再发重装或补造观察，不能算正常自动闭环通过；供应商字段仍待提供。完整证据见 [任务9结案](M2_INSTANCE_REINSTALL.md#任务-9-独立人工异常结案2026-11-09)。


## 最小客服工单接入

已有库先按目标环境规程备份，再以该环境PHP及私有配置运行`php artisan migrate --force`，确认`2026_11_09_000009_ticket_requests`完成后才开放工单入口。迁移只为ticket_replies增加可空请求键／指纹和两个作者唯一索引，保留历史正文和27张表；新空库由正常安装器执行8条迁移。迁移完成前不要让工单写请求进入旧表，不修改APP_KEY或重建业务数据。

本批不修改Worker、调度或通知，无专属Worker重启要求；HTTP如启用不可变OPcache，沿用目标环境既有代码发布流程加载新控制器／服务，前端发布正常构建产物。按客户提单→后台定位关联→公开回复→追加／结单验收，并确认跨客户404、重复请求不重复、并发结单409。无工单邮件或上游写入。2026-11-09仅完成隔离与空库演练，M2／正式库本次未迁移，详见[工单记录](M2_SUPPORT_TICKETS.md#2026-11-09-隔离验收与运行前置)。


2026-11-09 13:21（Asia/Shanghai）M2已按上述工单规程完成完整备份及000009，27表／8条迁移／5触发器；全表内容摘要仅迁移登记改变。现有HTTP与业务Worker环境一致，客户／管理员只读和身份隔离通过，业务Worker PID1004092、邮件守护与分钟timer保持，无重启、客户写操作或真实邮件。完整证据见[工单M2接入](M2_SUPPORT_TICKETS.md#m2-运行接入2026-11-09)，正式环境仍单独部署。


## 工单邮件运行前置

基础工单000009接入后，本批不新增迁移。发布新代码后，按原规程核对并优雅重启本环境专属mail Worker以加载ticket_update；业务Outbox Worker无需改动。确认SMTP总渠道已配置／启用，再由当前super_admin执行`notifications:tickets`并通过私有stdin显式开启、设置指定管理员。命令回传的服务端activation／UTC时间是新订阅期；不扫描历史消息，每次配置会使旧排队工单邮件失效。原任务／临期／重置通知开关保持，不重配或输出SMTP凭据。

本批只完成开发和隔离验收，不默认重启M2邮件Worker、启用真实工单邮件或向真实客户发验收信；M2基础工单已可手测。消息来源及未知结果继续按[工单邮件契约](M2_TICKET_MAIL.md)检查，发送结果不能代替站内业务状态。


## 整数数量驱动M2同版加载（2026-11-10）

开发基线ea71705于00:16:32–00:16:33（Asia/Shanghai）接入既有M2。按本规程核对原进程和无在途／租约任务后，仅正常stop/start原业务Worker，PID1092819→1222885、active/running、NRestarts=0。原PHP开发HTTP无CLI OPcache、原Vite实际服务integer模块；HTTP／Worker密钥、DB、Redis及缓存环境一致。原资金／目录／实例冻结配置／任务及令牌摘要不变，27表／8迁移保持，无真实订单／付款／上游资源动作；未重启邮件或共享服务。分钟timer继续原频率自然触发。

重启后00:17／00:18的分钟调度自然触发并成功结束，账单过期处理和通知sweep均为0；不替代15分钟代扣或非零履约验收。商品389只读选得4核／2GB／Ubuntu22.04／双盘40GB／15Mbps／2 IP、月付首期／续费各¥78，尚待资金授权与真实规格证据；563仍零库存。备选、原始证据位置和验收边界见[记录](M2_QUANTITY_SALES_ACCEPTANCE.md)。这次只证明同版运行接入，不冒充新数量订单的实际Worker交付。
