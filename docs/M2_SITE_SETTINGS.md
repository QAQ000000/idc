# 站点基础配置

## 范围、接口和公开边界

`/admin/site` 为独立基础资料页，不并入邮件、支付或验证码策略。仅当前启用的 `super_admin` 可以 `GET/PATCH /api/v1/admin/site-settings`；事务取得管理员锁后重查角色、二次认证限制及会话版本。保存携带 `expected_version`，五个字段完整提交，配置与 `site_configure` 审计同事务完成。没有新增表、列、迁移、依赖、上传或 Worker。

复用 `system_settings`，唯一键 `site.basic`、group=`site`，value 仅包含以下字段；不写 encrypted_credentials。版本是原 value 与对应最新审计编号的服务端 HMAC，同值保存也推进版本，拒绝 ABA 和旧请求覆盖。审计 target_type=`site`、target_id=0，context 仅有 before/after 的五项资料。

| 字段 | 上限（Unicode 字符） | 空值展示 |
| --- | --- | --- |
| site_name | 80 | 简云 |
| logo_path | 255 | 默认 J 标识 |
| company_name | 120 | 隐藏 |
| copyright_text | 200 | 让云资源管理更简单 |
| icp_number | 80 | 隐藏 |

所有字段为 UTF-8 单行纯文本，拒绝控制字符，保存去除首尾空格，不渲染 HTML。Logo 仅接受已经部署的本站根相对静态路径，如 `/images/logo.svg`；目录和文件名由 ASCII 字母、数字、下划线及连字符组成，后缀为小写 png/jpg/jpeg/gif/webp/svg/ico。不接受 URL、双斜杠、反斜杠、点路径、百分号编码、查询或片段。本批不读取文件、不检查文件存在、不代拉取上游资源、不提供上传；管理员负责可信静态资源部署。图片加载失败展示默认标识。

`GET /api/v1/site` 匿名读取，精确输出上述五项，不返回版本、审计、更新时间、配置状态或其他配置。只按固定键 SELECT value，不枚举配置表、不解密凭据；即使该 JSON 被混入额外属性也逐字段丢弃，非法值回退默认。后台接口额外返回 configured 与 version。以上响应均 no-store。

## 前端、权限和异常恢复

同一 Vue 应用使用 display-only 的 site store，启动、路径切换及窗口 focus 时异步刷新；不增加轮询，不等待配置加载再进入业务页。匿名 fetch 不附带客户／管理员 Token、Cookie，4 秒终止未完成请求。初始、空值、非法响应或读取失败使用逐字段默认值；不会清除登录或阻断商品、实例、付款。Logo 失败单独回退。

导航、公开商品页站名、公共页脚与浏览器标题读取同一状态；标题为“站名 · 云产品／管理后台”。后台保存后只读核对到的真实值更新同一状态，不用未提交草稿；其他已打开页面在下一次访问／focus 后更新，不承诺跨标签实时广播。管理员明确读取到的新配置会使旧公开请求失效，避免迟到响应覆盖新值。默认 HTML title 保留为首帧／无脚本回退。

配置摘要、站名／Logo 与保存／核对入口前置，公司／版权／备案按需展开。非敏感草稿仅存在当前页，不预览到公共页壳。有草稿、提交中或结果未知时离开提示。非超级管理员不发配置读取，直接 URL 保留权限说明；后端仍为权威权限边界。

复用 useSettingsDraft：保存后 GET 确认；409、丢响应或不可信成功仅只读核对，不自动 PATCH。版本变化且字段一致只说明当前配置已与本次提交一致；相同版本且原请求未知继续阻断，不能因“尚未读到修改”直接重发。其他版本／字段不一致时保留草稿，由管理员明确采用当前值或保留草稿后采用新版本。本站资料为公开信息，不得填写密钥；不会以白名单保证管理员主动填入文本中的秘密可被自动识别。

## 变更原因、复杂度和兼容

将硬编码站名／页脚移至单个既有配置行，日常修改无需构建。新增一个小领域服务、固定公开投影、独立表单和显示状态；复用现有权限、配置草稿恢复、审计与数据库事务，不新增通用配置编辑器、同步工具、发布系统或多租户主题。

数据库 DDL、历史资金／资源／任务、支付商户、SMTP 和上游凭据保持原契约，验证码策略另批设计。既有安装在未保存时直接使用默认值。本批只开发与隔离验收，不修改 M2 配置、不迁移／重启 Worker；M2 接入、真实部署及跨浏览器／物理设备体验另验。

## 验收计划

- 独立 MySQL／HTTP：匿名白名单、所有角色、非法值／Logo 路径、版本竞争、同值 ABA、审计故障回滚、等待中管理员失权；其他配置和凭据不变。
- 浏览器：多个入口一致，未保存草稿不生效，真实保存／丢响应／409，未知版本阻断、读取失败与超时默认，迟到响应不覆盖；Logo 失败、长文案及手机／竖屏平板布局。
- 单元／插件边界、完整隔离集成、私有 PHP-FPM 配置缓存前后、Vue 类型／Lint／构建、27 表字典与差异检查。

## 实际验收（2026-11-09）

- 完整隔离运行 `REDIS_SERVER=/www/server/redis/src/redis-server python3 scripts/test_integration.py --browser`：**1854项、28套**全部通过（原1800＋本批54）。本批覆盖首次保存、匿名／客户／三类非超级管理员拒绝、完整白名单、所有字段类型／长度、Logo路径拒绝、旧版本／同值ABA、真实MySQL等待竞争、等待中管理员撤权、审计故障整笔回滚及其他设置逐值不变。
- 审计JSON由MySQL规范化对象键序，测试按字段内容比较；并发夹具按真实服务的管理员→配置顺序取得锁，避免测试反转顺序导致的死锁。最终完整运行通过；无放宽生产CAS、权限或审计要求。私有PHP-FPM在配置缓存前后均通过白名单、保存／重复409、当前角色与跨请求身份隔离检查。
- Chromium **79项**：布局26、真实保存／丢响应／冲突及入口一致21、异常恢复16、角色／最长文案16。真实隔离PATCH已提交后主动丢弃响应，GET确认一致且只有一次写；另一请求保存后本页409、草稿保留，显式采用新版本后才允许重新提交。公开商品、客户／管理员登录、客户实例／账单／余额与后台共用展示状态；未保存草稿不影响页壳。
- 未知且版本不变不能再次保存／采用；显式恢复仅GET。初读失败不开表单；配置失败／4秒超时仍可浏览商品，默认站名和页脚可用，旧公开响应不能覆盖管理员新读取。公开读取在已有Token和Cookie时均未发送它们，读取失败保留会话。HTML样式文本只作字面内容显示，外部Logo被拒绝，空值逐字段回退；缺失图片回退J，合法本站SVG夹具正常显示。
- 桌面1440×1000、320×568／390×844手机、768×1024／820×1180竖屏平板检查默认／展开／收起；默认保存首屏可达。最长站名80、公司120、版权200及备案80字符在320／390／768／820／1440px无横向溢出，站名截断仍有完整无障碍名称和title。390×450仅为缩小键盘视口，不能替代物理键盘、移动真机或跨浏览器验收。
- Vue类型检查、ESLint零错误／零警告、Vite构建、27表字典同步和Git差异检查通过。保留既有Element Plus公共包超过500kB提示，无新依赖、构建产物或迁移提交。M2配置／业务数据未改动，没有真实邮件、收款、上游调用或Worker接管；M2接入与实际展示仍待验。

浏览器脚本、结果和截图位于Git忽略目录[验收产物](../output/playwright/site-settings/results.json)，代表截图：[320px默认](../output/playwright/site-settings/summary-320.png)、[768px展开](../output/playwright/site-settings/expanded-768.png)、[真实丢响应](../output/playwright/site-settings/real-save-390.png)、[真实冲突](../output/playwright/site-settings/real-conflict-390.png)、[未知请求](../output/playwright/site-settings/unknown-390.png)、[公开失败回退](../output/playwright/site-settings/public-failure-390.png)、[最长站名](../output/playwright/site-settings/long-320.png)、[最长页脚](../output/playwright/site-settings/long-footer-320.png)。最终日志为 `/tmp/jianyun-site-settings-integration.log` 和 `/tmp/jianyun-site-settings-{layout,real,recovery,permissions}.log`，构建日志另存 `/tmp/jianyun-site-settings-build.log`。
