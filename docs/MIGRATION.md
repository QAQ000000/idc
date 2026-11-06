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

### 1. `shd_clients` $\rightarrow$ `users` (客户导入，管理员来自 shd_user，详见 MIGRATION_RUNBOOK.md)
```sql
INSERT INTO jianyun.users (
    id, email, phone, password, balance, role, status, created_at
)
SELECT 
    c.id,
    IF(c.email = '' OR c.email IS NULL, CONCAT('user_', c.id, '@legacy.invalid'), c.email),
    NULLIF(c.phonenumber, ''),
    c.password,              -- 保留原始 32 位 MD5 密文
    c.credit,                -- 余额一分不差对齐
    'client',                -- 客户 role 统一强制为 client，管理员从 shd_user 独立提取
    IF(c.status = 1, 'active', 'suspended'),
    FROM_UNIXTIME(c.create_time)
FROM idc2.shd_clients c;
```

### 2. `shd_host` $\rightarrow$ `instances` (存活实例导入，严禁本地ID冒充远端ID)
```sql
-- 详细映射脚本与缺失映射挂起规则，以 docs/MIGRATION_RUNBOOK.md 第 3 节为唯一标准
-- 严禁任何 COALESCE(..., h.id) 本地 ID 降级操作！
```
WHERE h.domainstatus IN ('Active', 'Suspended', 'Pending');
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

### 4. `shd_downloads` $\rightarrow$ `config/downloads.json` (10 个文件/外链)
为了消灭无状态死数据表，常用工具下载直接固化迁移至前端/配置目录 `config/downloads.json` 静态维护，不再在 MySQL 中建表维护。

---

## 四、 零停机迁移上线实操步骤 (Runbook)

```text
[准备阶段] 
1. 新建并初始化 jianyun 数据库结构 (schema.sql)
2. 确认旧库 idc2 数据正常且已备份
      ↓
[全量静态导入] (耗时约 10 秒)
3. 运行专用迁移脚本: php scripts/migrate_legacy.php
4. 执行对账验证: 比对用户总数 (3,363)、钱包总余额、存活主机数 (118台)
      ↓
[功能并行灰度验证]
5. 随机抽取 3~5 个老账户进行免密测试登录与实例查看
6. 验证一台在跑的 Active 主机执行【重启】和【查看VNC】，确认上游透传无误
      ↓
[正式割接切换] (维护窗口 5 分钟)
7. 旧系统配置 Nginx 停写维护公告，确保旧库不再产生新订单
8. 运行增量迁移，同步最后几分钟内产生的新数据
9. 将生产域名解析 (DNS / Nginx 反向代理) 指向 JianYun 新系统
10. 割接完成，开放对外服务！
```
