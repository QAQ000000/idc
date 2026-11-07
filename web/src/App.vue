<script setup lang="ts">
import { computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useUser } from "./stores/user";
import { useAdmin } from "./stores/admin";
const account = useUser(), adminAccount = useAdmin();
const router = useRouter(), route = useRoute();
const adminArea = computed(() => route.path === "/admin" || route.path.startsWith("/admin/"));
onMounted(() => account.load().catch(() => account.logout()));
function logout() { account.logout(); void router.push("/"); }
function adminLogout() { adminAccount.logout(); void router.push("/admin/login"); }
</script>
<template>
  <el-container class="shell">
    <el-header class="topbar">
      <RouterLink class="brand" to="/">简云 <span>{{ adminArea ? '管理后台' : '云产品' }}</span></RouterLink>
      <nav aria-label="主导航">
        <template v-if="adminArea">
          <RouterLink to="/admin/tasks">异常任务</RouterLink><RouterLink to="/">客户前台</RouterLink>
          <template v-if="adminAccount.admin"><span>{{ adminAccount.admin.username }} · {{ adminAccount.admin.role }}</span><el-button @click="adminLogout">退出管理后台</el-button></template>
        </template>
        <template v-else>
          <RouterLink to="/">选购产品</RouterLink>
          <template v-if="account.user"><RouterLink to="/instances">我的实例</RouterLink><span>{{ account.user.email }} · 余额 ¥{{ account.user.balance }}</span><el-button @click="logout">退出登录</el-button></template>
          <template v-else><RouterLink to="/login">登录</RouterLink><RouterLink to="/register">注册</RouterLink></template>
          <RouterLink to="/admin/tasks">管理后台</RouterLink>
        </template>
      </nav>
    </el-header>
    <el-main><RouterView :key="$route.fullPath" /></el-main>
    <el-footer>简云 · 云产品服务</el-footer>
  </el-container>
</template>
