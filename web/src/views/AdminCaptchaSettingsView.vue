<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { onBeforeRouteLeave } from "vue-router";
import { ElMessageBox } from "element-plus";
import CaptchaSceneSettings from "../components/CaptchaSceneSettings.vue";
import { captchaScenes, type CaptchaSettings, type CaptchaScene } from "../api/captchaSettings";
import { adminApi } from "../api/admin";
import { message } from "../api/request";
import { useAdmin } from "../stores/admin";
const account = useAdmin(), writable = computed(() => account.admin?.role === "super_admin");
const data = ref<CaptchaSettings>(), loading = ref(false), error = ref("");
const changes = reactive<Record<CaptchaScene, boolean>>({ login: false, register: false, password_reset: false });
const changed = computed(() => Object.values(changes).some(Boolean));
let disposed = false;
async function load() {
  if (!writable.value || loading.value || data.value) return;
  loading.value = true; error.value = "";
  try {
    const next = await adminApi<CaptchaSettings>("/captcha-settings");
    for (const { id } of captchaScenes) {
      const policy = next?.scenes?.[id];
      if (typeof policy?.enabled !== "boolean" || policy.driver !== "builtin_image" || policy.mode !== "always" || typeof policy.version !== "string" || policy.version.length !== 64) throw new Error("未取得可信验证码配置");
    }
    if (!disposed && writable.value) data.value = next;
  } catch (e) { if (!disposed) error.value = message(e); }
  finally { loading.value = false; }
}
function beforeUnload(event: BeforeUnloadEvent) { if (changed.value) { event.preventDefault(); event.returnValue = ""; } }
watch(writable, value => { if (value) void load(); });
onBeforeRouteLeave(async () => {
  if (!changed.value) return true;
  try { await ElMessageBox.confirm("存在未保存策略或待核对结果。离开不会撤销已发出请求，返回后请重新核对。", "离开验证码策略？", { confirmButtonText: "离开页面", cancelButtonText: "留在页面", type: "warning" }); return true; } catch { return false; }
});
onMounted(() => { void load(); window.addEventListener("beforeunload", beforeUnload); });
onUnmounted(() => { disposed = true; window.removeEventListener("beforeunload", beforeUnload); });
</script>
<template>
  <section class="admin-query-page captcha-settings" aria-labelledby="captcha-title">
    <div class="page-heading"><div><p class="eyebrow">超级管理员 · 登录安全</p><h1 id="captcha-title">验证码策略</h1><p class="muted">三个场景分别保存；首批使用内置图片与始终验证模式。</p></div></div>
    <el-alert v-if="!writable" title="仅超级管理员可查看和修改验证码策略" type="warning" :closable="false" />
    <template v-else>
      <el-alert title="登录策略同时影响客户和管理员，关闭后仍受限流保护。" type="warning" :closable="false" />
      <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
      <el-button v-if="!data" :loading="loading" @click="load">重新加载策略</el-button>
      <div v-if="data" class="scenes-grid">
        <CaptchaSceneSettings v-for="scene in captchaScenes" :key="scene.id" :scene="scene.id" :label="scene.label" :impact="scene.impact" :limit="scene.limit" :initial="data.scenes[scene.id]" @change="changes[scene.id] = $event" />
      </div>
      <p class="muted">配置或验证依赖异常不会自动放行。</p>
    </template>
  </section>
</template>
<style scoped>
.scenes-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 16px; }
@media (max-width: 1200px) { .scenes-grid { grid-template-columns: minmax(0, 1fr); } }
</style>
