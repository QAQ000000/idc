# M2 本站页面、云控制台直连

## 当前定案（2026-11-08，替代 6cde758 中继方案）

用户已明确选择：**隐藏上游财务域名，允许浏览器直连独立云控制台域名**。页面仍为 `/instances/:id/console`，本地打包 noVNC；画面、键盘和鼠标经浏览器与云控制台 WebSocket 直接传输，不经过简云服务器。财务域名/账号/API 密钥、主机密码和 host_token 不通过控制台 API 交给前端。云 WS 地址和本次 VNC 连接密码允许浏览器读取；Base64 仅是编码，不能作为保密手段。

旧魔方 `DcimCloud::vnc()` 生成财务页面 `/dcim/novnc?url=<Base64>&password=...&host_token=...`，页面解码连接地址。简云复用“本站页面 + 云控制台直连”的职责划分，通过认证 POST 返回最小连接参数，不将密码附在页面 URL、浏览器历史或 Router query；不复制 host_token 自动粘贴主机密码功能。

## 权限和连接流程

1. 本人主登录调用 `POST /api/v1/instances/:id/console`，实时检查启用客户、实例归属、active/未到期、Provider 启用及任务/资金门禁，拒绝管理员作用域和模拟登录。原资源按冻结 Provider/PID/远端 ID/账期读取，目录下架不改变原绑定。
2. 可选 `ConsoleDriverInterface` 只返回云 WebSocket 地址和 VNC 连接密码。魔方驱动验证账号范围内原资源和 VNC 按钮，解析同财务源 `/dcim/novnc` 的标准 WS 模式，不返回整页或完整上游响应。host_token 丢弃。
3. 必须命中运营配置的精确 `CONSOLE_TARGETS=cloud-host:port`，生产只允许 wss、公网地址、无 userinfo/fragment/control characters；拒绝与财务 API 同主机名的直连目标。浏览器自己解析 DNS/验证 TLS，服务端的公网预检不能承诺固定浏览器 IP；目标域名须属于受信云系统。隔离测试显式允许 loopback/ws。
4. 返回 `{websocket_url,password,binding,client_disconnect_at}`，响应 no-store。binding 只是原资源上下文 HMAC，用于页面比较，不是登录凭证。deadline 为本站主 Token 到期和 15 分钟的较早时间，是**页面主动断开时刻**。不再签发/保存 Redis 中继票据，Redis 只保留 5 秒签发限流。
5. 本地 noVNC 直接向云系统建立 WS，只传云连接凭据和 binary 子协议，绝不把简云主 Token 发给云系统。页面及资源仍来自本站，不使用上游 HTML/iframe/跳转。连接失败需用户显式重连，不自动重试。
6. 页面每 15 秒调用 `POST /api/v1/instances/:id/console/check`（无上游网络请求），同样校验主登录/归属/门禁并返回当前 binding；变化或校验失败时页面断开。退出登录、离开页面、主会话到期或页面连接满 15 分钟也断开；旧连接事件不能覆盖新连接状态。

**直连的约束**：本站服务器不再持有数据通道，不能强制撤销客户已获取的云连接凭据、终止绕过本站页面建立的连接，或保证一次性/60 秒有效。上游实际凭据有效期、重复使用与撤销能力由云系统控制，当前未证明可撤销。前述断开策略是正常本站页面行为，不能当作服务端安全边界。若将来要求强制即时撤销，须取得上游原生支持或重新审议中继，不能靠新增本站 Token 假装解决。

## 复杂度与兼容变更

- 删除 PHP WebSocket/RFB 桥、Workerman/其传递依赖、独立守护单元、Nginx/Vite WS 代理及票据消费测试。不保留两套连接模式，不增加业务表、Outbox 任务或资金动作。后端只剩资格/目标校验，前端使用 noVNC 原生 VNC 认证。
- `POST /console` 从中继票据响应改为上述直连参数，旧 `/console/ws/<ticket>` 不再服务；新增只读语义的 POST `/console/check`。M2 是当前唯一接入环境，打开的旧页面须刷新，旧中继连接随退役终止。无数据库迁移/历史数据重写。
- 同步影响：AGENTS、README、架构/数据库摘要、插件契约、API、前端、路线图、部署规程、相关 Service/Driver/页面和隔离检查。本页替代旧“所有 WS 同源、所有上游连接凭据只在服务端、服务端周期强制断开”的验收条件。
- 不把 API 密钥、主机密码或 raw response 写日志/前端存储；本次连接参数只保留页面内存。云 WS URL 内的上游 token 是直连必需连接凭据，浏览器网络面板可见，不声称可隐藏供应商的所有线索或远端画面品牌。

## 本轮验收

- 隔离全回归通过 560 项（原 516 项 + 44 项直连权限/目标/投影/历史不变量检查），独立 systemd 运行检查另通过 22 项；PHP-FPM 配置缓存前后认证检查保持通过。覆盖管理员/模拟登录/越权/封禁/到期/任务门禁拒绝、目标白名单/私网预检、财务主机名与 DNS 尾点伪装拒绝、响应最小化、限流、绑定变化、HTTP check 和业务数据不变。
- Vue typecheck/lint/build、PHP/Python 语法、27 表字典同步和 Git 空白检查通过。生产构建保留原主包体积提示。
- M2 原实例 3 直连成功，本站页面渲染 1024×768 Debian 12 登录画面。页面/资源 HTTP origin 仅本站；VNC WS 直接连独立云域名的 `/cloud_ws11`，子协议仅 binary，不携带本站主 Token，iframe=0。控制台响应仅四个规定字段，财务域名/host_token 未出现。
- 1440px 桌面与 390px 移动端已检查，移动端 documentWidth=390、画布缩放358px。真实 check 请求返回正常；浏览器注入 check=409 后主动断开，注入短页面 deadline 后显示“本次连接已到时”并断开，这两项未改变 M2 业务状态，不代表上游撤销凭据。
- 退役并禁用 M2 专属 `jianyun-m2-console.service`，18213 不再监听；仅原 Worker、schedule 和共享 Redis 继续运行。旧单元保留私有备份，部署配置移除中继专用变量。API 使用直连配置启动，正式环境未部署。
- M2 资金、账单、实例配置/账期及原任务均与切换前一致，无新购买、付款、电源操作或旧单裁决。

正式 HTTPS/CSP、上游凭据 TTL/撤销、长时高并发尚未验收。不以直连可用推断延迟已降低多少；延迟收益取决于用户、简云和云系统的网络路径。
