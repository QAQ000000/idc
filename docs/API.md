# JianYun (简云) · RESTful API 契约与接口字典 (API Specification)

**客户本地预览**：新增公开POST /products/{id}/preview，输入同calculate，无上游请求。成功返回原安全Quote字段及pricing_mode=local_preview；否则仅{pricing_mode:"online_required"}，无金额／令牌。calculate追加pricing_mode=online，原在线语义不变。lp1确认仅在checkout在线复核一致后有效，原请求键查回优先。接口不公开原矩阵／成本／上游身份，见[契约](M2_LOCAL_PREVIEW.md)。

**快照计划刷新**：无新增HTTP写接口；管理员商品详情增加只读snapshot_refresh={result,last_attempt_at,last_success_at,valid_until,fresh,pricing_mode:"online"}，时间为可空UTC Unix秒，缺失观察为never／fresh=false。只有匹配当前快照且未过期才新鲜；权限沿原管理员读权限，不输出原始规则、成本、凭据或刷新hash。客户calculate／checkout仍实时核价、原确认及原键恢复保持，见[契约](M2_PRODUCT_REFRESH.md)。

**本地计价验证边界**：本批仅有魔方插件只读CLI对价入口，无新增HTTP API；客户calculate／checkout继续在线核价及原quote_token，诊断pricing_hash不可作为下单确认。规则不支持整组回退，在线失败不返回本地价，见[契约](M2_LOCAL_PRICING.md)。

**整数数量配置增量**：原商品configuration、calculate与管理员preview接口的fields新增type=integer（choices=[]），提供minimum／maximum／step／step_base=0／allow_endpoints／ranges（id／minimum／maximum）；defaults及config_options仍为数字字符串，字段ID作为键，值为数量而非子项ID。原下单／预览确认校验范围、步长和指纹，非法输入400、配置／报价漂移409；configuration摘要value为数量字符串。无新接口，见[契约](M2_INTEGER_QUANTITIES.md)。

**枚举依赖增量**：原商品config与后台preview的fields可带dependencies数组（field、operator=in/not_in、values、effect=allow/exclude、choices，ID均字符串），父字段先于子字段。原calculate／checkout拒绝非法组合，不自动替换配置；schema_hash及quote_token包含规则，改变须重新确认。无新增路由，其他字段与成功请求键恢复保持，见[契约](M2_ENUM_DEPENDENCIES.md)。

## 验证码策略后台（2026-11-09）

- `GET /api/v1/admin/captcha-settings`：仅当前启用super_admin，返回`scenes`三场景对象；每场景`enabled:boolean,driver,mode,configured:boolean,version:string`。
- `PATCH /api/v1/admin/captcha-settings/{scene}`：同权限，完整`{enabled:boolean,driver:"builtin_image",mode:"always",expected_version:string}`，响应`{saved:true}`；未知字段／场景／模式400，失效会话401、角色403、旧版本409。场景版本独立，保存与审计同事务，响应no-store。
- 客户`POST /auth/login`限制每可信IP 10次／60秒、`POST /auth/register`限制5次／60秒，包含无效参数／验证码失败，429拒绝且不执行业务；关闭验证码也限流，Redis不可用拒绝。管理员与找回原独立限流保持。

`login`同时影响客户和管理员。旧页面每次提交按当前策略校验；关闭页遇开启422并重新获取挑战，开启页遇关闭按当前关闭策略且继续限流。不承诺开关清空已发挑战。损坏配置／不可用驱动拒绝；丢响应先GET核对，不自动PATCH，完整边界见[契约](M2_CAPTCHA_SETTINGS.md)。

## 站点基础配置（2026-11-09）

- `GET /api/v1/site`：匿名、no-store，仅返回`site_name,logo_path,company_name,copyright_text,icp_number`五项字符串，绝不返回其他设置、密文、版本或审计。
- `GET /api/v1/admin/site-settings`：仅当前启用super_admin，五项资料＋`configured:boolean,version:string`。
- `PATCH /api/v1/admin/site-settings`：同权限，五项完整字符串＋`expected_version`；响应`{saved:true}`，400拒绝非法／未知字段，401／403拒绝失效身份／角色，409拒绝旧版本。站名80／Logo255／公司120／版权200／备案80个Unicode字符；UTF-8单行文本，去除首尾空格，允许空值。Logo只接受文档约束的本站静态路径。

配置与脱敏审计同事务，版本包含审计序号；丢响应只读核对，不自动重发。字段默认值、路径规则、兼容与验收见[站点契约](M2_SITE_SETTINGS.md)。验证码策略及其他配置保持独立。

本文档定义了 JianYun 系统客户端门户（Client Portal）与管理控制台（Admin Console）的全部 RESTful API 契约。

> 本字典同时包含规划接口；实际交付状态以 [ROADMAP.md](ROADMAP.md) 与 README 的当前实现说明为准。下述快照、变配确认及分散式人工裁决规划路径尚未实现，规划条目不代表运行验收。已交付的统一 resolve 与管理员查询接口及验证范围见文末。

**框架适配基准**：目标后端为 PHP 8.4 + Laravel 13，前端为单一 Vue 3/Element Plus REST API 应用；当前 MVP 已完成框架接入。框架接入保持本字典的路径、载荷、统一响应、金额字符串及身份/归属权限规则，不能因采用框架默认行为擅自改变认证或错误语义。兼容与回归要求见 [TECH_STACK.md](TECH_STACK.md)。

**旧站迁移边界**：本系统不定义迁移页面接口、旧库连接接口或旧站专用导入/回调接口。迁移由独立旧站插件导出目标 SQL 备份，经通用 MySQL 工具恢复；旧回调外置接收与受控重放遵循 [MIGRATION_RUNBOOK.md](MIGRATION_RUNBOOK.md)，只能经核准的通用支付契约结算，不能新增免验签、免鉴权的旧站入口。本系统自身的 schema 升级不属于旧站迁移接口。

**退款接口边界（2026-11-08）**：退款由管理员手动办理，本期不增加自动退款、自动补偿、网关退款调用/反查或新的退款 API；人工退款登记页面/接口也未在本轮实现。既有任务人工裁决接口保持原权限、令牌和证据门禁，详见 [人工退款策略](PAYMENTS_LEDGER.md#manual-refund-policy)。

---

## 一、 协议与统一规范

- **通信协议**：HTTPS + JSON
- **鉴权方式**：HTTP Header `Authorization: Bearer <token>`
- **统一响应格式**：
```json
{
  "code": 0,          // 0 表示成功；非 0 表示业务错误码
  "msg": "ok",        // 状态说明或错误提示
  "data": { ... }     // 业务数据载荷 (失败时通常为 null)
}
```

---

## 二、 客户端公开与认证接口 (Auth & Public)

### 1. 获取验证码配置
- **请求**：`GET /api/v1/captcha/config?scene={scene}`（`scene`: `login` | `register` | `password_reset`）
- **响应**：
```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "enabled": true,
    "type": "image",
    "driver": "builtin_image",
    "img_base64": "data:image/png;base64,...",
    "challenge_id": "48位十六进制挑战ID"
  }
}
```

关闭场景仅返回`{enabled:false}`。当前仅安装image驱动；挑战有效5分钟，验证尝试一次即销毁，绑定场景与可信IP。未配置默认开启，异常不跳过。

### 2. 用户注册
- **请求**：`POST /api/v1/auth/register`
- **载荷**：
```json
{
  "email": "user@example.com",
  "password": "UserPass123!",
  "affiliate_code": "TNMBGSKD", // 可选邀请码
  "captcha_payload": { ... }
}
```

### 3. 用户登录
- **请求**：`POST /api/v1/auth/login`
- **载荷**：`{ "email": "user@example.com", "password": "...", "captcha_payload": { ... } }`
- **响应**：
```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsIn...",
    "user": {
      "id": 1002,
      "email": "user@example.com",
      "balance": "150.00",
      "role": "client"
    }
  }
}
```

### 4. 客户账单与账户自助（已实现）

- `GET /api/v1/invoices?page=1&per_page=20&status=all`：仅本人客户主会话；分页 1–100000、每页 1–100。状态 all/unpaid/paid/cancelled/refund_pending/refunded；返回 items/total/page/per_page，按编号倒序，行含 invoice_id、instance_id/name、type、total、status、payment_method、created_at/paid_at/quote_expired_at、quote_expired；金额字符串，时间 UTC，不返回冻结配置或上游凭据。查询中的 user_id 不改变归属。原详情新增 created_at/quote_expired；支付仍使用原账单接口与最终门禁。
- `POST /api/v1/auth/password`：客户本人主登录，禁止模拟登录；`{current_password,new_password}`。旧密码正确且认证版本仍一致才更新，成功 `{changed:true}`，当前及其他旧登录同时失效。
- `POST /api/v1/auth/password/forgot`：公开，`{email,captcha_payload}`，独立 password_reset 验证码场景；IP 10 次/小时、邮箱冷却 60 秒且 5 次/小时。合法请求统一 `{accepted:true}`，不暴露账号存在/禁用或邮件配置状态，不承诺已送达。
- `POST /api/v1/auth/password/reset`：公开，`{token,new_password}`；独立用途 JWT 自申请 15 分钟有效，只允许一次成功，并发/重放/邮箱或认证版本改变拒绝。成功 `{changed:true}`，不自动登录。每次明确申请独立邮件，成功改密前同版本多条链接可并存；首次成功一起失效。无“最新申请立即废除旧链接”承诺。
- 新密码 12–72 UTF-8 字节、拒绝 NUL；接口敏感成功响应 no-store。邮件链接 `/reset-password#token=...`，前端取入内存后清 fragment，不存本地、不作为主登录。`users.auth_version` 在改密行锁事务递增；原无 av 客户 JWT 只兼容版本 0。VNC 后续资格检查同样比较版本；已发出的云凭据无法本站强撤销。

完整设计、迁移与验收边界见 [客户自助契约](M2_CUSTOMER_SELF_SERVICE.md)。

### 5. 软件与常用工具下载中心
- **列表接口**：`GET /api/v1/downloads?category={category}`
  - **响应**：返回资源列表（按 `order_num DESC, id DESC` 排序），包含标题、简介、文件大小、SHA256 校验和及权限要求。
- **资源下载/重定向**：`GET /api/v1/downloads/{id}/file`
  - **权限核验**：按 `access_level` 严格鉴权：
    - `public`: 允许免登录直接下载；
    - `login_required`: 需携带有效客户 JWT Token；
    - `active_instance`: 校验当前用户拥有至少一台 `active` 状态的云实例；
    - `product_bound`: 校验当前用户拥有指定 `bound_product_id` 商品的有效实例。
  - **原子计数**：执行 `UPDATE downloads SET downloads_count = downloads_count + 1 WHERE id = ?`；
  - **分发响应**：
    - `source_type = 'external'`: 返回 HTTP 302 重定向至外部网盘/直链；
    - `source_type = 'upload'`: 返回 Nginx 内部重定向头 `X-Accel-Redirect: /protected_downloads/...` 并设置 `Content-Disposition: attachment; filename="{file_name}"`，零 PHP 内存损耗高速下发。

---

## 三、 商品与购买流程 (Catalog & Order)

### 1. 获取在售商品列表
- **请求**：`GET /api/v1/products?type={cloud|dedicated|cdn}`
- **响应**：商品卡片列表（包含名称、默认配置参考价格 prices、configurable、类型、stock本地销售配额及upstream_stock上游观察）。`upstream_stock={state,quantity,checked_at}`：state为in_stock／sold_out／unlimited／unknown，可靠数量才非null，checked_at为UTC核对时间；缺失／过期／缓存故障为unknown且时间null。目录仅批读短期缓存，不调用上游。stock=-1为本地不限额，不能和上游未启用库存控制混为一谈。

### 2. 获取商品配置项表单 (动态渲染)
- **请求**：`GET /api/v1/products/{id}/config`
- **说明**：读取本地 JSON 快照，返回 `{fields:[{id,type:"select",label,choices:[{value,label}],default}],defaults,schema_hash}`；不公开原始上游响应，不声称 SWR/性能已实现。当前支持独立枚举、单父无环枚举联动和独立整数数量（类型15／18），完整字段见页首增量。

### 3. 下单前原子算价与预检 (Pre-flight Check)
- **请求**：`POST /api/v1/products/{id}/calculate`
- **载荷**：`{ "billing_cycle": "monthly", "config_options": { "276853": "1173030" } }（示意；实际必须提交全部必填选项）`
- **响应**：返回核验后的十进制字符串 total、recurring_price、config_options、configuration、billing_cycle、upstream_stock 和 quote_token；确认令牌有效 5 分钟。upstream_stock不进入签名，不是库存预留。HTTP409的code=1002表示本地配额用完、1003表示上游明确售罄、1004表示上游查询／协议失败且库存未知；后两类会更新短期观察，失败后可GET目录只读核对，不自动重试核价。当前不支持优惠券。兼容及页面行为见[本批契约](M2_CHECKOUT_RESPONSIVENESS.md)。

### 4. 提交订单并生成账单
- **请求**：`POST /api/v1/orders/checkout`
- **载荷**：
```json
{
  "product_id": 153,
  "billing_cycle": "monthly",
  "config_options": { "276853": "1173030" },
  "hostname": "server-hk-01",
  "password": "RootPassword!",
  "checkout_key": "user-generated-unique-key",
  "quote_token": "calculate 返回的令牌"
}
```
- **生命周期流转**：
  1. **下单事务（`checkout`）**：
     - 系统原子预检库存并锁定当前价格快照；
     - **即时预创建 `status='pending'` 的 `instances` 记录**，将用户填写的 `password` 经 AES-256-GCM 信封加密存入 `instances.encrypted_password`，`hostname` 存入 `instances.name`（彻底解决后续异步支付回调明文密码丢失问题）；
     - 插入 `invoices` 记录（关联 `instance_id = new_instance_id`, 初始 `status='unpaid'`）；
     - 若提交了有效优惠券，核销锁定卡券（`user_coupons.status = 'used'`）；
  2. **支付履约分流**：
     - **钱包余额全额支付**：若用户勾选使用余额且余额充足，在下单同一事务内完成扣款、标记 `invoices.status='paid'` 并直接下发 `tasks (action='create', instance_id=new_instance_id)`；
     - **三方网关异步收单**：返回未支付账单；待三方网关异步回调到达（`POST /api/v1/payments/notify/{gateway}`）时，CAS 标记 `invoices.status='paid'`，直接读取 `invoices.instance_id` 下发 `tasks (action='create')`；
  3. **交付完成激活**：后台 Worker 异步调用上游开通成功，带 Fencing Token 原子更新 `instances.status = 'active'` 并回填远端 ID 与 IP；
  4. **超时未付取消清理**：未支付账单超时自动取消（`unpaid -> cancelled`）时，定时清理任务同步将关联的 `status='pending'` 实例置为 `terminated`，并释放锁定的卡券资产（`user_coupons.status = 'unused'`）。
- **响应**：返回生成的账单 `{ "invoice_id": 81092, "instance_id": 10024, "total": 45.00, "status": "unpaid" }`。

---

## 四、 客户控制台实例管理 (Instances)

### 1. 我的实例列表
- **请求**：`GET /api/v1/instances?page=1&status=active`

### 2. 实例详情与实时监控
- **请求**：`GET /api/v1/instances/{id}`
- **说明**：返回本地交付、权益与配置快照；实时运行状态通过独立 runtime 接口读取，不把 active 当作正在运行。

<a id="instance-power"></a>

### 3. 电源运维操作（本批已实现）
- **请求**：`POST /api/v1/instances/{id}/power`
- **状态及记录**：`GET /api/v1/instances/{id}/runtime` 返回 state、checked_at（UTC）、actions、blocked、2 分钟 operation_token 及最近 10 条安全 tasks。普通实例查询不访问上游。同路径 `?view=tasks` 仅返回 blocked 与相同白名单 tasks，不调用上游、不签发令牌；缺省或 full 保持实时操作准备模式。`?view=observation`只返回120秒内上次观察，`?view=state`合并同资源在途查询后读取最新展示状态；返回state／checked_at（可空）／cached／blocked／tasks，无actions或operation_token，缓存命中也重核归属与本地门禁。其他值（含数组）400，所有模式均no-store。
- **载荷**：`{ "action": "reboot", "operation_token": "<runtime确认>", "request_key": "<16–64位字母数字或下划线短横线>" }`，支持 reboot/start/stop。成功返回 task_id/status=pending/action，HTTP 不调用上游变更。
- **授权与门禁**：本人有效客户身份、实例归属、active/已交付/未到期、真实产品能力和任务/退款门禁；模拟登录写操作本批拒绝。on 开放 stop/reboot，off 开放 start；重复请求/旧确认/冲突 409，越权资源 404，错误 Token 作用域 401。
- **结果**：任务 success 仅证明命令被上游接受，状态查询与 OS 健康独立；丢响应或崩溃只读调查，无法证明结果则进入管理员待核验，不能盲目重启。管理员任务 action 筛选支持 power；super_admin 按原证据/令牌可 investigate/fulfill/close_unexecuted，无 retry/退款动作。
- **历史不变量**：不修改资金、账单、冻结配置、实例续费价/账期/交付状态；目录下架不影响存量电源操作。完整兼容及枚举迁移见 [电源契约](M2_INSTANCE_POWER.md)。

### 4. 本站页面、云控制台直连

- **获取连接**：`POST /api/v1/instances/{id}/console`，本人主 Token、空 JSON。响应 `{websocket_url,password,binding,client_disconnect_at}`，no-store；503 未配置、409 能力/门禁/目标不满足、429 频繁签发。只投影独立云控制台的本次连接凭据，不返回财务域名、账号密钥、主机密码或 host_token。
- **页面检查**：`POST /api/v1/instances/{id}/console/check`，同样验证主登录/归属/权益/任务资金门禁，返回 `{binding}`，不读上游。页面每 15 秒调用，binding 改变或校验失败主动断开。
- **页面**：`/instances/{id}/console`，noVNC 本地打包；浏览器 WebSocket 直接连接云系统，只发送云连接凭据，不发送本站 Token。页面 URL 不附地址/密码，不使用供应商 HTML 或 iframe。
- 原 `/console` 的中继票据响应及 `/console/ws/<ticket>` 已退役，旧页面须刷新。deadline 是客户端断开策略，非上游凭据 TTL；本站不能强制撤销脱离页面使用的上游连接。直连信任边界、兼容与验收见 [VNC 契约](M2_INSTANCE_VNC.md)。

### 5. 实例自助重装系统（可选能力）
- **读取**：`GET /api/v1/instances/{id}/reinstall`，返回 state（available/unsupported/unavailable）、available、blocked、images（id/name）、password_mode=upstream_generated、data_disk=no_format_requested、120 秒 operation_token 及最近 10 条 tasks。任务只投影 id/status/image_name/result/accepted/updated_at；accepted 仅是上游受理，不是完成。
- **轻量跟踪**：同路径增加 `?view=tasks`，仅返回 blocked 与上述 tasks，继续校验本人、归属并 no-store；只读 MySQL，不调用驱动、不签发 operation_token、不返回镜像或凭据。缺省或 view=full 保持完整读取，其他 view（含数组）400。结束跟踪后须完整刷新取得新能力／确认，提交及 Worker 仍按原门禁重核。
- **展示镜像**：同路径 `?view=images` 返回5分钟安全缓存的state／images／password_mode／data_disk／checked_at／cached及当前blocked／tasks，不返回available或操作令牌；仅供选择。实际提交先读取full实时准备，规则变化重新确认，提交与Worker仍实时核验。无效或数组view返回400；全部no-store。细节见[展示契约](M2_INSTANCE_DISPLAY_CACHE.md)。
- **提交**：`POST /api/v1/instances/{id}/reinstall`，载荷 `{ "image_id": "1173023", "operation_token": "...", "request_key": "16-64位字母数字下划线或连字符", "confirmed_system_disk_erase": true }`。只允许本人会话；需先读能力，明确系统盘清空。客户自定义密码、端口和格式化数据盘字段均拒绝，不能静默忽略。响应为 task_id/status=pending，实例／能力变化、重复或冲突返回 409；权限不通过 401/403/404。
- **跟踪**：仍 GET 原路径。result 区分 pending/running/accepted/executing/awaiting_credentials/query_unavailable/investigating/needs_review/execution_ended/success/not_executed。accepted/executing 是正常自助状态；空闲预检、明确受理、观察结构化重装类型后恢复空闲、原资源凭据及再次空闲齐备（魔方不再识别中文描述）才自动同步并结束原任务，status=success/result=execution_ended 只表示执行结束，不证明系统成功。未知／漏观察／超时才异常核验，不以单个 Active 或镜像变化回填。未决 `/credentials` 返回 409；同步后返回加密保存的当前凭据及 verification=upstream_reported（未验证登录）或 verified，响应 no-store。管理员任务新增脱敏 reinstall_result，不返回 payload。原交易、资源与账期保留，详见 [重装契约](M2_INSTANCE_REINSTALL.md)。
- **管理员裁决**：现有 `/admin/tasks` action 筛选增加 reinstall；超级管理员经 `/admin/tasks/{id}/resolve` 只允许 investigate/fulfill/close_unexecuted。fulfill 除原资源／令牌／证据外须 `confirmed_reinstall_completed=true`、username、new_password（1–128 字节、实际验证的上游密码，不套用新购生成规则）；凭据不回显或进入审计。close_unexecuted 必须确认无执行／无延迟请求。禁止通用重试、退款或新购取消。

### 6. 实例自主续费（当前一个原账期，余额或现有渠道付款）
- **请求**：`POST /api/v1/instances/{id}/renew`
- **当前载荷**：`{ "base_due_date": "2026-12-07 15:33:12", "use_balance": true }`。base_due_date 为实例当前到期日（UTC 秒精度），同原周期重放返回原账单；仅续一个原账期，任意月份/周期切换待实现。省略 use_balance 只出未付账单，可用现有余额/易支付入口付款。

### 7. 弹性云变配升降级询价与变更
- **请求**：`POST /api/v1/instances/{id}/upgrade/calculate`
- **载荷**：`{ "new_config": { "bandwidth": 20 } }`
- **响应**：返回升级差价金额（降级时金额为 `0.00` 元）。

- **确认变更（规划）**：`POST /api/v1/instances/{id}/upgrade`，载荷 `{ "quote_token": "...", "new_config": { ... }, "idempotency_token": "...", "confirm_downgrade": false }`。后端验证归属、能力与报价快照，锁实例并按 TASKS_OUTBOX 未决任务互斥协议检查冲突；升级创建补差账单，支付后投递任务，降级需显式确认并同事务投递无账单的 `upgrade` 任务。HTTP 线程不执行 `resize()`。

### 8. 快照、备份与自主计划任务（规划）
- **能力与列表**：`GET /api/v1/instances/{id}/snapshots?type={snapshot|backup}`，返回驱动能力、本地台账 ID、状态及配额快照；以 `SnapshotDriverInterface` 为可选能力出口，不支持时禁止创建入口。
- **手动创建**：`POST /api/v1/instances/{id}/snapshots`，载荷 `{ "type": "snapshot", "idempotency_token": "..." }`。同事务预占 `creating` 台账（远端 ID 为 `NULL`）与可靠创建任务，返回 `202` 及 `snapshot_id/task_id`；令牌长度受限，最终任务键不超过 100 字符。
- **删除**：`DELETE /api/v1/instances/{id}/snapshots/{snapshot_id}`，其中 `snapshot_id` 为本地台账主键；同事务标记 `deleting` 并投递 `snapshot_delete:{snapshot_id}`，按资源类型执行 `snapshot_delete` / `backup_delete`，返回 `202`。
- **计划管理**：`GET/POST /api/v1/instances/{id}/snapshot-schedules`；`PATCH/DELETE /api/v1/instances/{id}/snapshot-schedules/{schedule_id}`。配置字段见 DATABASE §1.9；必须校验 `1 <= retention_count <= physical_max`、UTC 调度时刻和单槽位风险授权，暂停/删除规则不直接删除恢复点。
- **统一边界**：实例、台账、计划均需校验 `user_id` 和所属关系；所有写动作先锁实例并检查未裁决任务，再锁对应规则/台账，禁止以远端 ID 查找并操作其他实例资源。计划与手动操作共用同一配额、Outbox 及未知结果核验协议，详见 [BACKUP_SCHEDULER.md](BACKUP_SCHEDULER.md)。

---

## 五、 财务与结算接口 (Billing & Payments)

### 1. 账单列表与详情
- **请求**：`GET /api/v1/invoices/{id}`

### 2. 发起账单支付
- **请求**：`POST /api/v1/invoices/{id}/pay`
- **载荷**：`{ "gateway": "alipay_f2f" }`（支持 `alipay_f2f` | `epay` | `balance`）
- **响应**：
```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "type": "qrcode",                  // 'qrcode' (展示二维码扫码) | 'redirect' (跳收银台)
    "pay_url": "https://qr.alipay.com/bax0...",
    "qr_base64": "data:image/png;base64,..."
  }
}
```

### 3. 第三方支付异步回调通知
- **请求**：`POST /api/v1/payments/notify/{gateway}`（公开无需登录，由各支付驱动验签并 CAS 防重入更新）。

---

## 六、 管理员运维专用接口 (Admin Endpoints - 需 Admin 权限)

### 1. 【以该客户身份登录】(Login as Client - 严格风控约束)
- **请求**：`POST /api/v1/admin/users/{id}/impersonate`
- **安全约束与实现协议**：
  1. **Token 隔离与标记**：签发的 JWT Payload 必须携带 `is_impersonated: true` 以及经办管理员标识 `operator_admin_id: {admin_id}`。
  2. **超短有效期**：模拟登录 Token 有效期强制限制为 **15 分钟**，且不支持刷新（Refresh Token 禁用）。
  3. **高危敏感操作阻断**：当请求上下文携带 `is_impersonated: true` 时，系统全局中间件**物理阻断所有敏感安全写操作**：
     - 🛑 禁止修改该客户的登录密码、邮箱、手机号；
     - 🛑 禁止发起站外提现或绑定提现账户；
     - 🛑 禁止查看实名认证的未脱敏身份证号明文；
  4. **全链路审计追溯**：模拟登录期间客户产生的所有读写操作，审计日志必须记录双身份：`【操作者: 管理员 #1】【受检主体: 客户 #42】`。
  5. **退出模拟**：客户端顶部常驻红色警告条“您当前正在模拟客户身份排障 [退出模拟]”，点击直接销毁模拟 Token 并恢复原管理员会话。

### 1.1 全局数据越权与租户归属校验规则 (Tenant Ownership Guard)
> [!CAUTION]
> **严禁仅依赖前端路由守卫进行权限控制！**
> 后端每一个客户级业务接口（`/instances/{id}`, `/invoices/{id}`, `/tickets/{id}`, `/user-coupons/{id}`）必须由底层 ORM 作用域或中间件强制校验：
> `WHERE id = :resource_id AND user_id = :current_auth_user_id`
> 资源若存在但不属于当前 Token 用户，统一返回 `403 FORBIDDEN`，彻底杜绝越权（IDOR）漏洞。

### 2. 管理员实例续费改价（已实现，仅后续续费）

| 接口（前缀 `/api/v1`） | 当前语义 |
|---|---|
| GET /admin/instances/{id}/renewal-price | 当前启用 finance/super_admin 可读；返回 frozen_price、override_price、effective_price、billing_cycle、version、editable、blocked_reason、unpaid_invoices 和最近20条改价 audit。客服／运维403，客户Token401，响应no-store。 |
| PATCH /admin/instances/{id}/renewal-price | 仅当前启用 super_admin；严格载荷 `{override_price: "12.00" 或 null, expected_version: "64位版本", evidence: "10–1000字节依据"}`。金额必须十进制字符串，范围0.01–99999999.99；null显式恢复实例原冻结价。 |

同实例锁下重核版本、任务／资金门禁和管理员身份，覆盖价、全部归属一致的未付续费单CAS作废、释放cycle_marker和审计同事务提交。旧版本／同值／在途任务409；修改依据、前后价和作废账单ID保留。已付账单、已购账期、配置和原资源不变；后续由原服务出账。旧渠道迟到实收沿用凭证唯一键入钱包，不按旧价续费。详见 [改价契约](M2_INSTANCE_RENEWAL_PRICE.md)。

原 `POST /admin/instances/{id}/override-price` 没有实现；仅后续改价入口不处理补差。已人工完成变配的独立结清入口见下节，不调用上游或退款。

### 3. 任务队列监控、【一键重试】与【人工核验回填激活】
- **请求**：`GET /api/v1/admin/tasks?status=failed`
- **通用重发**：`POST /api/v1/admin/tasks/{id}/retry`（一键重新触发上游开通/变配，自动过滤拦截 `MANUAL_AUDIT_REQUIRED` 任务）。
- **强制重发（核实上游未创建）**：`POST /api/v1/admin/tasks/{id}/force-retry`（需 `super_admin` 权限并提交核验工单号，记入 `admin_audit_logs`）。
- **手动回填交付（核实上游已创建 / `ManualAdminDriver` 人工开通）**：`POST /api/v1/admin/tasks/{id}/manual-fulfill`
  - **权限与候选**：仅 `super_admin` 可裁决，必须提交核验工单号。仅接受 `create` 且处于 `failed + MANUAL_AUDIT_REQUIRED` 的任务；人工商品等待交付也进入此受保护状态，未裁决前持续阻断同实例其他动作。
  - **载荷**：`{ "remote_instance_id": "vm-1092", "ip_address": "192.0.2.10", "expected_execution_token": 9, "audit_ticket": "OPS-1024", "audit_remark": "人工核验已开通并绑定" }`
  - **效果**：独立管理员裁决命令先锁实例再锁任务、校验已付新购与最新令牌，CAS 原 `failed` 状态并递增令牌、清理 Worker 租约；唯有影响一行才能同事务写任务成功、实例激活起租及 `admin_audit_logs`。禁止伪造 Worker 租约绕过 `completeCreate()` 门禁，禁止重复调用上游。其他任务类型须采用对应动作的人工裁决协议，不能套用开通激活。

### 4. 资源下载管理与安全上传
- **文件上传**：`POST /api/v1/admin/downloads/upload`
  - **请求体**：`multipart/form-data`（字段: `file`）；
  - **安全策略**：严格限制扩展名白名单（`.zip`, `.tar.gz`, `.7z`, `.exe`, `.msi`, `.dmg`, `.apk`, `.iso`, `.pdf`），文件持久化至隔离存储目录 `storage/downloads/{YYYYMM}/{uuid}.{ext}`，返回 `{ "file_path": "...", "file_name": "...", "file_size": 1048576, "file_sha256": "..." }`；
- **新建/更新资源**：`POST /api/v1/admin/downloads`
  - **载荷**：
    ```json
    {
      "category": "专属驱动",
      "title": "Windows VirtIO 优化网卡驱动包",
      "description": "适用于 KVM 架构 Windows 实例，提升万兆网卡吞吐",
      "source_type": "upload",
      "file_path": "downloads/202611/c9f28d...virtio.iso",
      "file_name": "virtio-win-0.1.240.iso",
      "file_size": 612450304,
      "file_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "access_level": "product_bound",
      "bound_product_id": 153,
      "order_num": 10,
      "status": 1
    }
    ```

---

## 七、 标准业务错误码表

| Code | 标识 | 含义 |
| :---: | :--- | :--- |
| `0` | `SUCCESS` | 成功 |
| `400` | `BAD_REQUEST` | 参数无效或格式错误 |
| `401` | `UNAUTHORIZED` | 未登录或 Token 已过期 |
| `403` | `FORBIDDEN` | 权限不足或账号已被暂停封禁 |
| `404` | `NOT_FOUND` | 资源不存在 (商品/实例/账单) |
| `422` | `CAPTCHA_FAILED` | 验证码校验失败，请重试 |
| `1001` | `INSUFFICIENT_BALANCE` | 钱包余额不足 |
| `1002` | `OUT_OF_STOCK` | 上游资源已售罄 |
| `1003` | `UPSTREAM_TIMEOUT` | 上游接口响应超时，任务已安全进入后台重试队列 |
| `1004` | `REALNAME_REQUIRED` | 该商品所属节点要求必须实名认证后才可购买 |


## M0 实施兼容记录

现有 API 已由 Laravel 路由提供；现阶段仅 README 所列接口可用，本文其他接口仍为目标契约。checkout 新增可选 checkout_key（16–64 位 ASCII 字母/数字/_/-，区分大小写），按 (user_id, checkout_key) 唯一；重放同键同载荷返回原订单（包括已取消订单），同键不同业务参数返回 409。重试不重新定价、扣款、占库存或投递任务；新购买需新键。旧客户端省略键保持原行为，无法获得跨下单去重。摘要包含商品、账期、主机名、密码、配置与 use_balance，服务端以 HMAC 存储，不泄漏原密码。有限库存未付占用每用户最多 3 笔，达到上限返回 409；取消、付款或实际过期回收后释放。图片验证码及原 Token/AES-GCM 格式保留，注册 NUL 密码返回 400。`auth/me` 增加只读 role=client 供首方路由使用；后端仍按 Token 作用域与归属授权。未知路径保持 404；已知路径使用不支持的方法改为标准 HTTP 405（原生路由此前返回 404），两者均保持统一 JSON 响应，已加入兼容回归。

变更原因：框架适配时消除重复下单扣款、恶意未付占用与非法密码异常；影响 Auth/Billing、invoices 的两列与唯一键、前端提交和 schema 升级。验证包括并发重放、参数冲突、用户隔离、失败回滚和旧 MVP 原地升级；不新增支付网关或真实上游能力。

## M2 当前新增接口

| 方法/路径（均在 /api/v1 下） | 权限及行为 |
|---|---|
| POST /invoices/{id}/pay | 账单所有者；gateway=balance 原接口，gateway=epay 返回 checkout 表单描述，不代表已付款。 |
| GET/POST /payments/epay/notify | 无登录，驱动验签、PID 和成功终态校验；成功且入库后纯文本 success，失败 fail。 |
| GET /instances?page=1 | 当前用户自己的实例，每页 50 条；不返回密码或内部任务错误。 |
| GET /instances/{id} | 当前所有者；交付状态为 awaiting_payment/provisioning/investigating/needs_review/delivered/cancelled/compensated，返回原账单入口。 |
| GET /instances/{id}/credentials | 当前所有者且 active；返回远端 username/password，仅独立点击加载，所有响应 no-store。 |
| POST /admin/tasks/{id}/resolve | admin Token 且数据库中启用的 super_admin；动作/材料/旧 token 规则见 Outbox 契约。 |

CDN 交付可没有 IP，不以缺 IP 判定失败。电源、本站 VNC 和限定云模块的重装由独立可选能力接口提供；重装区分自动观察执行结束与实际回执／管理员确认成功。详情与凭据对非所有者均返回 404。

## 固定套餐续费当前接口补充

- `POST /instances/{id}/renew`：仅资源所有者、已交付且驱动支持续费，载荷见 §6；不能自动绕过 compliance/abuse/manual 暂停。返回与现有账单相同结构，续费无 30 分钟过期。
- `PATCH /instances/{id}/auto-renew`：所有者提交 `{ "enabled": false }`，必须为 JSON boolean。仅改变代扣策略；既有已付任务继续调查/履约，手动付款不受此开关限制。
- 实例列表/详情增加 suspend_type、auto_renew、renewal_eligible、renewal_invoice_id、renewal_status（none/awaiting_payment/provisioning/investigating/needs_review/renewed）。renewal_eligible 仅表示当前状态与驱动具备能力，实际付款仍须在事务内核验所有门禁。
- `/invoices/{id}/pay` 与 cancel 增加 renew：付款原子延长一次本地权益及投 Outbox，不直接恢复暂停；取消未付续费只取消该账单，不终止实例。

运行与异常资金规则见 [续费契约](M2_RENEWAL_CONTRACT.md)。


续费人工核验的 `POST /admin/tasks/{id}/resolve` 增加 `action=investigate`，载荷为 `{ "action":"investigate", "expected_execution_token":7, "evidence":"核验工单及继续查询说明" }`，仅启用 super_admin、已付 renew 的 failed+MANUAL_AUDIT_REQUIRED 可用。成功仍返回 `{resolved:true}`，保留原上游单号转 investigating 并递增 token/写审计；缺材料返回 400、权限不足 403、旧 token/状态或门禁冲突 409。没有续费退款接口；扣款成功但上游未成功由日志告警及待办列表通知管理员，详见 [续费契约](M2_RENEWAL_CONTRACT.md#续费异常通知与人工核验2026-11-08)。


### M2 配置销售 API 增量

上述选配示例省略其余字段，仅说明 ID 结构；必须以 `/config` 返回的全部字段为准。配置 ID/值规范化后计算 checkout_hash，键顺序及整数/字符串表达不产生新意图。选配订单必须携带确认令牌，下单前的首期价、续费价、Schema 或本地加价变化返回 409；原成功请求重放在预检与令牌检查前返回。新购账单响应增加 recurring_price、config_options 与可读 configuration，实例详情/列表增加 configuration；不公开上游成本或原始 Schema。30 分钟账单付款期限仍有效。旧固定商品可不传令牌，传入则校验。完整行为见 [配置销售契约](M2_CONFIG_SALES.md)。

后续真实验收修正：calculate 响应增加 `password_policy`，含整数 min_length/max_length（UTF-8 字节），可选 character_sets 与 generation_length。字符集名 upper/lower/digit/special 对应 ASCII 大写、小写、数字及 `~@#$&*(){}[]|`，须包含每个指定集合且只使用其并集；前端可按默认长度安全生成。魔方为 12–20 字节并跟随当前 Schema 的生成字符集；未声明策略的驱动保留 12–72 字节。checkout 在入库/扣款前按实时预检策略校验；不能绕过 UI 提交超限密码。报价确认绑定策略，变化需重新确认；成功同键重放仍先返回原单。历史已提交任务的调查不受新校验阻断。


<a id="admin-operations"></a>

## 运营后台第一批（2026-11-08）

范围为独立管理员登录、异常任务查询与已有人工裁决的网页入口，不新增自动退款或通用重试 API。

| 方法/路径（/api/v1） | 权限与输入/结果 |
|---|---|
| POST /admin/auth/login | `{username,password,captcha_payload}`；复用 login 验证码策略，按可信客户端 IP 由 Redis 限制每分钟 10 次密码校验；返回 `{token,admin:{id,username,email,role,status}}`。不存在账号与错误密码均 401，禁用账号 403；已有二次认证配置的账号因本批未实现二次认证登录而拒绝 403，绝不绕过。 |
| GET /admin/auth/me | 有效 admin Token；每次读取 admins 当前角色与状态，不信任前端角色。 |
| GET /admin/tasks | 所有启用管理员可读；`page` 默认 1、最大 100000，`per_page` 默认 20、最大 100。可筛选 `status=attention/manual/pending/running/investigating/failed/success/all`、`action=create/renew/power` 及精确 task_id/invoice_id/instance_id/user_id。返回 `{items,total,page,per_page}`，按任务 ID 倒序。默认 attention 包括未按永久取消/退款/续费取消结案的 failed、investigating、租约为空/过期的 running；已结案记录仍可按 failed/all 查阅；manual 仅 failed+人工核验前缀。不存在匹配项返回空页；非财务角色 total=null。 |
| GET /admin/tasks/{id} | 所有启用管理员可读；返回 task、instance、invoice、user、receipts、can_view_finance、allowed_actions、blocked_by_other_operation、audit。非财务角色 task/invoice.total、invoice.payment_method、instance.recurring_price/override_price 为 null，receipts=[]，audit.context 不含 amount/payment_record_id；原账单状态与付款时间保留。audit_page 默认 1，每页 20，最大页 100000。无任务 404。 |
| POST /admin/tasks/{id}/resolve | 仍是唯一已实现裁决入口；仅启用 super_admin，沿用 `action,evidence,expected_execution_token` 与既有确认要求。成功 `{resolved:true}`；无材料/错误参数 400、普通管理员 403、过期令牌/状态/实例业务门禁 409。客户 Token 一律 401。 |

客户与管理员 Token 的 sub_type 分离，管理员 Token 不能调用客户身份 API，客户 Token 不能调用后台。所有启用角色（super_admin/finance/support/operator）可查看本批任务上下文；只有 super_admin 可裁决，没有新增 RBAC 实体。令牌仍为原 2 小时格式，修改角色或禁用账号即时生效。前端导航鉴权网络失败／503 时保留令牌但清空角色并返回管理员登录，恢复后仍须重查身份；401／403 清除令牌，不据旧角色放行。

查询仅投影定位所需字段：账单收款状态/金额、客户编号与邮箱、实例账期/状态、商品与上游名称、操作/资源编号、异常码、分页人工审计。不会回传原始 payload、价格/配置快照、网关报文、连接地址、密文或密码；任意历史错误文本替换为 DETAILS_REDACTED，审计 context 仅投影 evidence、previous_execution_token、remote_instance_id、payment_record_id、amount。核验材料不得填写密钥。上游原始业务错误仍需查受控运维证据。

金额／收款和审计结构化财务字段现仅当前启用 finance/super_admin 可见；客服／运维仅查看非财务定位信息，原自由文本核验材料继续按原权限展示，不自动解析或重写。任务与账单关联核验客户和实例归属，详情收款同时限定该客户；查询响应 no-store。此为本次只读关联入口发现的权限缺口修正，原裁决请求、证据和执行令牌契约保持，见 [只读查询契约](M2_ADMIN_QUERIES.md)。

allowed_actions 是当前一致性快照下的候选操作，不能当作授权令牌：运行/调查/成功/永久取消无操作，普通管理员无操作，有其他 blocker 或退款冻结无操作。failed+人工核验、已付且归属吻合时，新购候选 fulfill/retry/cancel_compensate，续费候选 investigate/fulfill/retry；新购补偿另查唯一收款凭证与佣金等门禁。写服务仍以锁定读校验全部条件；页面打开后发生变化会拒绝，不自动使用新 token 重发。

`evidence` 为去除首尾空白后的 10–1000 UTF-8 字节。retry/cancel_compensate 必须提交 `confirmed_no_execution_or_pending_request=true` 并证明未履约、未扣上游款、无延迟请求。fulfill 必填真实 remote_instance_id，可选有效 ip_address；续费必须保留原资源。cancel_compensate 是管理员显式触发的既有**未交付新购取消并退到站内钱包**，不是渠道原路退款，续费不支持。详情不表示上游已扣款，人工退款登记仍未实现。

**契约变更记录**：为持续运营复用原 AdminAuthMiddleware、27 表和 TaskResolutionService，增加登录/只读投影/单 Vue 页面，将此前仅 CLI/受控 HTTP 可用的动作接入网页；未新增实体、迁移、驱动调用、自动退款或分散式裁决路径，原 resolve 载荷兼容。证据收紧为空白拒绝，畸形 IP 从运行错误收敛为 400。验证由 tests/admin.php、私有 PHP-FPM 与隔离浏览器提供，记录见路线图。

<a id="admin-products"></a>

## 商品管理、上下架与快照（2026-11-08）

复用上一批 admin 身份：所有启用管理员可读取本地商品和已配置上游列表；只有数据库当前启用 `super_admin` 可以编辑、调用上游预览或确认导入/刷新。后端中间件与服务事务均核验权限，客户 Token 无法访问。

| 路径（/api/v1） | 行为 |
|---|---|
| GET /admin/providers | 仅返回 id/name/driver/status/snapshot_supported，不返回 endpoint 或 credentials。保留选择既有上游的数组响应；连接管理使用独立接口，见 [上游接入](#admin-provider-connections)。 |
| GET /admin/products | page 默认 1、最大 100000；per_page 默认 20、最大 100。按 status=active/hidden/retired、provider_id 和 name 子串筛选，按 ID 倒序返回 items/total/page/per_page。名称中的 `%`、`_` 按普通字符匹配。 |
| GET /admin/products/{id} | 商品绑定、类型、销售状态、可售账期、默认参考首期价/新购续费参考价、加价规则、安全配置字段/默认选择、version 和分页审计。audit_page 默认 1，20 条/页，最大 100000；无商品 404。旧快照未记录续费参考价时明确缺失，不假定等于首期。 |
| PATCH /admin/products/{id} | `{name,status,markup_type,markup_value,expected_version,evidence}`；完整提交以上字段，其他写字段拒绝。status=active/hidden/retired，markup_type=none/fixed/percent，markup_value 必须非负十进制字符串。none 规范化为 0.00。只改目录、不跨写账单/实例/任务。 |
| POST /admin/products/preview | `{provider_id,remote_pid,name,type,markup_type,markup_value,billing_cycles,config_options?}`；从已配置且启用上游读取 Schema、只读报价，返回 fields/defaults/prices/recurring_prices/billing_cycles/preview_token/expires_at。支持驱动已声明的独立配置与固定商品，其他能力拒绝。 |
| POST /admin/products/import | 上述输入加 preview_token/evidence，再次读取上游比对预览；新商品默认 hidden，返回 product_id/prices。相同导入确认重放返回原 product_id，不新增商品/审计；同 Provider/远端 PID 的不同导入意图拒绝 409，需刷新既有商品。 |
| POST /admin/products/{id}/snapshot/preview | `{expected_version,billing_cycles?,config_options?}`；由原商品决定 Provider/PID/名称/类型/加价，不能通过请求重绑；省略配置使用上游默认值。 |
| POST /admin/products/{id}/snapshot | 同刷新预览输入加 preview_token/evidence；保持原销售状态和订单命名空间，整体保存该商品 JSON 快照。返回 product_id/prices/changed；同内容 changed=false，不重写商品，仍追加本次人工操作审计。 |

编辑、导入及刷新 evidence 为去首尾空白后 10–1000 UTF-8 字节；不填密码或密钥。同事务追加 `product_update` / `product_import` / `product_snapshot_refresh` 管理审计，记录依据、可核对变更或快照摘要与参考价。审计写入失败整笔回滚，不以补写日志替代。读取仅投影已知安全字段，不返回原始 Schema、成本、凭据或完整上下文。

`version` 绑定商品销售字段/快照与该商品最新审计版本，不将销售中的库存变动当作编辑冲突。旧编辑或刷新遇到其他变更返回 409，不能自动覆盖或更换版本重交。预览 5 分钟 HMAC 绑定管理员、规范输入、Schema、所选配置、两类报价与原版本；提交再读上游变化返回 409，不保存半成品。仅导入支持在确认令牌有效期内按原令牌找回已提交结果；刷新重复或旧版本拒绝，结果不明先看列表与审计。

加价基于快照保存的 `reference_costs` 重新计算默认首期/续费参考价，不在原售价上重复加价。旧上游快照缺成本时，在事务外只读核价且校验原配置/Schema；失败拒绝调价，名称/上下架可独立保存。原未加价人工固定商品的本地价可作为初始基价，首次记录后再调价仍使用原基价；历史人工加价但没有可信基价的情况拒绝猜测。目录参考价不保证实时成交：现有 Catalog 实时预检、报价确认和冻结仍是新购价格依据。

active 可新购，hidden/retired 均拒绝新配置/新报价/新下单。已冻结未付账单仍按原期限与金额付款；已付 create、原远端调查、存量 renew 不读取商品销售状态作为停止条件，不因调价/刷新改变账单金额或实例 recurring_price/override_price。禁用整个 Provider 是独立运维动作，本批不提供。

**契约变更原因与兼容**：为了日常网页运营，将 ProductSnapshotService 拆为只读准备和同连接事务保存，原 CLI `products:snapshot` 路径/默认行为保持；后台通过专用授权服务复用同一实现。config_cache 新增 reference_costs/recurring_prices，可空兼容旧 JSON；27 表无 DDL 变动，无历史重写或迁移。新 API 不新增驱动、购买、自动退款或自动刷新调度。验收见路线图本批记录。


## 单邮件通知接口边界（2026-11-08）

本批无新增 HTTP 通知配置/发送/裁决接口。邮件链接只指向现有 `/admin/tasks/:id` 或 `/instances/:id`，必须独立登录并遵循原权限，消息与 Message-ID 均不是登录或操作凭证。配置、查看投递状态及未知结果人工收口使用受保护部署环境的 notifications:configure/inspect/resolve，仍校验当前 super_admin、状态与证据并记审计。原任务 HTTP 操作不因收到邮件而获得授权，退款继续人工办理，完整契约见 [通知](NOTIFICATIONS.md)。


<a id="wallet-finance"></a>

## 充值、钱包与管理员资金查询（M2）

以下路径均在 `/api/v1` 下，沿用统一响应与金额字符串；客户与管理员 Token 不互通。

| 方法／路径 | 权限、输入与结果 |
|---|---|
| GET /wallet | 当前启用客户，返回本人 balance、currency=CNY。 |
| POST /wallet/recharges | 当前启用客户；amount 为 0.01–99999999.99 十进制字符串，request_key 为 16–48 位字母数字／下划线／短横线。返回原账单 DTO；同用户／键／规范化金额重放原单，换金额 409。无实例及报价到期日。 |
| GET /wallet/transactions | 本人 wallet 分户流水；page 默认 1、per_page 默认 20（最多 100），可按 invoice_id、type 筛选。返回 items/total/page/per_page；行含金额、前后余额、账单、payment_record_id、UTC 时间。不接受切换归属。 |
| POST /invoices/{id}/pay、/cancel | 充值复用原接口；pay 仅 gateway=epay，余额充值拒绝 400；取消 unpaid 幂等，已 paid 拒绝 409，取消后不再创建收银。回调仍走原易支付 notify；外部返回不结算。 |
| GET /admin/finance/{invoices,receipts,transactions} | 当前启用 finance/super_admin；分页同上，按 user_id、精确 email、instance_id、invoice_id、gateway_trade_no、channel、from/to 筛选；instance_id 核验实例、账单、收款／流水归属一致。时间格式 YYYY-MM-DD HH:mm:ss（UTC）。账单按创建时间，凭证按 paid_at（未付按 created_at），流水按 created_at。 |
| GET /admin/finance/invoices/{id} | 同上；返回 invoice、customer 和分页 receipts/transactions，页码 receipts_page/transactions_page 默认 1。customer 含当前钱包余额；不返回密钥、任意 JSON 或幂等键。 |

客户无权跨账户付款或读流水；support/operator 读取全局资金返回 403。无登录／错误作用域 401、格式错误 400、不存在详情 404。pending 凭证只表示付款尝试；同账单可有多笔独立实收，按网关筛选钱包只命中对应入账。无改账、退款或删除接口。完整领域边界及证据见 [充值与资金查询](M2_WALLET_FINANCE.md)。


## 第三批结账与账单展示增量

POST /products/{id}/calculate 增加 quote_expires_at（UTC，YYYY-MM-DD HH:mm:ss），与原 quote_token 的签名期限相同；前端不改签名或服务端预检规则，设备时钟只用于禁用过期提交，服务端仍最终裁决。Quote 同时继续返回 billing_cycle、configuration、首期和 recurring_price；刷新报价不重置购买请求键，输入变更才视为新意图。

GET /invoices 追加 type=all|new|renew|upgrade|recharge，与原 status、page/per_page 组合；格式错误 400。列表／详情只读增加 fulfillment_status、wallet_credit_total、late_credit_total（金额字符串），详情增加 instance_name；其他字段及权限保持兼容。fulfillment_status 在已付消费单上按本账单／归属匹配实例／对应动作的原任务投影：pending/running→provisioning、investigating→investigating、failed 或缺任务→needs_review、success→delivered|renewed|updated；其他资金状态或充值为 null。该字段只描述本单履约记录，不证明当前电源／可用性，也不作为付款授权或资金门禁。无新状态列、表或后台状态副本。

wallet_credit_total 只汇总本客户／本账单 wallet 分户的正向 recharge、late_payment_credit、refund_to_balance；late_credit_total 只汇总 late_payment_credit 或 late_credit:pay_ 键的 SC-L02 保护入款，不把新购取消的钱包补偿算作迟到款。均为历史累计入账，不是当前可用余额，也不代表退款或本单结清；旧流水不重写。详情继续用本人 /wallet/transactions 查询分页资金去向。钱包读取失败单独重试，不能把已读取的账单隐藏或把失败当作零到账。

本轮仅只读 API 增量和页面整理，付款 CAS、收款唯一身份、Outbox、租约／令牌和资金归属规则不变。无数据库迁移或 Worker 重启，真实 M2 资金链路继续保留独立人工验收。实施原因与页面／手机／异常证据见 [前端整理设计](FRONTEND_MODERNIZATION_DESIGN.md#第三批结账与账单页面)。

## 充值提前续费增量

不新增 HTTP 接口或前端自动扣款请求。原易支付充值回调成功仅确认充值账单／实收凭证／余额和流水已提交，提交后发布 Redis 客户提示，由现有 Worker 异步检查本人原自动续费单；不能把充值响应当作续费已付或上游已完成。客户继续查原续费账单、本单 fulfillment_status 和实例账期，资金去向沿用本人钱包流水及后台只读查询。Redis 故障不把已提交充值变成 fail，提示丢失靠原 15 分钟扫描；重复通知仍按原网关流水幂等，不重复入款／派 renew。兼容、运行及证据见 [充值唤醒契约](M2_RENEWAL_CONTRACT.md#充值到账后的异步唤醒)。

<a id="admin-queries"></a>

## 管理员客户与实例只读查询

沿用 `/api/v1`、admin 中间件和当前启用管理员身份；四种管理员角色可读。四个 GET 接口均返回 no-store，不调用驱动、不写资金或任务，详情不存在 404；匿名／客户 Token 401、停用管理员 403、参数错误 400。列表按 ID 倒序，page 默认 1（最大 100000）、per_page 默认 20（最大 100），返回 items/total/page/per_page。

| 路径 | 输入与返回 |
|---|---|
| /admin/customers | user_id、精确 email（最长 100）组合过滤；id/email/status/created_at，不含余额／消费／认证字段 |
| /admin/customers/{id} | customer 与分页 instances；page/per_page 仅控制关联实例，归属固定路径客户，不能用 query.user_id 切换 |
| /admin/instances | instance_id、user_id、provider_id、name 字面子串（最长 100）、ip_address 精确合法 IP（最长 45）、remote_instance_id 精确编号（最长 100）组合过滤 |
| /admin/instances/{id} | instance 与分页 invoices；账单仅本实例／客户一致的 invoice_id/type/status/created_at/paid_at，不含金额／渠道 |

instance 投影 id/user_id/user_email/product_id/product_name/provider_id/provider_name/name/status/remote_instance_id/ip_address/billing_cycle/start_date/due_date/suspend_type/auto_renew/created_at；不返回主机密码、上游地址／账号／密钥或配置快照。状态为本地服务状态。账单、凭证、流水继续使用原 finance 接口；任务链接使用原任务接口。无新写接口／表／迁移，权限收紧与兼容见 [本批契约](M2_ADMIN_QUERIES.md)。

<a id="admin-provider-connections"></a>

## 已有驱动的上游接入管理

所有接口前缀 `/api/v1`，返回 no-store。当前启用管理员可读列表／详情；super_admin 才能读取表单目录、新增、改名及验证。匿名／客户401、禁用或越权403、非法参数400、详情缺失404、版本／重放输入冲突409。没有替换账号、改地址、改驱动、切换启用状态或删除接口。

| 接口 | 输入与返回 |
|---|---|
| GET /admin/provider-connections | name 字面子串（最多50字节）、精确 driver、status=0/1；page 默认1／最大100000，per_page默认20／最大100；items/total/page/per_page，ID倒序 |
| GET /admin/provider-connections/{id} | 原连接安全字段、version、分页 audit；audit_page 默认1，每页20，最大100000 |
| GET /admin/provider-drivers | 已安装且实现可选连接配置契约的 items（id/fields/snapshot_supported）和目录 version；fields 为 key/label/type(url或secret)/required/max_length，不含值 |
| POST /admin/provider-connections | name、driver、按驱动的 endpoint/credentials、request_key（16–64位字母数字下划线短横线）、catalog_version、evidence（10–1000字节）；返回 provider_id。同管理员同键同输入重放原连接，换输入409；创建不联网 |
| PATCH /admin/provider-connections/{id} | 仅 name/expected_version/evidence；锁内版本检查、身份重核和同事务审计；返回 provider_id |
| POST /admin/provider-connections/{id}/verify | remote_pid 正整数、billing_cycle=monthly/annually、expected_version；读取默认配置与报价，返回 verified/remote_pid/billing_cycle/field_count/currency/first_price/recurring_price/verified_at；无上游写操作，不返回原始 Schema 或错误 |

列表／详情只投影 id/name/driver/status/created_at/updated_at/product_count/instance_count/driver_installed/configuration_complete/snapshot_supported/configuration_fields（key/label/configured）。不回显 endpoint、账号、凭据或密文。配置完整仅代表已填写，不代表可认证；失败统一脱敏且记本地验证审计。连接写入版本不因只读验证审计失效。详情审计只投影 evidence/before_name/after_name/remote_pid/billing_cycle/outcome/source，排除 HMAC 操作键／输入指纹与其他原始 JSON。新增实例查询 provider_id 筛选以关联原绑定；原商品导入保持 preview_token、权限、并发门禁及默认下架。兼容和网络信任边界见 [本批契约](M2_PROVIDER_CONNECTIONS.md)。

## 最小客服工单（2026-11-09）

客户 `/tickets` 的GET／POST、`/tickets/instances` 选择器、`/tickets/{id}`详情及其 `/replies`、`/close` 写入口已实现。后台 `/admin/tickets`、`/admin/tickets/{id}`及其 `/replies`、`/close` 向所有当前启用管理员开放，使用独立admin认证；不存在代登录、内部便签、转派或附件写入口。客户始终限本人工单／实例，私密类型不进入公开会话或计数；纯文本、no-store、同键异参409、关闭版本门禁与同事务审计不可绕过。完整请求字段、分页／筛选、返回投影与状态见 [工单契约](M2_SUPPORT_TICKETS.md#api与兼容)。已有库先迁移000009，不执行资金或资源操作。


## 工单邮件接口边界（2026-11-09）

原批次不增加 HTTP 接口；后续仅配置／订阅后台入口见 [邮件配置](M2_MAIL_SETTINGS.md)，仍不提供发送／重发接口。原工单提单／公开回复成功时，若双开关与当前订阅有效，同事务追加原通知表占位；重放仍返回原消息，结单不占位。邮件链接为`/tickets/:id?page=last`或`/admin/tickets/:id?page=last`，必须经原独立客户／管理员登录与归属校验，不包含免登Token。超级管理员本地`notifications:tickets`从stdin显式设置订阅起点及指定管理员；字段与兼容见[工单邮件](M2_TICKET_MAIL.md)。

## 邮件配置管理（2026-11-09）

超级管理员独占 `GET /admin/notification-settings`、`PATCH /admin/notification-settings/mail` 和 `PATCH /admin/notification-settings/tickets`。密钥不回显，保存带独立 expected_version；工单另需 confirm_new_period=true。字段、授权、冲突及兼容见 [配置契约](M2_MAIL_SETTINGS.md)。通知页分区／草稿／未知保存恢复见 [页面契约](NOTIFICATION_SETTINGS_PAGE.md)，本批HTTP协议与服务端保持不变。

## 到期服务状态核对（2026-11-09）

| 方法／路径（前缀 /api/v1） | 权限／行为 |
|---|---|
| POST /instances/:id/service-status | 客户本人；归属不符404，管理员Token不可代用 |
| POST /admin/instances/:id/service-status | 当前启用super_admin，网络前后重核权限 |

无请求业务参数；只读取上游并保存规范观察，不发停机／解挂。成功响应 no-store，data 为 applied、outcome、local_status、suspend_type、entitlement_state、service_observation。outcome 为 OBSERVED／NOT_DELIVERED／OPERATION_BLOCKED／LOCAL_STATE_CHANGED／UNSUPPORTED；查询失败可 applied=true 并保存 unknown／review_required，不得解读为上游正常。

service_observation 仅 state、suspension、checked_at、remote_due_date、review_required、code；从未核对为 not_checked／NOT_CHECKED，日期可空，UTC。权益为 valid／expired／not_started／not_applicable。原客户／管理员实例列表及详情补该投影和 service_status_supported，列表不调用上游、不暴露原 config_snapshot。后台 GET /admin/instances 支持 service_review=1，其他非空值400，与原筛选求交、分页不变；全部启用管理员可读，资金权限不变。

原账单可继续查找；新续费核对后出账，已知需核验状态拒绝划扣，迟到实收走原钱包分流。并发／数据保持／M2待验边界见[契约](M2_EXPIRY_RECOVERY.md)。

## 管理员已完成手工变配的补差

所有响应no-store，当前启用finance／super_admin可读，仅super_admin可预览及结清；身份取独立管理员会话，最终事务重查角色。客户不能访问本组接口。

- `GET /api/v1/admin/instances/{id}/manual-adjustment`：原已付周期依据、已覆盖价、未来有效价、editable／blocked_reason和最近20条完成审计（含原账单链接）。依据不足明确阻断，不按当前覆盖价猜算。
- `POST .../manual-adjustment/preview`：仅 `{new_price:"200.00", evidence:"实际变配完成依据"}`，返回原期、计价时刻／金额、将作废原单及5分钟preview_token。不能提交新规格或上游动作。
- `POST .../manual-adjustment/settle`：仅 `{preview_token:"…", request_key:"16–48位字母数字_-", confirmed:true}`，返回invoice_id／total／new_price／cancelled_invoice_ids／replayed。原请求重放返回原账单；结果未知只重试原请求或核对原记录，不能更换身份绕过预览。

400为字段／金额／明确确认错误；403为角色；404为实例缺失；409为依据不足、多期预付、未决任务／退款／服务门禁、余额不足、过期／变更预览或重放冲突。原账期／资源／冻结价不变；正额同事务结算、未来改价和审计，无上游任务；旧收银台迟到款按原凭证入钱包。普通pay／checkout不接受此upgrade。客户账单列表／详情及财务详情新增fulfillment_status=manual_settled，仅当原人工快照和完成审计匹配，普通paid无任务继续needs_review。完整规则见[契约](M2_MANUAL_ADJUSTMENT.md)。

## 管理员账号管理（2026-11-09）

前缀`/api/v1`，原admin中间件、no-store；客户／匿名401，停用403，撤销会话401，非super_admin管理账号403，字段错误400、缺失404、版本／最后超管／唯一账号冲突409。密码12–72字节且拒绝NUL，无返回／日志／审计明文或哈希。四固定角色super_admin／finance／support／operator，状态active／disabled。

| 方法与路径 | 权限、输入与结果 |
|---|---|
| GET /admin/accounts | super_admin；q（用户名／邮箱包含、最长100字节，转义通配符）、role/status（默认all）、page（1–100000）、per_page（1–100，默认20）；items/total/page/per_page |
| POST /admin/accounts | super_admin；username（1–50）、email（3–100）、role、status（可省略，默认active）、password；返回admin_id，唯一用户名／邮箱防重复，响应未知先查询 |
| GET /admin/accounts/{id} | super_admin；account安全白名单＋version、second_factor_configured、login_supported；最近20条安全audit，密码／2FA密文不返回 |
| PATCH /admin/accounts/{id} | super_admin；role、status、expected_version（64位HMAC）、evidence（trim后10–1000字节）；同事务变更／审计，返回admin_id/sessions_revoked/self_changed，无变化409 |
| POST /admin/accounts/{id}/password | super_admin且目标非本人；new_password、expected_version、evidence、confirmed=true；显式重置，返回admin_id/sessions_revoked |
| GET /admin/auth/security | 所有启用管理员，仅本人安全投影与目标version |
| POST /admin/auth/password | 所有启用管理员；current_password、new_password、expected_version；5次／分钟，校验旧密码并事务内重核原hash，返回sessions_revoked |

登录Token增加整数av；缺av只兼容尚无安全变更审计的版本0，非整数或不匹配401。账号写事务重核请求认证版本，等锁期间被调岗／撤销不能继续写。没有公开管理员注册、账号删除、用户名／邮箱编辑或自定义权限接口。最后超管保护及兼容见[账号契约](M2_ADMIN_ACCOUNTS.md)。


<a id="admin-payment-settings"></a>
## 易支付配置后台增量

仅当前启用super_admin可读写；其他管理员403、匿名／客户401，成功响应no-store。

| 请求 | 字段与结果 |
|---|---|
| GET /api/v1/admin/payment-settings/epay | enabled、endpoint、pid、type（alipay/wxpay/qqpay）、notify_url、return_url；configured、key_configured、identity_locked、64位version。不返回密钥或密文。 |
| PATCH /api/v1/admin/payment-settings/epay | 完整六个公开配置字段、expected_version、key_action=keep/replace；replace显式提供key，keep不得带key。返回saved=true，不回显配置或密钥。 |

版本过期或锁定身份变更409；非法输入400；审计失败整体回滚500。首次保存可停用但必须有有效商户密钥。URL禁嵌入账号／口令／query／fragment，正式环境须HTTPS，return_url为本站根地址；网关地址可含部署路径。出现任何epay尝试后endpoint/pid/key锁定，type和本站URL仍可版本化修改；不改旧收银字段。409／丢响应先GET，不自动重提密钥。

原POST /invoices/{id}/pay gateway=epay仅在新收银开启时准入；配置行锁下先提交尝试者有效，停用先提交则409且无新尝试。原GET/POST /payments/epay/notify不以enabled拒绝旧回调，仍验签、核销及独立迟到／额外入钱包，防重及Outbox边界保持。单渠道、人工退款；兼容、锁序与证据见[契约](M2_PAYMENT_SETTINGS.md)。


## 管理员客户账号管理

原客户详情与列表查询权限保持，新增接口仅当前启用super_admin，响应no-store。

| 方法 | 路径（前缀 /api/v1） | 用途 |
| --- | --- | --- |
| GET | /admin/customers/{id}/account | 安全账号资料、version、最近20条审计；可选request_key精确核对本人原操作 |
| PATCH | /admin/customers/{id}/status | status=active/suspended，启停并撤销旧认证 |
| POST | /admin/customers/{id}/password | new_password（12–72字节，无NUL），显式重置并撤销旧认证 |

两个写接口均要求expected_version（64位HMAC）、request_key（UUID v4）、evidence（10–1000字节）及confirmed=true；未知字段400、旧版本／空状态变更／已用操作编号409、非超管403、登录认证已撤销401。返回customer_id、request_key、sessions_revoked=true，不返回密码／哈希／auth_version／余额。账号变更与审计同事务。

GET返回account={id,email,status,version}、audit=[{id,admin_id,username,action,context,created_at}]、operation（按本超管原request_key命中的审计或null）；context仅evidence/request_key/before_status/after_status/sessions_revoked白名单。即使操作不在最近20条内也可独立查到。丢响应不自动重提；同版本且未查到审计仍为未知，不能仅凭当前状态判断成功。停用的资金、资源与续费边界见[完整契约](M2_CUSTOMER_ACCOUNTS.md)。
