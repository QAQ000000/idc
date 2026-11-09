<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import CaptchaWidget from "../components/CaptchaWidget.vue";
import { adminApi, type Admin } from "../api/admin";
import { message } from "../api/request";
import { useAdmin } from "../stores/admin";
const username = ref(""), password = ref(""), error = ref(""), busy = ref(false);
const captcha = ref<InstanceType<typeof CaptchaWidget>>();
const account = useAdmin(), route = useRoute(), router = useRouter();
async function submit() {
  if (busy.value) return;
  busy.value = true; error.value = "";
  try {
    const result = await adminApi<{ token: string; admin: Admin }>("/auth/login", {
      method: "POST", body: { username: username.value, password: password.value, captcha_payload: captcha.value?.payload() },
    });
    account.establish(result.token, result.admin); password.value = "";
    const target = route.query.redirect;
    await router.push(typeof target === "string" && /^\/admin\/(?:notifications|providers(?:\/(?:\d+|new))?|customers(?:\/\d+)?|instances(?:\/\d+)?|tickets(?:\/\d+)?|tasks(?:\/\d+)?|finance(?:\/invoices\/\d+)?|products(?:\/(?:\d+|import))?)(?:\?.*)?$/.test(target) ? target : "/admin/tasks");
  } catch (e) { error.value = message(e); await captcha.value?.refresh(); }
  finally { busy.value = false; }
}
</script>
<template>
  <el-card class="auth-card">
    <h1>管理员登录</h1>
    <p class="muted">使用独立管理员账号；客户账号无法进入后台。</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-form label-position="top" @submit.prevent="submit">
      <el-form-item label="管理员用户名"><el-input v-model="username" aria-label="管理员用户名" autocomplete="username" maxlength="50" required /></el-form-item>
      <el-form-item label="管理员密码"><el-input v-model="password" aria-label="管理员密码" type="password" autocomplete="current-password" maxlength="72" required show-password /></el-form-item>
      <CaptchaWidget ref="captcha" scene="login" />
      <el-button native-type="submit" type="primary" :loading="busy">登录管理后台</el-button>
    </el-form>
    <p><RouterLink to="/login">返回客户登录</RouterLink></p>
  </el-card>
</template>
