<script setup lang="ts">
import { computed, reactive, watch } from "vue";
import { ElMessageBox } from "element-plus";
import { adminApi } from "../api/admin";
import { captchaFields, type CaptchaPolicy, type CaptchaScene, type CaptchaSettings } from "../api/captchaSettings";
import { useSettingsDraft } from "../composables/useSettingsDraft";
import { useAdmin } from "../stores/admin";
const props = defineProps<{ scene: CaptchaScene; label: string; impact: string; limit: string; initial: CaptchaPolicy }>();
const emit = defineEmits<{ change: [value: boolean] }>();
const account = useAdmin(), writable = computed(() => account.admin?.role === "super_admin");
const settings = reactive(useSettingsDraft<CaptchaPolicy>(captchaFields, {
  read: async () => ({ value: (await adminApi<CaptchaSettings>("/captcha-settings")).scenes[props.scene], options: undefined }),
  write: body => adminApi<{ saved: boolean }>("/captcha-settings/" + props.scene, { method: "PATCH", body }),
}));
settings.initialize(props.initial, undefined);
const draft = computed(() => settings.draft!);
watch(() => settings.dirty || settings.busy || settings.needsReview, value => emit("change", value), { immediate: true });
async function adopt(keep: boolean) {
  if (!writable.value || settings.busy || !settings.canAdopt) return;
  if (!keep && settings.dirty) {
    try { await ElMessageBox.confirm(`放弃${props.label}策略草稿，采用当前保存值？`, "核对验证码策略", { confirmButtonText: "采用当前值", cancelButtonText: "保留草稿" }); } catch { return; }
  }
  if (writable.value) settings.adopt(keep);
}
</script>
<template>
  <el-card class="captcha-scene" shadow="never" role="region" :aria-labelledby="`captcha-${scene}-title`">
    <template #header><div class="scene-heading"><h2 :id="`captcha-${scene}-title`">{{ label }}</h2><el-switch v-model="draft.enabled" :aria-label="`启用${label}验证码`" :disabled="!writable || settings.busy || settings.needsReview" /></div></template>
    <p class="scene-impact">{{ impact }}</p>
    <p class="saved-summary">已保存：{{ settings.saved?.enabled ? '开启' : '关闭' }} · {{ settings.saved?.configured ? '已配置' : '默认策略' }}</p>
    <el-alert v-if="settings.error" :title="settings.error" type="error" :closable="false" role="alert" />
    <el-alert v-if="settings.notice" :title="settings.notice" type="success" :closable="false" role="status" />
    <p v-if="settings.dirty" role="status">本场景有未保存内容。</p>
    <p class="muted">本地图片 · 始终验证</p>
    <div class="actions section-actions">
      <el-button type="primary" :loading="settings.saving" :disabled="!writable || settings.busy || settings.needsReview" @click="writable && settings.save()">保存{{ label }}策略</el-button>
      <el-button :loading="settings.checking" :disabled="!writable || settings.busy" @click="settings.check">核对{{ label }}结果</el-button>
    </div>
    <div v-if="settings.observed && settings.needsReview" class="reconciliation">
      <p>最近读到：{{ settings.observed.enabled ? '开启' : '关闭' }} · 本地图片 · 始终验证</p>
      <div class="actions"><el-button :disabled="!settings.canAdopt || settings.busy" @click="adopt(true)">保留草稿并采用当前版本</el-button><el-button :disabled="!settings.canAdopt || settings.busy" @click="adopt(false)">采用当前策略</el-button></div>
    </div>
    <p class="muted scene-limit">{{ limit }}</p>
  </el-card>
</template>
<style scoped>
.captcha-scene { min-width: 0; align-self: start; }
.captcha-scene h2 { margin: 0; }
.scene-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.scene-impact, .saved-summary, .scene-limit, .reconciliation { overflow-wrap: anywhere; }
.section-actions { margin: 12px 0; }
.reconciliation { border-top: 1px solid var(--el-border-color); margin-top: 12px; }
</style>
