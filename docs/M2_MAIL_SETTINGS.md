# 邮件配置与通知开关后台化（2026-11-09）

本批将现有 CLI 配置能力接入同一 Vue 的 `/admin/notifications`，减少日常操作成本。仅当前启用的超级管理员可读写；其他管理员、客户与匿名请求均不得读取 SMTP 元数据、收件人或修改配置。

## 页面与保存契约

- 邮件区：SMTP 主机、端口、SMTPS／STARTTLS、用户名、发件邮箱／名称，本站 origin、主题前缀、总开关、任务告警／到期提醒及任务告警管理员。密码找回沿用总开关，不新增场景或自动发送。
- 密钥只显示“已配置／未配置”；默认 `password_action=keep`，请求不得夹带 password；显式 replace 才接收非空新密码，提交完成或失败后清空输入。密码和 SMTP 原配置整体 AES-256-GCM 保存，响应不返回密码、密文或伪密码占位符。
- 工单区单独保存：开关和指定管理员。每次显式保存创建新的订阅期／UTC 起点，并使旧订阅未认领消息在原发送门禁中失效；不补发历史。普通邮件配置保存不触碰工单配置、版本或起点，不隐式保存另一分区的草稿。
- 保存工单需二次确认 `confirm_new_period=true`。页面说明已认领发送无法撤回，启用须先保存邮件总开关。两区独立版本；旧标签页、重复提交和 CLI 后续配置均使相应旧版本冲突，必须重新加载核对。
- 所有写入复用 NotificationSettingsService 原校验、加密、授权和同事务审计。版本由原设置及原配置审计签名生成，无新增版本表／字段。审计仅保留公开配置与密钥处理方式，不保存密码。
- 保存本身不发测试邮件、不扫描历史或重启进程。任务／到期场景的原扫描可为仍满足条件的当前来源占位，页面明确提示；工单仍仅由新消息事务触发。

## 接口

`GET /api/v1/admin/notification-settings` 返回 `mail`、`tickets` 和可选启用管理员（id／username／email）。mail 包含可编辑公开字段、非密码 SMTP 配置、password_configured 和 version；tickets 只包含 enabled／admin_ids／activated_at／version，不返回 activation。

`PATCH …/notification-settings/mail` 接受 expected_version、enabled、task_alerts、due_reminders、site_url、subject_prefix、admin_ids、config（不含 password）、password_action 及仅 replace 时的 password。

`PATCH …/notification-settings/tickets` 接受 expected_version、enabled、admin_ids、confirm_new_period=true。未知字段拒绝；成功仅返回 saved=true。GET 与成功写响应均 no-store，权限由服务从数据库重核，不依赖前端角色。

## 并发与发送边界

两区在原连接本地事务中锁原配置，版本核对后调用原 writer，配置与审计一同回滚；CLI 继续兼容并参与版本失效。发送者若在加载 SMTP 后遇到配置保存，认领事务用当前原配置签名重核；符合来源但快照过期的消息保留 pending，等待重新加载，而不使用旧密码、旧 origin 或旧开关认领。SMTP 仍在认领提交后，保存不能撤回已开始的发送，未知仍人工核验，不透明重试。

## 页面验收范围

沿用现有 Element Plus 表单、卡片、确认弹窗和工作区导航。手机单列，竖屏平板／桌面两列；覆盖加载失败重试、无配置、保留／替换密码、两区分别保存、取消确认、版本冲突和无权限。操作路径为管理后台 → 邮件与通知 → 保存邮件配置，或单独确认工单订阅。截图保留在本机测试产物目录，不入 Git。

## 兼容与验收记录

本次调整原“仅 CLI、不提供 HTTP 配置”冻结边界，原因是减少运营对命令行的依赖；原 CLI 与通知表、插件、未知核验规则保留，不新增通知场景、通道、测试发送或重发入口。同步 README、AGENTS、API、通知、数据库释义、架构、前端与路线图。27 表及 schema 保持，无数据库迁移。

已完成以下验证：

- `REDIS_SERVER=/www/server/redis/src/redis-server python3 scripts/test_integration.py --browser --runtime`：各业务测试套件合计 1442 项（含 15 单元、39 插件、工单邮件 65、配置后台新增 51）及独立运行 26 项通过；私有 PHP-FPM 在配置缓存前后均通过。原工单邮件崩溃夹具改为认领前加载当前设置，以符合新增版本门禁，不改变未知结果核验断言。
- 新增 51 项覆盖真实 HTTP 匿名／客户／非超级管理员拒绝、停用配置可读、密钥保留／替换且不回显、CLI 与网页版本冲突、四请求并发仅一次保存、审计失败回滚、角色即时降权、普通保存不改订阅、显式切换使旧消息失效及旧 Sender 配置不得认领；保存不写财务／资源／工单／通知事实或调用 SMTP。
- 独立 Chromium 页面共 24 项检查通过：真实登录重定向、保留／替换请求、普通保存保留另一分区草稿、工单取消／确认、失败后清密钥、重载恢复、客服隐藏菜单／表单与真实 API 403、匿名回到管理登录。UI 的空配置、409／503 用浏览器拦截构造；后端空配置和真实 409 另由上述隔离 HTTP 测试覆盖。
- 1440×1000、768×1024、390×844、320×780 无横向溢出，390×420 聚焦输入用于缩小视口模拟键盘；截图已检查。物理手机软键盘、触控仍未验证。截图在 `output/playwright/mail-settings/`（不入 Git），包含布局、确认、空配置、冲突及权限拒绝。
- 前端 typecheck／lint／build、PHP 语法、schema 字典同步和 diff 检查通过；构建保留现有主包体积提示，未扩大为本批优化。隔离资源验收后清理。

M2 本批未启用工单订阅、未重启邮件 Worker、未真实发信；原工单邮件运行接入与真实收件仍按 [工单邮件契约](M2_TICKET_MAIL.md) 单独验收。正式环境另行部署。
