# JianYun (简云) · RESTful API 契约与接口字典 (API Specification)

本文档定义了 JianYun 系统客户端门户（Client Portal）与管理控制台（Admin Console）的全部 RESTful API 契约。

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
- **响应**：商品卡片列表（包含名称、起售价格、类型、库存状态）。

### 2. 获取商品配置项表单 (动态渲染)
- **请求**：`GET /api/v1/products/{id}/config`
- **说明**：直接读取本地 SWR 缓存快照（5ms 秒开），包含 CPU/内存/带宽/机房/操作系统选项。

### 3. 下单前原子算价与预检 (Pre-flight Check)
- **请求**：`POST /api/v1/products/{id}/calculate`
- **载荷**：`{ "billing_cycle": "monthly", "config_options": { "cpu": 2, "memory": 4, "bandwidth": 10 } }`
- **响应**：返回核验后的精确总价、优惠减免与上游库存确认状态。

### 4. 提交订单并生成账单
- **请求**：`POST /api/v1/orders/checkout`
- **载荷**：
```json
{
  "product_id": 153,
  "billing_cycle": "monthly",
  "config_options": { "8": 2, "12": "centos7" },
  "hostname": "server-hk-01",
  "password": "RootPassword!",
  "coupon_code": "SALE2026"
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

### 6. 实例自主续费 (支持多周期与余额一键代扣)
- **请求**：`POST /api/v1/instances/{id}/renew`
- **载荷**：`{ "months": 1, "use_balance": true }`

### 7. 弹性云变配升降级询价与变更
- **请求**：`POST /api/v1/instances/{id}/upgrade/calculate`
- **载荷**：`{ "new_config": { "bandwidth": 20 } }`
- **响应**：返回升级差价金额（降级时金额为 `0.00` 元）。

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
- **效果**：同一事务完成改价、生成已付差价账单、扣除余额、写审计日志。

### 3. 任务队列监控与【一键重试】
- **请求**：`GET /api/v1/admin/tasks?status=failed`
- **重发**：`POST /api/v1/admin/tasks/{id}/retry`（一键重新触发上游开通/变配）。

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
