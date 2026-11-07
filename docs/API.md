# JianYun (简云) · RESTful API 契约与接口字典 (API Specification)

本文档定义了 JianYun 系统客户端门户（Client Portal）与管理控制台（Admin Console）的全部 RESTful API 契约。

> 本字典同时包含规划接口；实际交付状态以 [ROADMAP.md](ROADMAP.md) 与 README 的当前实现说明为准。下述快照、变配确认和人工裁决接口尚未实现，不得据此认定已通过运行验收。

**框架适配基准**：目标后端为 PHP 8.4 + Laravel 13，前端为单一 Vue 3/Element Plus REST API 应用；当前 MVP 已完成框架接入。框架接入保持本字典的路径、载荷、统一响应、金额字符串及身份/归属权限规则，不能因采用框架默认行为擅自改变认证或错误语义。兼容与回归要求见 [TECH_STACK.md](TECH_STACK.md)。

**旧站迁移边界**：本系统不定义迁移页面接口、旧库连接接口或旧站专用导入/回调接口。迁移由独立旧站插件导出目标 SQL 备份，经通用 MySQL 工具恢复；旧回调外置接收与受控重放遵循 [MIGRATION_RUNBOOK.md](MIGRATION_RUNBOOK.md)，只能经核准的通用支付契约结算，不能新增免验签、免鉴权的旧站入口。本系统自身的 schema 升级不属于旧站迁移接口。

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
- **说明**：返回本地快照与远端状态（运行中/已关机、公网IP、到期时间、配置规格）。

### 3. 电源运维操作
- **请求**：`POST /api/v1/instances/{id}/power`
- **载荷**：`{ "action": "reboot" }`（支持 `reboot` / `start` / `stop`）

### 4. 在线 Web VNC 访问
- **请求**：`GET /api/v1/instances/{id}/vnc`
- **响应**：`{ "vnc_url": "https://vnc-gateway.example.com/vnc?token=..." }`

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

CDN 交付可没有 IP，不以缺 IP 判定失败。不包含 M4 电源、重装等未实现接口。详情与凭据对非所有者均返回 404。

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
