# 手机与竖屏平板操作路径优化

2026-11-09 接续治理：客户详情已按概览／系统维护／账期与续费分区，保持正常VNC、电源首屏可达，并单独验证任务、明文凭据和操作意图。下文保留上一批三页优化的历史证据；最新客户页路径、坐标与148项检查见 [客户样板](CLIENT_INSTANCE_PAGE_SAMPLE.md)。

本批按用户提供的《PORTRAIT_MOBILE_UX_OPTIMIZATION_REPORT.md》v2.1.0 顺序实施。解决纵向操作距离，不以整页高度作为验收指标；沿用单 Vue / Element Plus 和原业务服务，不新增接口、表、依赖或 Worker 分支。

## 页面与状态契约

| 顺序／页面 | 交互与边界 |
|---|---|
| 1. `/instances/:id` | 服务／交付状态、IP、VNC 和电源前移；异常在操作附近直接显示。规格、购买账单、电源历史和重装表单默认折叠。折叠仅控制展示，不卸载电源／重装组件，保留轮询、互斥事件和凭据失效处理；未决操作摘要不可折叠。登录凭据仍按需拉取、仅留内存，隐藏／离页清除。 |
| 2. `/checkout/:id` | 小于 1024px（含 768／820 竖屏平板）使用常驻底栏：首期价、周期续费价、报价状态、摘要及原提交入口。摘要抽屉仅展示 Quote 原字段，不拆算配置价格。底栏和桌面按钮共用 `canSubmit`、`submit()`、`checkoutKey`；旧报价失效后禁用，需显式重新获取。底栏动态测量占位，兼顾安全区和可视视口变化；输入后可滚动到底部入口。 |
| 3. `/admin/tasks` | 小于 1024px 将六项高级筛选收进抽屉，保留任务 ID 快速查询；草稿与 URL 已生效条件分离，取消不生效。显示生效标签、逐项删除与清除筛选；应用／分页保留 URL，重置到全部状态，首次进入仍默认异常关注。不扩展关键词接口，不混淆任务 ID 与实例 ID。桌面保留展开筛选。 |

权限与金融边界继续由后端裁决；展示运行状态不证明电源命令或重装完成。折叠期间的结果未知、互斥锁定和查询失败不得被隐藏成可操作状态。只在独立环境使用夹具与拦截响应验收，不在 M2 发起真实下单、付款、电源或重装。

## 验收方法

在 Chromium 320×568、390×844、768×1024、820×1180 及桌面检查首屏触达、抽屉展开／取消／应用、异常／空状态／重试、报价变化和失效、原请求键重试与分页。记录改造前后关键按钮／首条任务的文档纵坐标和从首屏触达所需滚动距离，截图保存在 `output/playwright/portrait/`（忽略目录，不打包部署）。键盘使用焦点、Tab／Enter 及缩小可视区域模拟，真实 iOS／Android 软键盘与触控另行手测，不将模拟当真机证据。

## 验收记录

### 改造前后的操作路径

| 页面 | 改造前 | 改造后／增加的交互 |
|---|---|---|
| 实例详情 | 先经过规格和账单，向下寻找电源；继续滚动寻找 VNC | 正常状态首屏直接点 VNC／电源。规格、历史、重装各按需展开一次；异常原因与在途任务摘要保持可见。凭据仍单独点击读取／隐藏。 |
| 结账 | 选配后滚到底部看价格／提交；改配置需往返滚动 | 选配时底部持续显示报价，直接使用原提交入口。一次打开摘要即可核对冻结配置和选择余额；表单未填完整时自动关闭摘要、聚焦原输入框。 |
| 后台任务 | 六项筛选始终展开，320px 需先滚动才看到数据 | 进入即可看首条任务；任务 ID 直接查询，其余条件增加一次打开筛选抽屉。取消不应用；标签显示实际条件，可逐项删除或清除到全部任务。分页／刷新不使用未应用草稿。 |

下表是同一独立夹具、页面初始滚动位置 0 的入口文档坐标（px，四舍五入）。它衡量触达位置，不是压缩总页面高度的目标。结账改造后为固定底栏坐标，随页面滚动仍可见；平板原默认短表单的提交入口本已在首屏，增量收益是长选配和滚动时保持价格可见。

| 视口 | VNC Y 前 → 后 | 电源按钮 Y 前 → 后 | 结账提交 Y 前 → 后 | 首条任务 Y 前 → 后 |
|---|---:|---:|---:|---:|
| 320×568 | 2740 → 351 | 1094 → 499 | 1601 → 516 | 890 → 432 |
| 390×844 | 2545 → 329 | 993 → 477 | 1507 → 792 | 627 → 368 |
| 768×1024 | 2421 → 349 | 986 → 497 | 968 → 972 | 463 → 388 |
| 820×1180 | 2421 → 349 | 986 → 497 | 945 → 1128 | 463 → 388 |

正常夹具四种视口均能完整显示 VNC、电源按钮、结账提交和首条任务。320px 下 VNC 的必要滚动距离由 2204px 降为 0，结账提交由 1073px 降为 0，首条任务由 385px 降为 0。异常横幅允许占用首屏，不为凑位置折叠阻断原因。

### 检查与实际边界

- Chromium **154 项页面检查**通过：实例 47、结账 40、任务 48、表单校验／暂停／错误及真实 15 秒轮询 13、Visual Viewport 模拟 6。覆盖 320／390／768／820px，桌面 1440px 保留原布局；没有整页横向溢出，表格仅内部滚动。
- 实例：正常、展开／收起、电源二次确认后取消、显式读取／隐藏凭据、未决电源／重装、服务暂停、503／不残留旧数据；折叠重装面板实际等待下一次 15 秒查询，仍更新状态并保持互斥，未提交电源或清盘请求。
- 结账：长选配、不同首期／续费价、摘要／余额选项、报价乱序／失效／失败／重算、503 结果未知后保留原请求键；底栏和抽屉均关联原 `checkout-form`。缩小视口测试输入与 Tab；另通过覆盖 Visual Viewport 的测试属性模拟“布局高度不变、键盘挤占可视区域”，验证底栏移位、末项输入可见及关闭后恢复。这些均不是实际操作系统键盘证据。
- 任务：六项筛选、草稿取消／重新打开、URL 条件恢复、实例条件应用、分页、逐项删除、清除到全部状态、快速任务 ID、空状态／503／重试及键盘 Tab。分页总数 42 为浏览器合成，记录本身来自独立库；不据此声称有 42 条真实异常。
- 前端类型检查、lint（0 警告）、生产构建、15 项单元／39 项插件检查、27 表字典同步及 Git 差异检查通过。构建保留原 Element Plus 大包提示。独立 UI 环境另外执行了配置缓存前后私有 FPM 身份／财务隔离检查；本轮未重跑 1109 项完整业务回归，不将 UI 启动器跳过的套件记为已执行。

未执行真实付款、上游开关机／重装／续费、M2 数据修改、数据库迁移或 Worker 重启。iOS／Android 真机键盘、安全区硬件、触控及其他浏览器仍需用户体验确认；浏览器 CSS 安全区回退与模拟通过不替代真机结论。

### 本机截图与复核入口

截图和脚本在忽略目录 `output/playwright/portrait/`，作为本机验收材料，不进入 Git／部署包。下列前后配对使用相同夹具；另有长选配报价的合成分支截图，文件名不含 baseline。

| 页面 | 320px | 390px | 768px | 820px |
|---|---|---|---|---|
| 实例 | [前](../output/playwright/portrait/before-instance-320.png)／[后](../output/playwright/portrait/after-instance-320.png) | [前](../output/playwright/portrait/before-instance-390.png)／[后](../output/playwright/portrait/after-instance-390.png) | [前](../output/playwright/portrait/before-instance-768.png)／[后](../output/playwright/portrait/after-instance-768.png) | [前](../output/playwright/portrait/before-instance-820.png)／[后](../output/playwright/portrait/after-instance-820.png) |
| 结账 | [前](../output/playwright/portrait/before-checkout-320.png)／[后](../output/playwright/portrait/after-checkout-baseline-320.png) | [前](../output/playwright/portrait/before-checkout-390.png)／[后](../output/playwright/portrait/after-checkout-baseline-390.png) | [前](../output/playwright/portrait/before-checkout-768.png)／[后](../output/playwright/portrait/after-checkout-baseline-768.png) | [前](../output/playwright/portrait/before-checkout-820.png)／[后](../output/playwright/portrait/after-checkout-baseline-820.png) |
| 任务 | [前](../output/playwright/portrait/before-tasks-320.png)／[后](../output/playwright/portrait/after-tasks-320.png) | [前](../output/playwright/portrait/before-tasks-390.png)／[后](../output/playwright/portrait/after-tasks-390.png) | [前](../output/playwright/portrait/before-tasks-768.png)／[后](../output/playwright/portrait/after-tasks-768.png) | [前](../output/playwright/portrait/before-tasks-820.png)／[后](../output/playwright/portrait/after-tasks-820.png) |

展开／异常／键盘截图按上述四种宽度保存：`instance-expanded-*`、`instance-confirm-*`、`instance-selector-*`、`instance-blocked-*`、`checkout-summary-*`、`checkout-expired-*`、`checkout-required-*`、`checkout-keyboard-*`、`tasks-drawer-*`、`tasks-keyboard-*`、`tasks-empty-*`、`tasks-error-*`。可视视口模拟为 `checkout-visual-viewport-390.png`、`checkout-visual-viewport-820.png`；桌面三个代表页为 `*-desktop-1440.png`。已人工查看代表截图及汇总图 `review-contact-sheet.jpg`。

执行记录为 `/tmp/jianyun-portrait-{baseline,instance,checkout,tasks,final,viewport}.log`，结构化结果见该截图目录的 `results.json`。临时 UI 启动器复用原隔离环境初始化、迁移和浏览器夹具，仅跳过完整业务套件；结束后清理独立库／Redis／HTTP／FPM／Vite。需要重新搭建时沿用 README 的 `scripts/test_integration.py --browser`，其正式入口会完整执行业务套件后保留 UI 环境，不能连接业务库或将 fixture 导入 M2。

### 兼容与维护成本

本批因窄屏纵向路径过长调整三个页面及电源／重装展示，抽出订单摘要和任务筛选两个纯展示组件，避免桌面与抽屉分别维护表单／费用逻辑。新增的媒体查询监听、底栏尺寸观察及可视视口监听随页面卸载清理；摘要校验失败处理只回到原必填项，不另写下单流程。冻结报价、请求键、角色／归属、凭据接口、任务门禁、上游能力、令牌和 Worker 行为继续沿用原实现。API、数据库字典和后端契约没有变更；FRONTEND、前端整理设计、README 和路线图同步本批摘要，27 表无迁移。
