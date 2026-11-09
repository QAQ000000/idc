<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { onBeforeRouteLeave } from "vue-router";
import { ElMessageBox } from "element-plus";
import { useAdmin } from "../stores/admin";
import { adminApi } from "../api/admin";
import { message } from "../api/request";
import { paymentFields, type PaymentSettings } from "../api/paymentSettings";
import { useSettingsDraft } from "../composables/useSettingsDraft";

const account = useAdmin(), writable = computed(() => account.admin?.role === "super_admin");
const endpoint = "/payment-settings/epay";
const settings = reactive(useSettingsDraft<PaymentSettings>(paymentFields, {
  read: async () => ({ value: await adminApi<PaymentSettings>(endpoint), options: undefined }),
  write: body => adminApi<{ saved: boolean }>(endpoint, { method: "PATCH", body }),
  adopt: (next, draft) => ({ ...next, ...paymentFields(draft), ...(next.identity_locked ? { endpoint: next.endpoint, pid: next.pid } : {}) }),
}));
const ready = ref(false), loading = ref(false), loadError = ref("");
const keyAction = ref("keep"), secret = ref(""), secretNotice = ref(""), detailsOpen = ref(false), secretRegion = ref<HTMLElement>();
const draft = computed(() => settings.draft!), saved = computed(() => settings.saved!);
const busy = computed(() => settings.busy), needsReview = computed(() => settings.needsReview);
const locked = computed(() => !!saved.value?.identity_locked);
const changed = computed(() => settings.dirty || keyAction.value === "replace" || settings.busy || settings.needsReview);
let disposed = false, observer: IntersectionObserver | undefined;
function clearSecret(submitted = false) {
  if (keyAction.value === "replace") secretNotice.value = submitted ? "密钥输入已清除；本次请求按已提交内容核对。" : "未提交密钥已清除，后续保存默认保留原密钥。";
  secret.value = ""; keyAction.value = "keep";
}
function focusOut(event: FocusEvent) { if (!(event.relatedTarget instanceof Node) || !secretRegion.value?.contains(event.relatedTarget)) clearSecret(); }
function toggle(event: Event) { detailsOpen.value = (event.target as HTMLDetailsElement).open; if (!detailsOpen.value) clearSecret(); }
function hidden() { if (document.hidden) clearSecret(); }
function pageHide() { clearSecret(); }
function beforeUnload(event: BeforeUnloadEvent) { if (changed.value) { event.preventDefault(); event.returnValue = ""; } }
watch(writable, value => { if (!value) clearSecret(); }, { flush: "sync" });
watch(secretRegion, element => {
  observer?.disconnect();
  if (element) { observer = new IntersectionObserver(entries => { if (!entries.some(e => e.isIntersecting)) clearSecret(); }); observer.observe(element); }
}, { flush: "post" });
async function load() {
  if (!writable.value || loading.value || ready.value) return;
  loading.value = true; loadError.value = "";
  try { const data = await adminApi<PaymentSettings>(endpoint); if (!disposed) { settings.initialize(data, undefined); ready.value = true; } }
  catch (e) { if (!disposed) loadError.value = message(e); }
  finally { loading.value = false; }
}
async function save() {
  if (!writable.value || settings.busy || settings.needsReview || (keyAction.value === "replace" && (locked.value || !detailsOpen.value || !secret.value || document.hidden))) return;
  const request = settings.save({ key_action: keyAction.value, ...(keyAction.value === "replace" ? { key: secret.value } : {}) });
  clearSecret(true); await request;
}
async function adopt(keep: boolean) {
  if (settings.busy || !settings.canAdopt) return;
  if (!keep && settings.dirty) {
    try { await ElMessageBox.confirm("放弃本页非敏感草稿，采用当前保存值？", "核对支付配置", { confirmButtonText: "采用当前值", cancelButtonText: "保留草稿" }); } catch { return; }
  }
  if (!disposed) { clearSecret(); settings.adopt(keep); }
}
onBeforeRouteLeave(async () => {
  const warn = changed.value; clearSecret(); if (!warn) return true;
  try { await ElMessageBox.confirm("存在未保存草稿或待核对结果。离开不会撤销已发出请求，返回后请重新核对。", "离开支付配置？", { confirmButtonText: "离开页面", cancelButtonText: "留在页面", type: "warning" }); return true; } catch { return false; }
});
onMounted(() => { void load(); document.addEventListener("visibilitychange", hidden); window.addEventListener("pagehide", pageHide); window.addEventListener("beforeunload", beforeUnload); });
onUnmounted(() => { disposed = true; clearSecret(); observer?.disconnect(); document.removeEventListener("visibilitychange", hidden); window.removeEventListener("pagehide", pageHide); window.removeEventListener("beforeunload", beforeUnload); });
</script>
<template>
  <section class="admin-query-page payment-settings" aria-labelledby="payment-title">
    <div class="page-heading"><div><p class="eyebrow">超级管理员 · 单渠道</p><h1 id="payment-title">易支付配置</h1><p class="muted">停用仅关闭新收银；旧收银付款仍正常核销入账。</p></div></div>
    <el-alert v-if="!writable" title="仅超级管理员可查看和修改支付配置" type="warning" :closable="false" />
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" role="alert" />
    <el-button v-if="writable && !ready" :loading="loading" @click="load">重新加载配置</el-button>
    <el-card v-if="writable && ready && settings.saved && settings.draft" shadow="never">
      <template #header><h2>渠道与新收银</h2></template>
      <p class="saved-summary">上次确认：{{ saved.configured ? (saved.enabled ? '新收银已启用' : '新收银已停用') : '尚未配置' }} · 密钥{{ saved.key_configured ? '已配置' : '未配置' }} · 商户身份{{ locked ? '已锁定' : '尚可编辑' }}</p>
      <p v-if="settings.dirty || keyAction === 'replace'" role="status">有未保存内容。</p>
      <el-alert v-if="settings.error" :title="settings.error" type="error" :closable="false" role="alert" />
      <el-alert v-if="settings.notice" :title="settings.notice" type="success" :closable="false" role="status" />
      <el-alert v-if="secretNotice" :title="secretNotice" type="info" :closable="false" />
      <div class="actions section-actions">
        <el-button type="primary" :loading="settings.saving" :disabled="settings.busy || settings.needsReview || keyAction === 'replace' || !saved.key_configured" @click="save">保存支付配置</el-button>
        <el-button :loading="settings.checking" :disabled="settings.busy" @click="settings.check">核对保存结果</el-button>
      </div>
      <p v-if="!saved.key_configured" class="muted">首次配置请展开详细参数，在密钥编辑区确认保存。</p>
      <div v-if="settings.observed && settings.needsReview" class="reconciliation">
        <h3>最近读到的配置</h3>
        <dl><dt>新收银</dt><dd>{{ settings.observed.enabled ? '开启' : '停用' }}</dd><dt>网关／商户号</dt><dd>{{ settings.observed.endpoint }}／{{ settings.observed.pid }}</dd><dt>付款方式</dt><dd>{{ settings.observed.type }}</dd><dt>通知地址</dt><dd>{{ settings.observed.notify_url }}</dd><dt>返回地址</dt><dd>{{ settings.observed.return_url }}</dd><dt>密钥与身份</dt><dd>{{ settings.observed.key_configured ? '已配置（不证明本次替换）' : '未配置' }} · {{ settings.observed.identity_locked ? '商户身份已锁定' : '尚可编辑' }}</dd></dl>
        <div class="actions"><el-button :disabled="!settings.canAdopt || settings.busy" @click="adopt(true)">保留草稿并采用当前版本</el-button><el-button :disabled="!settings.canAdopt || settings.busy" @click="adopt(false)">采用当前配置</el-button></div>
        <p class="muted">商户已锁定时，保留草稿也会采用服务器的网关和商户号；密钥不会自动重提。</p>
      </div>
      <el-form label-position="top" :disabled="settings.busy || settings.needsReview" @submit.prevent="save">
        <el-form-item label="允许创建新收银"><el-switch v-model="draft.enabled" aria-label="允许创建新收银" /></el-form-item>
        <p class="muted">已创建的收银台可能在停用后继续付款，不能据此视为无效。余额支付不受影响。</p>
        <details @toggle="toggle">
          <summary>网关、商户与本站详细参数</summary>
          <el-alert v-if="locked" title="已有支付尝试：网关地址、商户号和密钥已锁定，不能替换。" type="info" :closable="false" />
          <div class="settings-grid">
            <el-form-item label="网关地址"><el-input v-model="draft.endpoint" aria-label="网关地址" type="url" :disabled="locked" maxlength="255" placeholder="https://pay.example" /></el-form-item>
            <el-form-item label="商户号 PID"><el-input v-model="draft.pid" aria-label="商户号 PID" :disabled="locked" maxlength="100" /></el-form-item>
            <el-form-item label="付款方式"><el-select v-model="draft.type" aria-label="付款方式"><el-option label="支付宝" value="alipay" /><el-option label="微信支付" value="wxpay" /><el-option label="QQ钱包" value="qqpay" /></el-select></el-form-item>
            <el-form-item label="异步通知地址"><el-input v-model="draft.notify_url" aria-label="异步通知地址" type="url" maxlength="255" placeholder="https://shop.example/api/v1/payments/epay/notify" /></el-form-item>
            <el-form-item label="本站返回地址（站点根地址）"><el-input v-model="draft.return_url" aria-label="本站返回地址" type="url" maxlength="255" placeholder="https://shop.example" /></el-form-item>
          </div>
          <div ref="secretRegion" class="secret-editor" @focusout="focusOut">
            <p>商户密钥：{{ saved.key_configured ? '已配置，不回显' : '未配置' }}。</p>
            <el-form-item v-if="!locked" label="密钥处理"><el-radio-group v-model="keyAction" aria-label="密钥处理" @change="secret = ''; secretNotice = ''"><el-radio value="keep">保留现有密钥</el-radio><el-radio value="replace">显式设置密钥</el-radio></el-radio-group></el-form-item>
            <template v-if="!locked && keyAction === 'replace'"><el-form-item label="新商户密钥"><el-input v-model="secret" aria-label="新商户密钥" type="password" autocomplete="new-password" maxlength="4096" /></el-form-item><el-button native-type="submit" type="primary" :disabled="!secret || busy || needsReview">保存配置并设置密钥</el-button><p class="muted">离开本编辑区即清除未提交密钥。</p></template>
          </div>
        </details>
      </el-form>
    </el-card>
  </section>
</template>
<style scoped>
.payment-settings h2 { margin: 0; font-size: 18px; }
.payment-settings p, .payment-settings dd { overflow-wrap: anywhere; }
.payment-settings :deep(.el-select) { width: 100%; }
.saved-summary { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; margin-top: 0; }
.section-actions { margin-block: 16px; }
.settings-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; }
summary { cursor: pointer; font-weight: 600; padding: 12px 0; }
.secret-editor, .reconciliation { padding: 12px; border: 1px solid var(--el-border-color); border-radius: 8px; margin-block: 12px; }
.secret-editor :deep(.el-radio-group) { gap: 8px; flex-wrap: wrap; }
.secret-editor :deep(.el-radio) { margin-right: 0; }
@media (max-width: 767px) {
  .settings-grid { grid-template-columns: minmax(0, 1fr); }
  .section-actions, .reconciliation .actions { display: grid; grid-template-columns: minmax(0, 1fr); }
  .payment-settings :deep(.el-button) { margin-left: 0; max-width: 100%; height: auto; min-height: 32px; white-space: normal; }
}
</style>
