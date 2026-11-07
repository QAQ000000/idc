<script setup lang="ts">
import { computed, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import CaptchaWidget from "../components/CaptchaWidget.vue";
import { api, message, type User } from "../api/request";
import { useUser } from "../stores/user";
const props = defineProps<{ scene: "login" | "register" }>();
const title = computed(() =>
  props.scene === "login" ? "登录账户" : "注册账户",
);
const email = ref(""),
  password = ref(""),
  error = ref(""),
  busy = ref(false);
const captcha = ref<InstanceType<typeof CaptchaWidget>>();
const account = useUser(),
  router = useRouter(),
  route = useRoute();
async function submit() {
  if (busy.value) return;
  error.value = "";
  busy.value = true;
  try {
    const result = await api<{ token: string; user: User }>(
      "/auth/" + props.scene,
      {
        method: "POST",
        body: {
          email: email.value,
          password: password.value,
          captcha_payload: captcha.value?.payload(),
        },
      },
    );
    account.establish(result.token, result.user);
    password.value = "";
    const target =
      typeof route.query.redirect === "string" ? route.query.redirect : "/";
    await router.push(
      target.startsWith("/") && !target.startsWith("//") ? target : "/",
    );
  } catch (e) {
    error.value = message(e);
    await captcha.value?.refresh();
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <el-card class="auth-card">
    <h1>{{ title }}</h1>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-form label-position="top" @submit.prevent="submit"
      >
<el-form-item label="邮箱"
        >
<el-input
          v-model="email"
          type="email"
          aria-label="邮箱"
          autocomplete="username"
          required
          maxlength="100" />
</el-form-item
      ><el-form-item label="密码"
        >
<el-input
          v-model="password"
          type="password"
          aria-label="密码"
          :autocomplete="
            scene === 'login' ? 'current-password' : 'new-password'
          "
          show-password
          required
          :minlength="scene === 'register' ? 12 : 1"
          maxlength="72" />
</el-form-item
      ><CaptchaWidget ref="captcha" :scene="scene" /><el-button
        type="primary"
        native-type="submit"
        :loading="busy"
        >
{{ title }}
</el-button
      >
</el-form
    >
    <p>
      <RouterLink
        v-if="scene === 'login'"
        :to="{ path: '/register', query: route.query }"
        >还没有账户？注册</RouterLink
      ><RouterLink v-else :to="{ path: '/login', query: route.query }"
        >已有账户？登录</RouterLink
      >
    </p>
  </el-card>
</template>
