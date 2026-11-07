<script setup lang="ts">
import { onMounted, ref } from "vue";
import { api, message, type Captcha } from "../api/request";
const props = defineProps<{ scene: "login" | "register" }>();
const config = ref<Captcha | null>(null);
const answer = ref("");
const error = ref("");
const loading = ref(false);
async function refresh() {
  loading.value = true;
  error.value = "";
  config.value = null;
  answer.value = "";
  try {
    config.value = await api<Captcha>("/captcha/config?scene=" + props.scene);
  } catch (e) {
    error.value = message(e);
  } finally {
    loading.value = false;
  }
}
function payload() {
  if (
    !config.value ||
    (config.value.enabled &&
      (config.value.type !== "image" || !answer.value.trim()))
  )
    throw new Error("请完成验证码");
  return config.value.enabled
    ? { challenge_id: config.value.challenge_id, answer: answer.value }
    : {};
}
defineExpose({ refresh, payload });
onMounted(refresh);
</script>
<template>
  <div v-loading="loading" class="captcha">
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <template v-if="config?.enabled && config.type === 'image'">
      <el-form-item label="图片验证码"
        >
<el-input
          v-model="answer"
          aria-label="图片验证码"
          autocomplete="off"
          maxlength="20" /><button
          type="button"
          class="captcha-image"
          aria-label="刷新验证码"
          @click="refresh"
        >
          <img :src="config.img_base64" alt="图片验证码，点击刷新">
</button
      >
</el-form-item>
    </template>
    <el-alert
      v-else-if="config?.enabled"
      title="当前验证码暂不可用，请联系管理员"
      type="warning"
      :closable="false"
    />
    <el-button v-if="error" @click="refresh">重新获取验证码</el-button>
  </div>
</template>
