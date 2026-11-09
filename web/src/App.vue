<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useUser } from "./stores/user";
import { useAdmin } from "./stores/admin";
import { message } from "./api/request";
const account = useUser(),
  adminAccount = useAdmin();
const router = useRouter(),
  route = useRoute(),
  menuOpen = ref(false),
  accountError = ref("");
const adminArea = computed(
  () => route.path === "/admin" || route.path.startsWith("/admin/"),
);
const workspace = computed(() =>
  Boolean(route.meta.admin || route.meta.client),
);
const links = computed(() =>
  adminArea.value
    ? [
        { to: "/admin/customers", label: "客户查询", mark: "◎" },
        { to: "/admin/instances", label: "实例查询", mark: "▦" },
        { to: "/admin/providers", label: "上游接入", mark: "⇄" },
        { to: "/admin/products", label: "商品管理", mark: "◇" },
        { to: "/admin/tickets", label: "客服工单", mark: "✉" },
        ...(adminAccount.admin?.role === "super_admin" ? [{to:"/admin/notifications", label:"邮件与通知", mark:"⚙"}] : []),
        ...(adminAccount.admin?.role === "super_admin" ? [{to:"/admin/payments", label:"易支付配置", mark:"¥"}] : []),
        ...(adminAccount.admin?.role === "super_admin" ? [{to:"/admin/accounts", label:"管理员账号", mark:"♙"}] : []),
        {to:"/admin/account/security", label:"账户安全", mark:"◇"},
        { to: "/admin/tasks", label: "异常任务", mark: "≡" },
        ...(["super_admin", "finance"].includes(adminAccount.admin?.role || "") ? [{to:"/admin/finance", label:"资金查询", mark:"¥"}] : []),
      ]
    : [
        { to: "/instances", label: "我的实例", mark: "▦" },
        { to: "/invoices", label: "我的账单", mark: "≡" },
        { to: "/wallet", label: "余额与充值", mark: "¥" },
        { to: "/tickets", label: "我的工单", mark: "✉" },
        { to: "/account/security", label: "账户安全", mark: "◇" },
      ],
);
function isSection(path: string) {
  return route.path === path || route.path.startsWith(path + "/");
}
const identity = computed(() =>
  adminArea.value ? adminAccount.admin?.username : account.user?.email,
);
watch(
  () => route.fullPath,
  () => {
    menuOpen.value = false;
  },
);
async function loadAccount() {
  accountError.value = "";
  try {
    await account.load();
  } catch (e) {
    if (sessionStorage.getItem("jianyun_token"))
      accountError.value = message(e);
  }
}
onMounted(loadAccount);
function logout() {
  accountError.value = "";
  account.logout();
  void router.push("/");
}
function adminLogout() {
  adminAccount.logout();
  void router.push("/admin/login");
}
</script>
<template>
  <a class="skip-link" href="#main-content">跳至正文</a>
  <div class="shell" :class="{ 'workspace-shell': workspace }">
    <header class="topbar">
      <div class="brand-group">
        <el-button
          class="mobile-menu"
          aria-label="打开导航菜单"
          aria-controls="mobile-navigation"
          :aria-expanded="menuOpen"
          @click="menuOpen = true"
        >
          ☰
        </el-button>
        <RouterLink class="brand" to="/" aria-label="简云首页">
          <span class="brand-symbol" aria-hidden="true">J</span>
          简云
          <span class="brand-caption">
            {{ adminArea ? "管理后台" : "云产品服务" }}
          </span>
        </RouterLink>
      </div>
      <nav class="desktop-nav" aria-label="主导航">
        <template v-if="adminArea">
          <RouterLink to="/admin/products">商品管理</RouterLink>
          <RouterLink to="/admin/tasks">异常任务</RouterLink>
          <RouterLink to="/">客户前台</RouterLink>
          <template v-if="adminAccount.admin">
            <span class="identity">{{ adminAccount.admin.username }}</span>
            <el-button @click="adminLogout">退出管理后台</el-button>
          </template>
        </template>
        <template v-else>
          <RouterLink to="/" exact-active-class="nav-current">
            选购产品
          </RouterLink>
          <template v-if="account.user">
            <RouterLink to="/instances">控制台</RouterLink>
            <RouterLink class="identity" to="/wallet">余额 ¥{{ account.user.balance }}</RouterLink>
            <el-button @click="logout">退出登录</el-button>
          </template>
          <template v-else>
            <RouterLink to="/login">登录</RouterLink>
            <el-button type="primary" @click="router.push('/register')">
              创建账户
            </el-button>
          </template>
          <RouterLink class="quiet-link" to="/admin/tasks">管理后台</RouterLink>
        </template>
      </nav>
      <span class="mobile-context">
        {{ adminArea ? "管理后台" : workspace ? "客户控制台" : "云产品" }}
      </span>
    </header>
    <div class="shell-body">
      <aside v-if="workspace" class="sidebar" aria-label="工作区导航">
        <p class="eyebrow">{{ adminArea ? "管理工作区" : "客户控制台" }}</p>
        <nav class="side-nav" aria-label="侧边导航">
          <RouterLink v-for="link in links" :key="link.to" :to="link.to" :class="{ 'nav-selected': isSection(link.to) }">
            <span aria-hidden="true">{{ link.mark }}</span>
            {{ link.label }}
          </RouterLink>
          <RouterLink to="/">
            <span aria-hidden="true">＋</span>
            选购产品
          </RouterLink>
        </nav>
        <div v-if="identity" class="sidebar-account">
          <span class="avatar" aria-hidden="true">
            {{ identity.slice(0, 1).toUpperCase() }}
          </span>
          <div>
            <strong>{{ adminArea ? "管理员" : "当前账户" }}</strong>
            <p>{{ identity }}</p>
            <small v-if="adminArea">{{ adminAccount.admin?.role }}</small>
          </div>
        </div>
      </aside>
      <div class="content-column">
        <main id="main-content" class="el-main" tabindex="-1">
          <el-alert
            v-if="accountError && !adminArea"
            title="账户信息暂时不可用"
            :description="accountError"
            type="warning"
            :closable="false"
          >
            <el-button @click="loadAccount">重试账户查询</el-button>
          </el-alert>
          <RouterView
            :key="
              $route.path === '/reset-password' || /^\/admin\/customers\/[1-9]\d*$/.test($route.path) ? $route.path : $route.fullPath
            "
          />
        </main>
        <footer class="site-footer">
          <span>简云 · 云产品服务</span>
          <span>让云资源管理更简单</span>
        </footer>
      </div>
    </div>
    <el-drawer
      v-model="menuOpen"
      title="导航菜单"
      direction="ltr"
      size="min(300px, 90vw)"
    >
      <nav
        id="mobile-navigation"
        class="side-nav mobile-navigation"
        aria-label="移动导航"
        @click="menuOpen = false"
      >
        <RouterLink to="/">选购产品</RouterLink>
        <template v-if="adminArea && adminAccount.admin">
          <RouterLink v-for="link in links" :key="link.to" :to="link.to" :class="{ 'nav-selected': isSection(link.to) }">
            {{ link.label }}
          </RouterLink>
          <p class="muted">{{ identity }}</p>
          <el-button @click="adminLogout">退出管理后台</el-button>
        </template>
        <template v-else-if="!adminArea && account.user">
          <RouterLink v-for="link in links" :key="link.to" :to="link.to" :class="{ 'nav-selected': isSection(link.to) }">{{ link.label }}</RouterLink>
          <p class="muted">
            {{ account.user.email }}
            <br>
            余额 ¥{{ account.user.balance }}
          </p>
          <el-button @click="logout">退出登录</el-button>
        </template>
        <template v-else-if="!adminArea">
          <RouterLink to="/login">登录</RouterLink>
          <RouterLink to="/register">创建账户</RouterLink>
        </template>
        <RouterLink :to="adminArea ? '/' : '/admin/tasks'">
          {{ adminArea ? "客户前台" : "管理后台" }}
        </RouterLink>
      </nav>
    </el-drawer>
  </div>
</template>
