<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api, message } from "../api/request";
import { useUser } from "../stores/user";
const props = defineProps<{reset?: boolean}>();
const account = useUser(), router = useRouter(), route = useRoute();
const token = ref("");
const current = ref(""), password = ref(""), confirm = ref(""), error = ref(""), busy = ref(false), done = ref(false);
const policy = document.createElement("meta"); policy.name = "referrer"; policy.content = "no-referrer";
watch(() => [props.reset, route.fullPath] as const, () => {
  if (!props.reset) { policy.remove(); token.value = ""; done.value = false; return; }
  if (!policy.isConnected) document.head.append(policy);
  const incoming = new URLSearchParams(route.hash.slice(1)).get("token");
  if (!incoming) return;
  token.value = incoming; done.value = false; error.value = "";
  current.value = ""; password.value = ""; confirm.value = "";
  history.replaceState(history.state, "", location.pathname);
  void router.replace({path: route.path});
}, {immediate: true});
onBeforeUnmount(() => policy.remove());
const title = computed(() => props.reset ? "重置账户登录密码" : "修改账户登录密码");
async function submit() {
  if (busy.value || done.value) return;
  const bytes = new TextEncoder().encode(password.value).length;
  if (bytes < 12 || bytes > 72) { error.value = "新密码须为 12–72 字节"; return; }
  if (password.value !== confirm.value) { error.value = "两次输入的新密码不一致"; return; }
  busy.value = true; error.value = "";
  try {
    await api(props.reset ? "/auth/password/reset" : "/auth/password", {method: "POST", body: props.reset ? {token: token.value, new_password: password.value} : {current_password: current.value, new_password: password.value}});
    token.value = ""; current.value = ""; password.value = ""; confirm.value = ""; done.value = true; account.logout();
  } catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
onBeforeUnmount(() => { token.value = ""; current.value = ""; password.value = ""; confirm.value = ""; });
</script>
<template>
  <el-card class="auth-card">
    <h1>{{ title }}</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="done" title="账户密码已更新，旧登录和旧重置链接已失效，请重新登录。" type="success" :closable="false" />
    <template v-else-if="reset && !token"><el-alert title="重置链接缺失或已清除，请重新打开邮件中的链接，或重新申请。" type="warning" :closable="false" /><RouterLink to="/forgot-password">重新申请</RouterLink></template>
    <el-form v-else label-position="top" @submit.prevent="submit">
      <el-form-item v-if="!reset" label="当前账户密码"><el-input v-model="current" type="password" aria-label="当前账户密码" autocomplete="current-password" show-password required maxlength="72" /></el-form-item>
      <el-form-item label="新账户密码"><el-input v-model="password" type="password" aria-label="新账户密码" autocomplete="new-password" show-password required minlength="12" maxlength="72" /></el-form-item>
      <el-form-item label="确认新密码"><el-input v-model="confirm" type="password" aria-label="确认新密码" autocomplete="new-password" show-password required minlength="12" maxlength="72" /></el-form-item>
      <el-button native-type="submit" type="primary" :loading="busy">确认{{ reset ? '重置' : '修改' }}</el-button>
      <p v-if="reset"><RouterLink to="/forgot-password">链接过期或已使用？重新申请</RouterLink></p>
    </el-form>
    <p><el-button @click="router.push('/login')">返回登录</el-button></p>
  </el-card>
</template>
