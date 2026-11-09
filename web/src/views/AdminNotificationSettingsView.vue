<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { onBeforeRouteLeave, useRoute } from "vue-router";
import { ElMessageBox } from "element-plus";
import { useAdmin } from "../stores/admin";
import { adminApi } from "../api/admin";
import type { MailSettings, NotificationSettings, TicketSubscription } from "../api/notificationSettings";
import { message } from "../api/request";
import { useNotificationDraft } from "../composables/useNotificationDraft";
const account = useAdmin(), route = useRoute(), writable = computed(() => account.admin?.role === "super_admin");
const activeTab = ref(route.query.section === "tickets" ? "tickets" : "mail");
const loading = ref(false), loadError = ref(""), ready = ref(false), ticketConfirming = ref(false);
const mailFields = (m: MailSettings) => ({ enabled: m.enabled, task_alerts: m.task_alerts, due_reminders: m.due_reminders, site_url: m.site_url.replace(/\/$/, ""), subject_prefix: m.subject_prefix,
  admin_ids: [...m.admin_ids].sort((a, b) => a - b), config: { host: m.config.host, port: m.config.port, security: m.config.security, username: m.config.username, from: m.config.from, from_name: m.config.from_name } });
const ticketFields = (t: TicketSubscription) => ({ enabled: t.enabled, admin_ids: [...t.admin_ids].sort((a, b) => a - b) });
const mail = reactive(useNotificationDraft<MailSettings>("mail", mailFields));
const tickets = reactive(useNotificationDraft<TicketSubscription>("tickets", ticketFields));
const passwordAction = ref("keep"), password = ref(""), secretNotice = ref(""), smtpOpen = ref(false), secretRegion = ref<HTMLElement>();
let disposed = false, secretObserver: IntersectionObserver | undefined;
const mailDirty = computed(() => mail.dirty || passwordAction.value === "replace");
const hasChanges = computed(() => mailDirty.value || tickets.dirty || mail.busy || tickets.busy || mail.needsReview || tickets.needsReview || ticketConfirming.value);
function clearSecret(submitted = false) {
  if (passwordAction.value === "replace") secretNotice.value = submitted ? "密钥输入已清除；本次请求按已提交内容处理。" : "未提交的密钥替换已清除；再次保存默认保留现有密钥。";
  password.value = ""; passwordAction.value = "keep";
}
watch(activeTab, () => clearSecret(), { flush: "sync" });
watch(writable, value => { if (!value) clearSecret(); }, { flush: "sync" });
watch(secretRegion, element => {
  secretObserver?.disconnect();
  if (element) { secretObserver = new IntersectionObserver(entries => { if (!entries.some(e => e.isIntersecting)) clearSecret(); }); secretObserver.observe(element); }
}, { flush: "post" });
function secretFocusOut(event: FocusEvent) { if (!(event.relatedTarget instanceof Node) || !secretRegion.value?.contains(event.relatedTarget)) clearSecret(); }
function smtpToggle(event: Event) { smtpOpen.value = (event.target as HTMLDetailsElement).open; if (!smtpOpen.value) clearSecret(); }
function onHidden() { if (document.hidden) clearSecret(); }
function onPageHide() { clearSecret(); }
function beforeUnload(event: BeforeUnloadEvent) { if (hasChanges.value) { event.preventDefault(); event.returnValue = ""; } }
async function load() {
  if (!writable.value || loading.value || ready.value) return;
  loading.value = true; loadError.value = "";
  try {
    const data = await adminApi<NotificationSettings>("/notification-settings");
    if (disposed) return;
    mail.initialize(data.mail, data.recipients); tickets.initialize(data.tickets, data.recipients); ready.value = true;
  } catch (e) { if (!disposed) loadError.value = message(e); }
  finally { loading.value = false; }
}
async function saveMail() {
  if (!writable.value || mail.busy || mail.needsReview || (passwordAction.value === "replace" && (!password.value || !smtpOpen.value || activeTab.value !== "mail" || document.hidden))) return;
  const saving = mail.save({ password_action: passwordAction.value, ...(passwordAction.value === "replace" ? { password: password.value } : {}) });
  clearSecret(true); await saving;
}
async function saveTickets() {
  if (!writable.value || tickets.busy || tickets.needsReview || ticketConfirming.value) return;
  ticketConfirming.value = true;
  try {
    await ElMessageBox.confirm("保存会切换工单订阅起点，使旧订阅尚未认领的排队消息失效；不会补发历史消息。已经发送或正在发送的邮件无法撤回。是否保存？", "单独保存工单订阅", { confirmButtonText: "确认切换订阅期", cancelButtonText: "取消", type: "warning" });
    if (!disposed) await tickets.save({ confirm_new_period: true });
  } catch { /* Cancellation never submits. */ }
  finally { ticketConfirming.value = false; }
}
async function adopt(section: "mail" | "tickets", keep: boolean) {
  const model = section === "mail" ? mail : tickets;
  if (model.busy || !model.canAdopt) return;
  if (!keep && (section === "mail" ? mailDirty.value : tickets.dirty)) {
    try { await ElMessageBox.confirm("仅放弃本区未保存草稿并采用当前保存值，另一分区保持原状。", "放弃本区草稿？", { confirmButtonText: "放弃并采用当前值", cancelButtonText: "取消", type: "warning" }); } catch { return; }
  }
  if (disposed) return;
  if (section === "mail") clearSecret();
  model.adopt(keep);
}
onBeforeRouteLeave(async () => {
  const warn = hasChanges.value; clearSecret();
  if (ticketConfirming.value) { ElMessageBox.close(); return false; }
  if (!warn) return true;
  try { await ElMessageBox.confirm("存在未保存草稿、提交中或待核对的结果。离开将丢失页内草稿；已发出请求不会撤销，返回后须重新核对。", "确认离开通知配置？", { confirmButtonText: "离开页面", cancelButtonText: "留在页面", type: "warning" }); return true; } catch { return false; }
});
onMounted(() => { void load(); document.addEventListener("visibilitychange", onHidden); window.addEventListener("pagehide", onPageHide); window.addEventListener("beforeunload", beforeUnload); });
onUnmounted(() => { disposed = true; clearSecret(); secretObserver?.disconnect(); document.removeEventListener("visibilitychange", onHidden); window.removeEventListener("pagehide", onPageHide); window.removeEventListener("beforeunload", beforeUnload); if (ticketConfirming.value) ElMessageBox.close(); });
</script>
<template>
  <section class="admin-query-page notification-settings" aria-labelledby="notifications-title">
    <div class="page-heading"><div><p class="eyebrow">超级管理员 · 通知设置</p><h1 id="notifications-title">邮件与通知</h1><p class="muted">两区独立保存；保存配置不会发送测试邮件。</p></div></div>
    <el-alert v-if="!writable" title="仅超级管理员可查看和修改邮件配置" type="warning" :closable="false" />
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" role="alert" />
    <el-button v-if="writable && !ready" :loading="loading" @click="load">重新加载配置</el-button>
    <template v-if="writable && ready">
      <p v-if="mailDirty || tickets.dirty" class="draft-summary" role="status">未保存：{{ [mailDirty ? '邮件配置' : '', tickets.dirty ? '工单订阅' : ''].filter(Boolean).join('、') }}。切分区保留非敏感草稿。</p>
      <el-alert v-if="mail.needsReview" title="邮件配置保存结果待核对，请进入邮件配置区处理。" type="warning" :closable="false" />
      <el-alert v-if="tickets.needsReview" title="工单订阅保存结果待核对，不会自动再次切换订阅期。" type="warning" :closable="false" />
      <el-tabs v-model="activeTab" aria-label="通知配置分区">
        <el-tab-pane label="邮件配置" name="mail">
          <el-card v-if="mail.draft && mail.saved" shadow="never" class="mail-section">
            <template #header><h2>邮件配置</h2></template>
            <p class="saved-summary" :title="mail.saved.config.host">上次确认：邮件{{ mail.saved.enabled ? '开启' : '关闭' }} · {{ mail.saved.config.host || 'SMTP 尚未配置' }} · 密钥{{ mail.saved.password_configured ? '已配置' : '未配置' }}</p>
            <el-alert v-if="mail.error" :title="mail.error" type="error" :closable="false" role="alert" />
            <el-alert v-if="mail.notice" :title="mail.notice" type="success" :closable="false" role="status" />
            <el-alert v-if="secretNotice" :title="secretNotice" type="info" :closable="false" />
            <div class="section-actions actions">
              <el-button type="primary" :loading="mail.saving" :disabled="mail.busy || mail.needsReview || passwordAction === 'replace'" @click="saveMail">保存邮件配置</el-button>
              <el-button :loading="mail.checking" :disabled="mail.busy" @click="mail.check">核对邮件保存结果</el-button>
            </div>
            <p v-if="passwordAction === 'replace'" class="muted">密钥替换请在编辑区域内确认保存。</p>
            <div v-if="mail.observed && mail.needsReview" class="reconciliation">
              <details open><summary>最近读到的邮件配置</summary><dl><dt>邮件／任务告警／到期提醒</dt><dd>{{ mail.observed.enabled ? '开启' : '关闭' }}／{{ mail.observed.task_alerts ? '开启' : '关闭' }}／{{ mail.observed.due_reminders ? '开启' : '关闭' }}</dd><dt>SMTP</dt><dd>{{ mail.observed.config.host }} : {{ mail.observed.config.port }} · {{ mail.observed.config.security }} · {{ mail.observed.config.username }}</dd><dt>发件人</dt><dd>{{ mail.observed.config.from_name }} · {{ mail.observed.config.from }}</dd><dt>本站地址／主题前缀</dt><dd>{{ mail.observed.site_url }}／{{ mail.observed.subject_prefix }}</dd><dt>任务告警收件人ID</dt><dd>{{ mail.observed.admin_ids.join('、') || '无' }}</dd><dt>密钥状态</dt><dd>{{ mail.observed.password_configured ? '已配置（不证明本次替换）' : '未配置' }}</dd></dl></details>
              <div class="actions"><el-button :disabled="!mail.canAdopt || mail.busy" @click="adopt('mail', true)">已核对，保留邮件草稿并采用当前版本</el-button><el-button :disabled="!mail.canAdopt || mail.busy" @click="adopt('mail', false)">采用当前邮件配置</el-button></div>
            </div>
            <el-form label-position="top" :disabled="mail.busy || mail.needsReview" @submit.prevent="saveMail">
              <div class="switch-grid"><el-form-item label="邮件总开关"><el-switch v-model="mail.draft.enabled" aria-label="邮件总开关" /></el-form-item><el-form-item label="任务人工核验告警"><el-switch v-model="mail.draft.task_alerts" aria-label="任务人工核验告警" /></el-form-item><el-form-item label="客户到期提醒"><el-switch v-model="mail.draft.due_reminders" aria-label="客户到期提醒" /></el-form-item></div>
              <el-form-item label="任务告警管理员收件人"><el-select v-model="mail.draft.admin_ids" multiple aria-label="任务告警管理员收件人"><el-option v-for="a in mail.recipients" :key="a.id" :value="a.id" :label="`${a.username} · ${a.email} (#${a.id})`" /></el-select></el-form-item>
              <p class="muted">总开关控制新通知占位和发送认领，找回密码仍由客户主动申请；已在发送中的邮件无法撤回。任务／到期扫描可为仍满足条件的来源占位，到期提醒覆盖未来7天。</p>
              <details class="smtp-details" @toggle="smtpToggle">
                <summary>SMTP 与邮件详细参数</summary>
                <div class="settings-grid">
                  <el-form-item label="SMTP 主机"><el-input v-model="mail.draft.config.host" aria-label="SMTP 主机" maxlength="253" /></el-form-item>
                  <el-form-item label="SMTP 端口"><el-input-number v-model="mail.draft.config.port" aria-label="SMTP 端口" :min="1" :max="65535" /></el-form-item>
                  <el-form-item label="加密方式"><el-select v-model="mail.draft.config.security" aria-label="加密方式"><el-option label="SSL / TLS（SMTPS）" value="smtps" /><el-option label="STARTTLS" value="starttls" /></el-select></el-form-item>
                  <el-form-item label="SMTP 用户名"><el-input v-model="mail.draft.config.username" aria-label="SMTP 用户名" maxlength="255" autocomplete="off" /></el-form-item>
                  <el-form-item label="发件邮箱"><el-input v-model="mail.draft.config.from" aria-label="发件邮箱" type="email" maxlength="100" /></el-form-item>
                  <el-form-item label="发件名称"><el-input v-model="mail.draft.config.from_name" aria-label="发件名称" maxlength="100" /></el-form-item>
                  <el-form-item label="本站地址（仅 origin）"><el-input v-model="mail.draft!.site_url" aria-label="本站地址" type="url" placeholder="https://shop.example" maxlength="255" /></el-form-item>
                  <el-form-item label="邮件主题前缀"><el-input v-model="mail.draft!.subject_prefix" aria-label="邮件主题前缀" maxlength="40" /></el-form-item>
                </div>
                <div ref="secretRegion" class="secret-editor" @focusout="secretFocusOut">
                  <p>SMTP密钥：{{ mail.saved!.password_configured ? '已配置' : '未配置' }}，不回显。</p>
                  <el-form-item label="密钥处理"><el-radio-group v-model="passwordAction" aria-label="密钥处理" @change="password = ''; secretNotice = ''"><el-radio value="keep">保留现有密钥</el-radio><el-radio value="replace">显式替换密钥</el-radio></el-radio-group></el-form-item>
                  <template v-if="passwordAction === 'replace'"><el-form-item label="新 SMTP 密钥"><el-input v-model="password" aria-label="新 SMTP 密钥" type="password" maxlength="4096" autocomplete="new-password" /></el-form-item><el-button native-type="submit" type="primary" :disabled="!password || mail.busy || mail.needsReview">保存邮件配置并替换密钥</el-button><p class="muted">仅在本区域保留输入；离开焦点／视口、切区或进入后台即清除。</p></template>
                </div>
              </details>
            </el-form>
          </el-card>
        </el-tab-pane>
        <el-tab-pane label="工单订阅" name="tickets">
          <el-card v-if="tickets.draft && tickets.saved" shadow="never" class="ticket-section">
            <template #header><h2>工单订阅</h2></template>
            <p class="saved-summary">上次确认订阅起点（UTC）：{{ tickets.saved.activated_at || '尚未建立' }}</p>
            <el-alert v-if="tickets.error" :title="tickets.error" type="error" :closable="false" role="alert" />
            <el-alert v-if="tickets.notice" :title="tickets.notice" type="success" :closable="false" role="status" />
            <div class="section-actions actions"><el-button type="primary" :loading="tickets.saving || ticketConfirming" :disabled="tickets.busy || tickets.needsReview || ticketConfirming" @click="saveTickets">单独保存工单订阅</el-button><el-button :loading="tickets.checking" :disabled="tickets.busy || ticketConfirming" @click="tickets.check">核对工单保存结果</el-button></div>
            <div v-if="tickets.observed && tickets.needsReview" class="reconciliation"><p>最近读到：{{ tickets.observed.enabled ? '开启' : '关闭' }}；收件人ID：{{ tickets.observed.admin_ids.join('、') || '无' }}；起点（UTC）：{{ tickets.observed.activated_at || '尚未建立' }}</p><div class="actions"><el-button :disabled="!tickets.canAdopt || tickets.busy" @click="adopt('tickets', true)">已核对，保留工单草稿并采用当前版本</el-button><el-button :disabled="!tickets.canAdopt || tickets.busy" @click="adopt('tickets', false)">采用当前工单订阅</el-button></div></div>
            <el-alert title="每次保存都切换订阅期；旧排队消息失效，历史消息不补发" type="warning" :closable="false" />
            <el-form label-position="top" :disabled="tickets.busy || tickets.needsReview || ticketConfirming" @submit.prevent="saveTickets">
              <el-form-item label="工单通知"><el-switch v-model="tickets.draft.enabled" aria-label="工单通知" /></el-form-item>
              <el-form-item label="工单管理员收件人"><el-select v-model="tickets.draft.admin_ids" multiple aria-label="工单管理员收件人"><el-option v-for="a in tickets.recipients" :key="a.id" :value="a.id" :label="`${a.username} · ${a.email} (#${a.id})`" /></el-select></el-form-item>
            </el-form>
            <p class="muted">新工单／客户追加通知指定管理员，管理员公开回复通知原客户；结单不发普通回复邮件。启用前须先保存并开启邮件总开关。这里只保存工单订阅，不保存邮件草稿；未认领旧消息失效，已发送／正在发送无法撤回。</p>
          </el-card>
        </el-tab-pane>
      </el-tabs>
    </template>
  </section>
</template>
<style scoped>
.notification-settings :deep(.el-select) { width: 100%; }
.notification-settings :deep(.el-tabs__content) { min-width: 0; }
.notification-settings p, .notification-settings dd { overflow-wrap: anywhere; }
.notification-settings h2 { margin: 0; font-size: 18px; }
.settings-grid, .switch-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; }
.switch-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.smtp-details > summary { cursor: pointer; font-weight: 600; padding: 12px 0; }
.saved-summary { margin-top: 0; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; }
.section-actions { margin-bottom: 16px; }
.secret-editor, .reconciliation { padding: 12px; border: 1px solid var(--el-border-color); border-radius: 8px; margin-block: 12px; }
.secret-editor :deep(.el-radio-group) { gap: 8px; flex-wrap: wrap; }
.secret-editor :deep(.el-radio) { margin-right: 0; }
@media (max-width: 767px) {
  .settings-grid { grid-template-columns: minmax(0, 1fr); }
  .switch-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .section-actions, .reconciliation .actions { display: grid; grid-template-columns: minmax(0, 1fr); }
  .section-actions :deep(.el-button), .reconciliation :deep(.el-button), .secret-editor :deep(.el-button) { margin-left: 0; max-width: 100%; height: auto; min-height: 32px; white-space: normal; }
}
</style>
