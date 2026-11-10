# 代码规范与 Laravel 复用整理

## 本批决定（2026-11-10）

目标是让熟悉 Laravel 的开发者能从标准入口理解项目，同时保留既有 API、资金、权限与未知执行结果保证。采用“工具接入 → 独立纯格式提交 → 一个缓存试点”的顺序；不批量改 ORM、认证、队列或插件装配，不新增表或迁移。

接手时从 `routes/api.php` 进入 `app/Http/Controllers`，再跟到 `app/Services` 的领域操作；驱动契约在 `app/Plugins`，具体实现仅在 `plugins`。命令和调度入口是 `routes/console.php`，配置是 `config/`，环境与隔离启动方式见 [README](../README.md#当前实现与本地启动)。`app/Core` 当前主要是兼容入口和少量领域工具，不是另一套应用框架。

## 格式检查

开发依赖锁定 Pint 1.32.1，配置为根目录 `pint.json`。`composer format` 整理 PHP，`composer lint` 只检查；隔离集成入口也在创建基础设施前运行同一检查。生产使用 `composer install --no-dev`，不需要 Pint。

采用 Laravel 预设，但关闭改写导入、语法标记和字面量的规则，首批只统一缩进、换行、空格及注释排版。197 个受版本管理的 PHP 文件逐文件经过 `token_get_all(..., TOKEN_PARSE)` 对照，排除空白与注释后标记完全一致，196 个文件实际改变。工具接入和纯格式提交独立，后续业务差异不混入格式基线。规则升级须单独审阅，不随业务改动顺手启用自动重构。

## 基础设施盘点

下表是当前代码的职责划分，不把可替代方案视为本期必须开发的缺口。

| 入口／证据 | 当前实现及保留理由 | Laravel 替代与成本 | 本批处理 |
| --- | --- | --- | --- |
| `bootstrap/app.php`、`routes/api.php`、`routes/console.php`、`config/` | 路由、中间件、容器、配置、日志、异常渲染、Artisan 与调度已由框架承接，没有第二套路由器 | 无须再建立包装框架；业务命令继续使用 Artisan | 保留标准入口 |
| `app/Core/Database.php` | 通过 `DB::connection()` 获取原 PDO 和框架事务，是兼容层而非自建连接池或事务管理器；领域 SQL 表达行锁、CAS 和同事务账本／Outbox | Query Builder 可逐查询采用；整批 Eloquent 重写增加锁顺序、金额类型及连接混用的回归成本，当前不必需 | 保留薄适配及显式 SQL |
| `app/Core/RedisStore.php` | 通过 Laravel Redis 连接调用 phpredis `rawCommand`，不是自写 Redis 协议；仍承载 Lua、集合和一次性消费 | 普通缓存改用 Cache；原子操作继续使用 Redis，删除适配须先迁完其调用者 | 本批仅迁库存缓存调用者 |
| `app/Services/StockObservationService.php` | 展示性观察可丢失，无任务／资金权威性；JSON 信封只保留状态、数量、核对时间 | `Cache::store('redis')->put/many` 承接 TTL、前缀和批读；安全解码及 5 分钟观察有效性仍是领域语义 | 已收敛，见下文 |
| `app/Core/ReadObservationCache.php` | 实例观察含在途合并、代际检查、所有者匹配后原子发布及比较删除；保护慢请求旧所有者不覆盖新观察 | 普通 `Cache::remember` 不等价；先检查锁所有权再单独 put 仍有竞态。可另批评估框架锁与原子发布组合，不先造通用缓存抽象 | 保留；删除会恢复重复查询及旧响应覆盖风险 |
| `plugins/providers/zjmf/TokenCache.php` | 登录 Token 按连接／凭据作用域隔离并加密，受 exp、提前失效及 300 秒上限约束；失效删除比较原值 | Cache 只能替代存取，不能替代驱动政策和比较删除；核心不能接管魔方协议 | 留在插件，暂不迁移 |
| `plugins/captcha/builtin_image/`、`app/Http/Middleware/ThrottleCustomerAuth.php`、`app/Services/PasswordService.php` | 验证码一次性消费、客户认证固定窗口原子计数、找回冷却键保护公网信任边界；管理员登录／找回的通用限流已用 RateLimiter | `RateLimiter` 可用于普通限流；替换原子消费或计数时必须证明并发窗口与故障拒绝语义。直接删除会允许重用挑战或绕过限流 | 保留专项原子操作，不把验证码当普通缓存 |
| `app/Services/RenewalWakeupService.php` | 提交后 SADD／SPOP 是可丢失提示，MySQL 原账单与 15 分钟扫描裁决实际代扣 | 事件／队列可以通知但不能替代资金事实；换组件不会自动提高财务正确性 | 保留，不引入第二队列 |
| `app/Core/Request.php`、`bootstrap/app.php` | 读取 Laravel Request，同时保留仅 JSON 对象、64KiB／深度32、按字节长度和严格 ID；默认不 trim／改写密码与金额字符串 | 新接口可评估 FormRequest；旧接口直接替换会改变载荷、422／400错误及空值规则，需专项协议回归 | 保留兼容校验，不扩展第二套验证器 |
| `app/Core/Security.php` | 使用原生 bcrypt、OpenSSL AES-256-GCM、HMAC，保留原密文信封、Token 格式、旧 MD5 校验兼容及可信代理链规则 | Hash、Crypt、Guard／Sanctum、TrustProxies 可分项评估；直接替换会涉及存量解密、会话及代理信任边界，不能靠包名保证兼容 | 本批不动；不是自写密码学算法 |
| `app/Middlewares/`、`app/Http/Middleware/ApiContext.php` | 客户／管理员既有认证上下文由框架中间件包装，每请求前后 reset；Worker 也逐任务隔离 | 后续可迁到 Guard／请求属性／scoped 服务；须覆盖 FPM、Worker、撤销和越权。删除 reset 会留下上下文串用风险 | 保留，另批处理身份上下文 |
| `app/Core/ApiException.php`、`app/Support/ApiResponse.php` | 领域异常与 `{code,msg,data}` 协议的薄适配，实际响应／渲染使用框架 | Laravel JsonResponse 已复用；改返回格式只会给现有 Vue／客户端制造兼容工作 | 保留小适配，不增加响应抽象 |
| `app/Core/Money.php` | 十进制字符串、BCMath、加价及上限是领域契约，插件另有上游舍入顺序 | 框架没有等价金额语义；float／通用 cast 不能直接替代 | 保留，计价器继续隔离在插件 |
| `app/Plugins/`、`plugins/` | 契约／DTO／注册器维护安装能力及物理隔离，具体供应商语义留在插件 | 容器可辅助装配，但不能取消插件边界；不需要为一个缓存调用新增接口 | 保留，原插件边界检查继续运行 |
| `app/Services/OutboxWorker.php`、`app/Services/NotificationService.php` | Outbox 与通知认领、防重、令牌、未知结果调查是业务可靠性；不能靠异常重试推断未执行 | Laravel Queue、Mail 可以承接调度／传输，不能替代同事务事实和外部结果分类 | 保留领域机制，不迁第二队列 |
| `plugins/notifications/mail/MailDriver.php` | SMTP 与 MIME 已由 Symfony Mailer／Mime 承接；每封独立连接，accepted／rejected／unknown 归插件判定 | Laravel Mail 也使用 Symfony 传输；仅改 facade 收益低，默认重试／故障转移会改变未知发送边界 | 保留成熟传输，不自写 SMTP，不自动重发 |
| `database/schema.sql`、`database/migrations/`、`app/Support/SchemaSql.php` | 框架负责迁移登记，完整 SQL 是空库结构事实，字典由脚本生成；SchemaSql 仅处理 MySQL DELIMITER | 不另建默认 users／jobs／cache 表；保留生成字典比维护两份独立 DDL 更便宜 | 27 表不变，无新迁移 |
| `scripts/console.php`、`tests/*.php`、`scripts/test_integration.py` | CLI 旧入口只 require Artisan；既有测试直接覆盖隔离 MySQL／Redis／HTTP、并发、崩溃和私有 FPM | PHPUnit／Pest 可按需求增量采用；整体换测试框架增加工作但不增加当前覆盖 | 保留测试资产，增加 Pint 前置检查 |

优先级由真实维护成本决定：本批先删去库存服务的底层 Redis 存取细节；其他设施没有确切收益前保持现状。认证上下文、请求校验如另批整理，应分别提交并运行对应专项，不与缓存收敛捆绑。

## 库存缓存试点契约

**变更原因与影响**：库存观察只需可丢失的存取／TTL／批读，适合直接使用 Laravel Cache。修改 `StockObservationService` 和既有库存专项；不新建 Cache 接口、适配器或通用 Repository。`read/remember/readMany` 的返回结构和调用者不变。

- 使用显式 Redis store，避免默认 store 改动让目录批读悄悄变为其他存储。内层键仍按 APP_KEY、数据库、Provider 和远端商品隔离；框架增加现有 `jianyun:cache:` 前缀和存储编码。
- `many` 在当前单 Redis／phpredis 配置下仍只发一次 MGET，保留目录调用方键、顺序和相同绑定的多个位置；不触发上游 fan-out。不承诺任意未来 Redis Cluster 配置也有相同批读行为。
- TTL 仍为 300 秒，解码仍检查 JSON／状态／数量／观察时间，超过 300 秒、明显未来或损坏值返回未知。缓存读取故障返回未知，写入失败不阻断已有真实预检结果或已提交业务。
- 原裸 Redis 键不再读取，首次视为冷缓存；原正常键最多 5 分钟自然过期。无需数据库迁移、缓存清库、迁移脚本、双读或双写。滚动加载期间新旧进程各自使用独立展示缓存，可能短暂显示未知，不影响下单权威检查。
- 观察继续不进入商品配额、配置快照、报价指纹、账单／实例冻结或任务；下单及 Worker 真实预检保持。没有资金／权限保证迁到缓存。

实例在途锁、上游 Token、验证码及续费提示不属于本试点。缓存服务端是受信基础设施；来自缓存的展示内容仍经过原白名单解码，前端没有因此取得授权。删除原门禁、原子消费或带所有者的发布检查会改变信任边界，不能视为“删防御代码”。

## 验证与运行边界

`REDIS_SERVER=/www/server/redis/src/redis-server python3 scripts/test_integration.py` 全量通过 **37套／2365项**检查，配置缓存前后 **24项**私有PHP-FPM检查通过，测试资源已清理。原库存专项从27项扩到36项，验证框架批读仅一次MGET、调用方键／顺序／重复绑定、Provider／商品／数据库隔离、真实TTL、陈旧／未来／损坏观察、旧键冷缓存、Redis工厂故障注入与恢复；本地配额、报价确认、缺货不出账不扣款及Worker预检仍通过。原实例展示60项、充值唤醒42项及其他资金／任务／权限专项保持通过。

`composer lint`对应的Pint检查、Composer严格校验、PHP解析／单元18项／插件44项／纯计价93项、Python语法、27表字典同步、改动Markdown链接／围栏与`git diff --check`均通过。运行依赖未升级，仅增加开发依赖Pint。纯格式提交为`e8f5fd8`，工具接入为`f20fe99`；缓存变更独立于该格式基线。全量日志为`/tmp/jianyun-framework-integration.log`，格式化标记对照结果为197文件、0标记差异。

所有数据库／Redis／HTTP／SMTP夹具和 PHP-FPM 均使用原隔离入口。不向真实上游发请求、不导入／购买商品，不修改 M2 资金、账期、任务或进程状态。没有前端改动，不把本批后端回归宣称为真实商品交付或页面验收。
