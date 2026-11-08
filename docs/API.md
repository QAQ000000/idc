# JianYun (简云) · RESTful API 契约与接口字典 (API Specification)

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
    "type": "slider",           // 'image' | 'click' | 'slider'
    "driver": "geetest",
    "site_key": "xxx-xxx-xxx",  // 第三方公钥或图片base64
    "challenge_id": "c-902184"
  }
}
```

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
- **响应**：商品卡片列表（包含名称、默认配置参考价格 prices、configurable、类型、库存状态）。

### 2. 获取商品配置项表单 (动态渲染)
- **请求**：`GET /api/v1/products/{id}/config`
- **说明**：读取本地 JSON 快照，返回 `{fields:[{id,type:"select",label,choices:[{value,label}],default}],defaults,schema_hash}`；不公开原始上游响应，不声称 SWR/性能已实现。当前仅独立枚举。

### 3. 下单前原子算价与预检 (Pre-flight Check)
- **请求**：`POST /api/v1/products/{id}/calculate`
- **载荷**：`{ "billing_cycle": "monthly", "config_options": { "276853": "1173030" } }（示意；实际必须提交全部必填选项）`
- **响应**：返回核验后的十进制字符串 total、recurring_price、config_options、configuration、billing_cycle 和 quote_token；确认令牌有效 5 分钟。当前不支持优惠券。

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
- **状态及记录**：`GET /api/v1/instances/{id}/runtime` 返回 state、checked_at（UTC）、actions、blocked、2 分钟 operation_token 及最近 10 条安全 tasks。普通实例查询不访问上游。
- **载荷**：`{ "action": "reboot", "operation_token": "<runtime确认>", "request_key": "<16–64位字母数字或下划线短横线>" }`，支持 reboot/start/stop。成功返回 task_id/status=pending/action，HTTP 不调用上游变更。
- **授权与门禁**：本人有效客户身份、实例归属、active/已交付/未到期、真实产品能力和任务/退款门禁；模拟登录写操作本批拒绝。on 开放 stop/reboot，off 开放 start；重复请求/旧确认/冲突 409，越权资源 404，错误 Token 作用域 401。
- **结果**：任务 success 仅证明命令被上游接受，状态查询与 OS 健康独立；丢响应或崩溃只读调查，无法证明结果则进入管理员待核验，不能盲目重启。管理员任务 action 筛选支持 power；super_admin 按原证据/令牌可 investigate/fulfill/close_unexecuted，无 retry/退款动作。
- **历史不变量**：不修改资金、账单、冻结配置、实例续费价/账期/交付状态；目录下架不影响存量电源操作。完整兼容及枚举迁移见 [电源契约](M2_INSTANCE_POWER.md)。

### 4. 本站 Web VNC（可选驱动）

- **签发**：`POST /api/v1/instances/{id}/console`，本人客户主 Token；空 JSON 请求体。响应仅 `{path:"/console/ws/<一次性随机票据>", expires_in:60, max_duration:900}`，`Cache-Control: no-store`。未启用返回 503，能力/状态/门禁不满足 409，频繁签发 429。
- **页面**：`/instances/{id}/console`，本地打包 noVNC；不会返回旧规划中的 `vnc_url` 或实现旧 GET `/vnc`。
- **连接**：本站 WS 路径，子协议头依次 `binary, jianyun.<原主Token>`，服务端只选择 `binary`；精确 Origin + 主登录 + 归属/状态 + 单次票据共同验证，拒绝返回 403。不将主 Token 放入 URL；票据不是登录凭证。连接最长 15 分钟/主会话到期前，周期核验封禁/归属/任务门禁；断线必须重新签发。
- 上游 URL、VNC 密码、host_token 留在服务端；PHP 中继替客户完成上游 RFB 认证并替换桌面标题。目标白名单、TLS/DNS 边界、反向代理与兼容见 [VNC 契约](M2_INSTANCE_VNC.md)。不增加任务、资金变动或 schema。


### 5. 一键重装系统
- **请求**：`POST /api/v1/instances/{id}/reinstall`
- **载荷**：`{ "os_id": "ubuntu_24_04", "new_password": "NewRootPass123!" }`

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

### 2. 【实例改价与补差神器】(Price Override & Balance Debit)
- **请求**：`POST /api/v1/admin/instances/{id}/override-price`
- **载荷**：
```json
{
  "new_recurring_price": 70.00,        // 修改下次续费循环价格
  "debit_balance_diff": true,          // 是否折算天数直接从用户余额扣除升级差价
  "diff_amount": 13.33,                // 折算差价
  "remark": "运维在母鸡上加了2核4G，同步改价"
}
```
- **效果**：同一事务完成改价、作废在途未付 `renew` 账单（`cycle_marker = NULL`）、生成已付差价账单（`invoices.type = 'upgrade'`, `status = 'paid'`，因运维已在母鸡手动完成变配，不投递 Outbox `tasks`）、扣除余额、写 `admin_audit_logs` 审计日志。

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

CDN 交付可没有 IP，不以缺 IP 判定失败。电源与本站 VNC 由独立能力接口提供，重装仍未实现。详情与凭据对非所有者均返回 404。

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
| GET /admin/tasks | 所有启用管理员可读；`page` 默认 1、最大 100000，`per_page` 默认 20、最大 100。可筛选 `status=attention/manual/pending/running/investigating/failed/success/all`、`action=create/renew` 及精确 task_id/invoice_id/instance_id。返回 `{items,total,page,per_page}`，按任务 ID 倒序。默认 attention 包括未按永久取消/退款/续费取消结案的 failed、investigating、租约为空/过期的 running；已结案记录仍可按 failed/all 查阅；manual 仅 failed+人工核验前缀。不存在匹配项返回空页。 |
| GET /admin/tasks/{id} | 所有启用管理员可读；返回 task、instance、invoice、user、receipts、allowed_actions、blocked_by_other_operation、audit。audit_page 默认 1，每页 20，最大页 100000。无任务 404。 |
| POST /admin/tasks/{id}/resolve | 仍是唯一已实现裁决入口；仅启用 super_admin，沿用 `action,evidence,expected_execution_token` 与既有确认要求。成功 `{resolved:true}`；无材料/错误参数 400、普通管理员 403、过期令牌/状态/实例业务门禁 409。客户 Token 一律 401。 |

客户与管理员 Token 的 sub_type 分离，管理员 Token 不能调用客户身份 API，客户 Token 不能调用后台。所有启用角色（super_admin/finance/support/operator）可查看本批任务上下文；只有 super_admin 可裁决，没有新增 RBAC 实体。令牌仍为原 2 小时格式，修改角色或禁用账号即时生效。

查询仅投影定位所需字段：账单收款状态/金额、客户编号与邮箱、实例账期/状态、商品与上游名称、操作/资源编号、异常码、分页人工审计。不会回传原始 payload、价格/配置快照、网关报文、连接地址、密文或密码；任意历史错误文本替换为 DETAILS_REDACTED，审计 context 仅投影 evidence、previous_execution_token、remote_instance_id、payment_record_id、amount。核验材料不得填写密钥。上游原始业务错误仍需查受控运维证据。

allowed_actions 是当前一致性快照下的候选操作，不能当作授权令牌：运行/调查/成功/永久取消无操作，普通管理员无操作，有其他 blocker 或退款冻结无操作。failed+人工核验、已付且归属吻合时，新购候选 fulfill/retry/cancel_compensate，续费候选 investigate/fulfill/retry；新购补偿另查唯一收款凭证与佣金等门禁。写服务仍以锁定读校验全部条件；页面打开后发生变化会拒绝，不自动使用新 token 重发。

`evidence` 为去除首尾空白后的 10–1000 UTF-8 字节。retry/cancel_compensate 必须提交 `confirmed_no_execution_or_pending_request=true` 并证明未履约、未扣上游款、无延迟请求。fulfill 必填真实 remote_instance_id，可选有效 ip_address；续费必须保留原资源。cancel_compensate 是管理员显式触发的既有**未交付新购取消并退到站内钱包**，不是渠道原路退款，续费不支持。详情不表示上游已扣款，人工退款登记仍未实现。

**契约变更记录**：为持续运营复用原 AdminAuthMiddleware、27 表和 TaskResolutionService，增加登录/只读投影/单 Vue 页面，将此前仅 CLI/受控 HTTP 可用的动作接入网页；未新增实体、迁移、驱动调用、自动退款或分散式裁决路径，原 resolve 载荷兼容。证据收紧为空白拒绝，畸形 IP 从运行错误收敛为 400。验证由 tests/admin.php、私有 PHP-FPM 与隔离浏览器提供，记录见路线图。

<a id="admin-products"></a>

## 商品管理、上下架与快照（2026-11-08）

复用上一批 admin 身份：所有启用管理员可读取本地商品和已配置上游列表；只有数据库当前启用 `super_admin` 可以编辑、调用上游预览或确认导入/刷新。后端中间件与服务事务均核验权限，客户 Token 无法访问。

| 路径（/api/v1） | 行为 |
|---|---|
| GET /admin/providers | 仅返回 id/name/driver/status/snapshot_supported，不返回 endpoint 或 credentials。用于选择既有上游，不提供新上游配置。 |
| GET /admin/products | page 默认 1、最大 100000；per_page 默认 20、最大 100。按 status=active/hidden/retired、provider_id 和 name 子串筛选，按 ID 倒序返回 items/total/page/per_page。名称中的 `%`、`_` 按普通字符匹配。 |
| GET /admin/products/{id} | 商品绑定、类型、销售状态、可售账期、默认参考首期价/新购续费参考价、加价规则、安全配置字段/默认选择、version 和分页审计。audit_page 默认 1，20 条/页，最大 100000；无商品 404。旧快照未记录续费参考价时明确缺失，不假定等于首期。 |
| PATCH /admin/products/{id} | `{name,status,markup_type,markup_value,expected_version,evidence}`；完整提交以上字段，其他写字段拒绝。status=active/hidden/retired，markup_type=none/fixed/percent，markup_value 必须非负十进制字符串。none 规范化为 0.00。只改目录、不跨写账单/实例/任务。 |
| POST /admin/products/preview | `{provider_id,remote_pid,name,type,markup_type,markup_value,billing_cycles,config_options?}`；从已配置且启用上游读取 Schema、只读报价，返回 fields/defaults/prices/recurring_prices/billing_cycles/preview_token/expires_at。支持驱动已声明的独立配置与固定商品，其他能力拒绝。 |
| POST /admin/products/import | 上述输入加 preview_token/evidence，再次读取上游比对预览；新商品默认 hidden，返回 product_id/prices。相同导入确认重放返回原 product_id，不新增商品/审计；同 Provider/远端 PID 的不同导入意图拒绝 409，需刷新既有商品。 |
| POST /admin/products/{id}/snapshot/preview | `{expected_version,billing_cycles?,config_options?}`；由原商品决定 Provider/PID/名称/类型/加价，不能通过请求重绑；省略配置使用上游默认值。 |
| POST /admin/products/{id}/snapshot | 同刷新预览输入加 preview_token/evidence；保持原销售状态和订单命名空间，更新该商品 JSON 快照。 |

编辑、导入及刷新 evidence 为去首尾空白后 10–1000 UTF-8 字节；不填密码或密钥。同事务追加 `product_update` / `product_import` / `product_snapshot_refresh` 管理审计，记录依据、可核对变更或快照摘要与参考价。审计写入失败整笔回滚，不以补写日志替代。读取仅投影已知安全字段，不返回原始 Schema、成本、凭据或完整上下文。

`version` 绑定商品销售字段/快照与该商品最新审计版本，不将销售中的库存变动当作编辑冲突。旧编辑或刷新遇到其他变更返回 409，不能自动覆盖或更换版本重交。预览 5 分钟 HMAC 绑定管理员、规范输入、Schema、所选配置、两类报价与原版本；提交再读上游变化返回 409，不保存半成品。仅导入支持在确认令牌有效期内按原令牌找回已提交结果；刷新重复或旧版本拒绝，结果不明先看列表与审计。

加价基于快照保存的 `reference_costs` 重新计算默认首期/续费参考价，不在原售价上重复加价。旧上游快照缺成本时，在事务外只读核价且校验原配置/Schema；失败拒绝调价，名称/上下架可独立保存。原未加价人工固定商品的本地价可作为初始基价，首次记录后再调价仍使用原基价；历史人工加价但没有可信基价的情况拒绝猜测。目录参考价不保证实时成交：现有 Catalog 实时预检、报价确认和冻结仍是新购价格依据。

active 可新购，hidden/retired 均拒绝新配置/新报价/新下单。已冻结未付账单仍按原期限与金额付款；已付 create、原远端调查、存量 renew 不读取商品销售状态作为停止条件，不因调价/刷新改变账单金额或实例 recurring_price/override_price。禁用整个 Provider 是独立运维动作，本批不提供。

**契约变更原因与兼容**：为了日常网页运营，将 ProductSnapshotService 拆为只读准备和同连接事务保存，原 CLI `products:snapshot` 路径/默认行为保持；后台通过专用授权服务复用同一实现。config_cache 新增 reference_costs/recurring_prices，可空兼容旧 JSON；27 表无 DDL 变动，无历史重写或迁移。新 API 不新增驱动、购买、自动退款或自动刷新调度。验收见路线图本批记录。
