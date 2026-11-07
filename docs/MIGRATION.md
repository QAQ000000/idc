# JianYun (简云) · 正式站旧数据平滑迁移方案 (Migration Overview)

> [!WARNING]
> **本文档为历史概要草案。生产割接与对账稽核的唯一权威实施契约请严格遵循 [docs/MIGRATION_RUNBOOK.md](MIGRATION_RUNBOOK.md)！**  
> 绝对严禁使用 `IF(c.id=1, 'admin')` 伪造管理员权限，绝对严禁使用 `COALESCE(..., h.id)` 冒充远端实例 ID。

---

## 一、 迁移资产盘点与范围界定

通过对导入的 `idc2` 生产库全面审计，迁移实行**“精准提纯、丢弃垃圾”**的原则：

| 资产类型 | 旧库来源表 | 原始数量 | 迁移策略与处理方法 |
| :--- | :--- | :---: | :--- |
| **客户账户** | `shd_clients` | **3,363 人** | **100% 全量迁移**。保留原始用户 ID，映射邮箱、手机号、钱包余额。 |
| **客户密码** | `shd_clients.password` | 3,363 条 | **旧 MD5 平滑升级机制**（首次登录成功后静默转为 Bcrypt，客户完全无感）。 |
| **云实例资产** | `shd_host` | 2,477 台历史 | **仅迁移存活/待处理的 154 台**：<br>• `Active` (正常在跑): **118 台**<br>• `Suspended` (欠费暂停): **22 台**<br>• `Pending` (待处理): **14 台**<br>*(其余 2,323 台已删除/已取消的历史死数据彻底抛弃)* |
| **上游魔方** | `shd_zjmf_finance_api` | 4 个 | 映射为 `providers` 表中的 `zjmf` 驱动提供方。 |
| **自营服务节点** | `shd_servers` | 6 台 | 映射为 `providers` 表中的 `easypanel`, `sub2api`, `mcsm` 驱动。 |
| **历史日志** | `shd_activity_log` 等 | 40+ 万行 (330MB) | **彻底抛弃不迁移**（旧库作为冷备份保留在磁盘，无需搬运垃圾）。 |

**存量驱动支持范围**：资产导入与自动运维验收分开进行。DCIM/魔方、Easypanel、宝塔 `skymnbt`、Sub2API 必须逐节点核准真实标识、续费语义与能力声明；驱动缺失或能力不支持时服务端禁用相应自动动作，并按 [MIGRATION_RUNBOOK.md](MIGRATION_RUNBOOK.md) §2.1 明确人工接管人、入口、时限和对账流程。未完成这些前置条件，不能宣称 118 台 Active 均可自动重启/续费。

**旧支付在途款**：11 笔旧未付订单与旧收银台可能在停写后继续回调。必须先实施 [MIGRATION_RUNBOOK.md](MIGRATION_RUNBOOK.md) §4.0 的持久接收、旧商户验签、受控订单映射、幂等重放和逐笔对账；不能将旧回调 `302` 到新接口，不能把旧账单 ID 直接用作新账单 ID。原始报文加密保存于私有目录，未知映射持续隔离核办，不占用实例 Outbox 或伪造账单。

---

## 二、 核心技术难点解决：旧密码平滑升级 (MD5 $\rightarrow$ Bcrypt)

旧魔方数据库中使用的是 32 位 MD5 哈希，如果强制用户重置密码，会导致大量客户流失。新系统采用**“双重鉴权 + 登录平滑热升级”**架构：

```mermaid
flowchart TD
    UserLogin[用户输入账号密码登录] --> CheckHashType{数据库中密码长度是否为 32 位?<br/>(旧魔方 MD5)}
    
    CheckHashType -- "是 (旧 MD5 格式)" --> VerifyMD5{md5(输入密码) === 数据库值 ?}
    VerifyMD5 -- "匹配成功" --> UpgradeBcrypt[⚡ 触发热升级:<br/>1. 用 Bcrypt 重新计算密码哈希<br/>2. 异步回写覆盖 users.password<br/>3. 签发登录 Token]
    VerifyMD5 -- "密码错误" --> Reject1[拒绝登录: 账号或密码错误]

    CheckHashType -- "否 (现代 Bcrypt 格式)" --> VerifyBcrypt{password_verify 校验}
    VerifyBcrypt -- "校验成功" --> GenToken[签发登录 Token]
    VerifyBcrypt -- "校验失败" --> Reject2[拒绝登录: 账号或密码错误]

    UpgradeBcrypt --> Success[成功进入新控制台]
    GenToken --> Success
```

**客户体验**：老客户直接输入原有密码即可登录，系统在他点击【登录】的这一毫秒内，自动完成了其账户安全等级由 MD5 升级到高强度 Bcrypt，客户全程零感知。

---

## 三、 数据映射规则表

### 1. `shd_clients` $\rightarrow$ `users` (客户导入，管理员来自 shd_user 且导入 admins 表，详见 MIGRATION_RUNBOOK.md)
```sql
INSERT INTO jianyun.users (
    id, email, phone, password, balance, status, created_at
)
SELECT 
    c.id,
    IF(c.email = '' OR c.email IS NULL, CONCAT('user_', c.id, '@legacy.invalid'), c.email),
    NULLIF(c.phonenumber, ''),
    c.password,              -- 保留原始 32 位 MD5 密文
    c.credit,                -- 余额一分不差对齐
    IF(c.status = 1, 'active', 'suspended'),
    FROM_UNIXTIME(c.create_time)
FROM idc2.shd_clients c;
```

### 2. `shd_host` $\rightarrow$ `instances` (存活实例导入，严禁本地ID冒充远端ID)
```sql
-- 详细映射脚本与缺失映射挂起规则，以 docs/MIGRATION_RUNBOOK.md 第 3 节为唯一标准
-- 严禁任何 COALESCE(..., h.id) 本地 ID 降级操作！
-- 过滤范围: WHERE h.domainstatus IN ('Active', 'Suspended', 'Pending');
```

### 3. `shd_news` & `shd_news_menu` $\rightarrow$ `articles` (118 篇公告)
```sql
INSERT INTO jianyun.articles (
    id, type, category, title, content, views, status, created_at, updated_at
)
SELECT 
    m.id,
    'news',
    COALESCE(t.name, '默认分类'),
    m.title,
    n.content,               -- HTML 富文本内容原样导入
    m.read,
    IF(m.hidden = '1', 'draft', 'published'),
    FROM_UNIXTIME(m.create_time),
    FROM_UNIXTIME(m.update_time)
FROM idc2.shd_news_menu m
LEFT JOIN idc2.shd_news n ON m.id = n.relid
LEFT JOIN idc2.shd_news_type t ON m.parent_id = t.id;
```

### 4. `shd_downloads` $\rightarrow$ `downloads` (10 个文件/外链导入)
支持后台动态上传维护与外链安全分发，将旧库 `shd_downloads` 真实外部网盘地址（剔除旧站 `sakuraidc.cc` 动态跳转）规范迁入 `downloads` 表：
```sql
INSERT INTO jianyun.downloads (
    id, category, title, description, source_type, file_path, access_level, downloads_count, status, created_at
)
SELECT 
    d.id,
    CASE 
        WHEN d.title LIKE '%JuiceSSH%' OR d.title LIKE '%ConnectBot%' OR d.title LIKE '%远程桌面%' THEN '远程连接'
        ELSE '网站源码'
    END,
    TRIM(d.title),
    TRIM(d.description),
    'external',
    TRIM(d.location),
    IF(d.clientsonly = 1, 'login_required', 'public'),
    d.downloads,
    IF(d.hidden = 1, 0, 1),
    IF(d.create_time > 0, FROM_UNIXTIME(d.create_time), NOW())
FROM idc2.shd_downloads d;
```

---

## 四、 迁移实施顺序与生产门禁

以下仅为导航；生产割接、回调接收所有权、最终余额快照和回滚条件以 [MIGRATION_RUNBOOK.md](MIGRATION_RUNBOOK.md) §4 为唯一权威。维护窗口必须保留旧回调持久接收，不能把“停写旧站”解释为关闭全部支付接收入口。

```text
[准备阶段] 
1. 新建并初始化 jianyun 数据库结构 (schema.sql)
2. 确认旧库 idc2 数据正常且已备份
   部署旧支付持久接收与重放桥接，验收加密存储、映射、幂等和逐笔对账
      ↓
[全量静态导入] (耗时约 10 秒)
3. 运行专用迁移脚本: php scripts/migrate_legacy.php
4. 执行对账验证: 比对用户总数 (3,363)、钱包总余额、存活主机数 (118台)
      ↓
[功能并行灰度验证]
5. 随机抽取 3~5 个老账户进行免密测试登录与实例查看
6. 验证一台在跑的 Active 主机执行【重启】和【查看VNC】，确认上游透传无误
   按每类存量 Provider 验收实际支持能力；不支持项服务端禁用并确认人工接管
      ↓
[正式割接切换] (维护窗口 5 分钟)
7. 停写旧业务及旧结算，保留原商户回调 URL 的持久接收，明确唯一结算归属
8. 冻结最终余额和旧新订单映射，运行增量迁移，对接收凭证幂等重放并逐笔对账
9. 将生产域名解析 (DNS / Nginx 反向代理) 指向 JianYun 新系统
10. 回调桥接/对账、存量能力或人工接管、可靠Worker及应急门禁全部通过后，开放支持范围内的生产服务
```

本流程和新增协议仍需实现迁移/桥接脚本并通过演练；不得因文档已补齐就将路线图任务 5.4 标记为完成。
