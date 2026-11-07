# JianYun (简云) · 统一单前端架构设计规范 (Frontend Specification)

本文档定义了 JianYun 的前端工程架构、技术选型、目录规范、交互模型与权限路由设计。

---

## 一、 技术选型与设计原则

### 1. 核心技术栈
- **构建工具**：**Vite 5+**（秒级热更新，毫秒级冷启动）
- **开发框架**：**Vue 3 (Composition API / `<script setup lang="ts">`) + TypeScript**
- **UI 组件库**：**TDesign Vue Next**（腾讯企业级开源组件库，极度契合浅色现代化云控制台）或 **Element Plus**
- **原子化样式**：**Tailwind CSS**（高自由度定制专属质感，杜绝庞大 CSS 冗余）
- **状态管理**：**Pinia**
- **客户端路由**：**Vue Router 4**

### 2. 核心架构：统一单应用 (One App Architecture)
> [!IMPORTANT]
> **绝对禁止多端分散（严禁同时维护多个独立前端项目）！**
> 整个系统只维护一套位于 `web/` 的统一前端单页应用（SPA）。

```text
                               ┌─────────────────────────────┐
                               │     统一单前端工程 (web/)   │
                               └──────────────┬──────────────┘
                                              │
              ┌───────────────────────────────┼───────────────────────────────┐
              ▼                               ▼                               ▼
     【公开访客态 (Guest)】        【客户登录态 (Client)】        【管理员登录态 (Admin)】
   • 免登商品选购大厅             • 我的云服务器资产管控         • 用户管理 (一键免密模拟登录)
   • 动态规格计算与价格预览       • 实例开关机/重装/VNC          • 实例运维中心 (一键改价补差)
   • 登录 / 注册 / 找回密码       • 自主续费与变配补差           • 上游节点配置 (动态表单)
                                  • 财务账单支付与充值           • 任务发件箱监控与重试
                                  • 售后工单沟通                 • 全站财务流水与退款审计
                                  • 推荐返利与佣金提现           • 工单客服处理中心
```

---

## 二、 统一工程目录结构 (`web/`)

```text
web/
├── package.json
├── vite.config.ts
├── tailwind.config.js
├── tsconfig.json
├── index.html
└── src/
    ├── main.ts                       # 入口引导
    ├── App.vue
    ├── api/                          # 统一 API 客户端
    │   ├── request.ts                # Axios 封装 (自动注入 Token, 401 拦截)
    │   ├── auth.ts
    │   ├── catalog.ts
    │   ├── instances.ts
    │   ├── billing.ts
    │   ├── tickets.ts
    │   └── admin.ts
    ├── components/                   # 全局复用业务组件
    │   ├── StatusBadge.vue           # 统一实例/账单状态徽章 (Active绿, Suspended橙)
    │   ├── ConfigOptionRenderer.vue  # 动态配置项渲染器 (滑块、单选、级联)
    │   ├── CaptchaWidget.vue         # 场景化自适应验证码挂载器
    │   ├── VncModal.vue              # Web VNC 独立弹窗
    │   └── PriceOverrideModal.vue    # 运维一键改续费价并划扣差价弹窗
    ├── layouts/
    │   └── AppLayout.vue             # 统一响应式布局 (顶部栏 + 折叠侧边栏 + 手机端抽屉)
    ├── router/
    │   ├── index.ts                  # 路由表
    │   └── guard.ts                  # 权限守卫 (根据 user.role 过滤路由)
    ├── stores/
    │   ├── user.ts                   # 用户信息与 Token
    │   └── app.ts                    # 侧边栏折叠、全局通知
    └── views/
        ├── public/                   # 公开页面
        │   ├── CatalogView.vue       # 云产品选购大厅
        │   └── CheckoutView.vue      # 订单支付收银台
        ├── auth/
        │   ├── LoginView.vue
        │   ├── RegisterView.vue
        │   └── ForgotPasswordView.vue
        ├── client/                   # 客户控制台专属页面
        │   ├── InstancesView.vue     # 我的服务器列表
        │   ├── InstanceDetailView.vue# 实例详情 (图表、操作、重装系统)
        │   ├── InvoicesView.vue      # 我的账单
        │   ├── TicketsView.vue       # 我的工单
        │   └── AffiliateView.vue     # 推广返佣中心
        └── admin/                    # 管理员专属页面
            ├── UsersView.vue         # 客户管理 (含模拟登录按钮)
            ├── InstancesManageView.vue # 全站主机运维 (含一键改价)
            ├── ProvidersView.vue     # 上游节点配置 (根据 Schema 自动渲染)
            ├── TasksView.vue         # 任务发件箱监控
            ├── FinanceView.vue       # 财务流水与退款
            └── SettingsView.vue      # 场景化验证码/通知渠道配置
```

---

## 三、 关键前端交互与组件规范

### 1. 动态规格配置项渲染器 (`ConfigOptionRenderer.vue`)
- **职责**：接收来自后端 SWR 缓存返回的 `config_schema` JSON 数据，自动判断类型：
  - `type: 'slider'` $\rightarrow$ 渲染滑动条组件（用于带宽 1~100M、数据盘 20~1000G）；
  - `type: 'radio'` / `select` $\rightarrow$ 渲染卡片单选框（用于 1核2G、2核4G、4核8G）；
  - `type: 'os'` $\rightarrow$ 渲染操作系统镜像选择器（支持 Windows / Linux 分组切换）。
- **实时响应**：选项变动时，实时触发本地价格联动计算，保证用户操作零卡顿。

### 2. 状态徽章与色彩体系 (`StatusBadge.vue`)
全站统一遵循企业级云服务语义色彩（浅色背景 + 高对比深色字，满足 WCAG 规范）：
- **运行正常 / 已支付 / 成功 (Success)**：浅绿底背景 (`#EAFBF3`) + 深绿文字 (`#097A43`)；
- **欠费暂停 / 待支付 / 审核中 (Warning)**：浅橙底背景 (`#FFF6E5`) + 深橙文字 (`#805200`)；
- **已销毁 / 已失败 / 已退款 (Error)**：浅红底背景 (`#FFF1F0`) + 深红文字 (`#BF3128`)；
- **待开通 / 执行中 (Info)**：浅蓝底背景 (`#E8F1FF`) + 深蓝文字 (`#0E4FCC`)。

### 3. 运维改价补差弹窗 (`PriceOverrideModal.vue`)
- 管理员点击实例列表的【改价/变配】时唤起；
- 输入新的月付价格，系统自动根据到期日折算剩余天数应收差额；
- 支持一键勾选【从用户余额划扣】，确认后提交，自动刷新表格，极大提升日常运维效率。

- **推荐：`@wangeditor/editor-for-vue`（或 `@tiptap/vue-3`）**
  1. **旧数据 100% 兼容**：原生吃进 HTML，旧库的 118 篇新闻直接放进去，原汁原味展现和编辑，零迁移痛苦；
  2. **轻量美观**：纯 Vue 3 组件，界面清爽现代，体积仅几百 KB；
  3. **自带常用能力**：支持图片上传/拖拽、代码高亮、表格、标题格式化，开箱即用；
  4. **前端安全与防 XSS**：配合 `DOMPurify` 库对展示内容做自动清洗，彻底杜绝恶意脚本注入。

---

## 四、 响应式与跨端适配 (Responsive)
- **桌面视口 (>= 1024px)**：标准侧边栏布局，高密度信息表格；
- **移动端视口 (< 1024px)**：侧边栏自动收缩为底部/汉堡抽屉，表格自适应折叠为卡片流视图，保证手机随时随地开机、续费、改配置。
