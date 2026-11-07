<script setup lang="ts">
import { onMounted } from "vue";
import { useRouter } from "vue-router";
import { useUser } from "./stores/user";
const account = useUser();
const router = useRouter();
onMounted(() => account.load().catch(() => account.logout()));
function logout() {
  account.logout();
  void router.push("/");
}
</script>
<template>
  <el-container class="shell">
    <el-header class="topbar">
      <RouterLink class="brand" to="/">简云 <span>云产品</span></RouterLink>
      <nav aria-label="主导航">
        <RouterLink to="/">选购产品</RouterLink
        ><template v-if="account.user"
          >
<span
            >{{ account.user.email }} · 余额 ¥{{ account.user.balance }}</span
          ><el-button @click="logout">退出登录</el-button>
</template
        ><template v-else
          >
<RouterLink to="/login">登录</RouterLink
          ><RouterLink to="/register">注册</RouterLink>
</template
        >
      </nav>
    </el-header>
    <el-main><RouterView :key="$route.fullPath" /></el-main>
    <el-footer>简云 · 云产品服务</el-footer>
  </el-container>
</template>
