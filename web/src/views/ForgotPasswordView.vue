<script setup lang="ts">
import { ref } from "vue";
import CaptchaWidget from "../components/CaptchaWidget.vue";
import { api, message } from "../api/request";
const email = ref(""), busy = ref(false), error = ref(""), submitted = ref(false);
const captcha = ref<InstanceType<typeof CaptchaWidget>>();
async function submit() {
  if (busy.value) return;
  busy.value = true; error.value = "";
  try {
    await api("/auth/password/forgot", {method: "POST", body: {email: email.value, captcha_payload: captcha.value?.payload()}});
    submitted.value = true;
  } catch (e) { error.value = message(e); }
  finally { await captcha.value?.refresh(); busy.value = false; }
}
</script>
<template>
  <el-card class="auth-card">
    <h1>找回账户密码</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="submitted" title="若该邮箱符合找回条件，将收到有效期 15 分钟的重置邮件。请检查收件箱及垃圾箱；若未收到，可稍后重新申请或联系管理员。" type="success" :closable="false" />
    <el-form label-position="top" @submit.prevent="submit">
      <el-form-item label="账户邮箱"><el-input v-model="email" type="email" autocomplete="email" aria-label="账户邮箱" required maxlength="100" /></el-form-item>
      <CaptchaWidget ref="captcha" scene="password_reset" />
      <el-button native-type="submit" type="primary" :loading="busy">发送重置邮件</el-button>
    </el-form>
    <p><RouterLink to="/login">返回登录</RouterLink></p>
  </el-card>
</template>
