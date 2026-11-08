# 开发 Agent 与 Laravel Boost

## 范围

2026-11-08 按用户确认接入 Laravel Boost，仅作为开发工具。`composer.lock` 锁定 Boost 2.10.4；它及 MCP、Roster 等五个新增包均为开发依赖，没有升级现有运行依赖。没有安装 `laravel/ai`，不增加业务表、AI API Key、客户功能或常驻服务，不改变支付、Outbox、Worker 与插件边界。

本项目人工维护的 `AGENTS.md` 及领域契约优先；本批手动配置 MCP，不运行会生成通用规范/技能的 `boost:install` 或 `boost:update`，不增加 Composer 自动更新规范脚本。后续若更新 Boost，须审查工具清单和差异再运行本页验收。官方说明：[Laravel Boost](https://laravel.com/docs/13.x/boost)。

## 启动

1. 使用 PHP 8.4 与兼容的 Composer 执行 `composer install`，包含开发依赖。
2. 从仓库根目录启动 Codex。项目 `.codex/config.toml` 注册 `laravel-boost`，由 `python3 scripts/boost_mcp.py` 启动标准输入输出 MCP。其他支持 MCP 的开发客户端可使用相同命令，工作目录设为仓库根目录。Python 要求仍为 3.9+。
3. 用 `codex mcp get laravel-boost --json` 检查注册。客户端加载项目配置后才会获得工具；现有会话的工具清单不因文件变更自动刷新。

PHP 不在默认 PATH 时，启动命令追加 `--php /absolute/path/to/php`。启动器使用私有临时 `.env` 目录及配置/服务/包/路由缓存，净化继承环境，随机生成临时 APP_KEY；不读取根目录或 M2 的 `.env`、现有配置缓存及数据库/Redis/SMTP/上游凭据。会话结束清理自己的临时文件与进程。

默认提供 `search-docs`、`get-absolute-url` 和应用版本/依赖信息资源 `file://instructions/application-info.md`。默认 URL 为 `http://localhost`，只是生成相对链接的基准，不表示已启动 HTTP 服务。文档检索会把查询词及依赖包版本发给 Laravel 官方文档服务，不应在查询词中放业务数据或凭据；服务不可用时据实报告并查官方文档，不能当作测试通过。

## 可选：独立开发／测试数据库

默认不开放数据库工具。需要查询时，显式传入私有 JSON 文件：

```bash
python3 scripts/boost_mcp.py --database-config /private/path/boost.json
```

JSON 只允许以下五个字符串键，真实密码由操作者在本机填写，文件权限设为 `0600`，不要提交 Git：

```json
{
  "DB_HOST": "127.0.0.1",
  "DB_PORT": "3306",
  "DB_DATABASE": "jianyun_dev_agent",
  "DB_USERNAME": "agent_reader",
  "DB_PASSWORD": "replace-with-local-development-password"
}
```

仅接受回环地址与专用库名 `jianyun_test`、`jianyun_test_*`、`jianyun_dev_*`；这些命名限制防止误接，不代替操作者确认数据库归属。不得把真实业务库改名后接入。开发客户端也可在启动前设置 `JIANYUN_BOOST_DATABASE_CONFIG` 为文件绝对路径；项目 Codex 配置只传递这个显式路径，凭据内容仍不在命令参数中。本机文件可放 `.codex/boost.local.json`，已忽略。

显式绑定后额外开放 `database-connections`、`database-schema`、`database-query`，只用于开发数据。SQL 工具由锁定版本的 Boost 在只读事务内执行，并拒绝写入语句；推荐使用只有该开发库读权限的账号。Tinker、规则写入、业务/浏览器日志读取始终关闭，不注册浏览器日志 HTTP 路由。

## 测试与验收

Boost 帮助开发 Agent 获取框架上下文；回归与 UI 验收继续使用本项目的真实入口，不以 `php artisan test` 代替自定义检查，不通过 MCP 调用付款、重装或其他真实上游操作。

```bash
# 无数据库：MCP 握手、应用信息、环境隔离、工具限制与退出
python3 scripts/test_boost.py

# 原隔离业务回归，并在同一个临时 MySQL 中验证 MCP schema/只读查询
REDIS_SERVER=/absolute/path/to/redis-server python3 scripts/test_integration.py --boost

python3 scripts/sync_schema_docs.py --check
git diff --check
```

生产安装继续 `composer install --no-dev --optimize-autoloader`；没有 Boost/MCP 开发包时，配置仍可加载。`config/boost.php` 默认关闭 Boost，只有独立 CLI 启动器显式开启；不会因为开发依赖存在就给普通 HTTP 请求增加诊断入口。无需给 M2/正式 Worker、调度或服务配置传入任何 Boost 环境变量。

本批验收（2026-11-08）：

- 32 项独立 Boost 检查通过：真实 MCP 握手/资源/URL、污染环境与配置缓存隔离、禁用工具拒绝、普通 HTTP/CLI 关闭，以及取消时完整清理忽略 TERM 的父/孙进程。
- 12 项隔离数据库 MCP 检查通过：实际库身份、27 表结构、只读查询、写入拒绝与数据保持；原 694 项业务检查和私有 PHP-FPM 配置缓存前后检查通过。
- 真实 `search-docs` 查询 `database transactions` 成功，返回 `laravel/framework@13.x` 官方文档。本机 `codex mcp get laravel-boost --json` 已识别项目配置；不把注册等同于当前会话热加载。
- 在独立临时副本执行真实 `composer install --no-dev --optimize-autoloader`（验收时关闭 Composer hooks），确认 Boost/MCP 类不存在，生产配置缓存前后均可启动、无浏览器日志路由。临时副本已清理。
- Composer 校验、PHP 语法、Python 编译、27 表字典同步及差异检查通过。运行依赖锁定内容与接入前完全一致，未操作 M2 服务或业务库；本批未重跑运行守护/生产安装演练或网页验收。
