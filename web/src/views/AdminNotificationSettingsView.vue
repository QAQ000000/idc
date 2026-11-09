<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { ElMessageBox } from "element-plus";
import { useAdmin } from "../stores/admin";
import { adminApi } from "../api/admin";
import type { NotificationSettings } from "../api/notificationSettings";
import { message } from "../api/request";
const account = useAdmin(), writable = computed(() => account.admin?.role === "super_admin");
const data = ref<NotificationSettings>(), loading = ref(false), busy = ref(false), error = ref(""), success = ref("");
const passwordAction = ref("keep"), password = ref("");
function clearSecret() { password.value = ""; passwordAction.value = "keep"; }
async function load() {
  if (!writable.value || busy.value) return;
  loading.value = true; error.value = ""; success.value = ""; clearSecret();
  try { data.value = await adminApi<NotificationSettings>("/notification-settings"); }
  catch (e) { data.value = undefined; error.value = message(e); }
  finally { loading.value = false; }
}
async function saveMail() {
  if (!data.value || busy.value) return;
  busy.value = true; error.value = ""; success.value = "";
  const m = data.value.mail;
  try {
    await adminApi("/notification-settings/mail", { method: "PATCH", body: {
      enabled: m.enabled, task_alerts: m.task_alerts, due_reminders: m.due_reminders,
      site_url: m.site_url, subject_prefix: m.subject_prefix, admin_ids: m.admin_ids, config: m.config,
      expected_version: m.version, password_action: passwordAction.value,
      ...(passwordAction.value === "replace" ? { password: password.value } : {}),
    } });
    success.value = "邮件配置已保存，工单订阅期未改变。";
    const current = await adminApi<NotificationSettings>("/notification-settings");
    data.value.mail = current.mail; data.value.recipients = current.recipients;
  } catch (e) { error.value = message(e) + "；若响应丢失或配置已变更，请重新加载核对。"; }
  finally { clearSecret(); busy.value = false; }
}
async function saveTickets() {
  if (!data.value || busy.value) return;
  busy.value = true;
  try {
    await ElMessageBox.confirm("保存会切换工单订阅起点，使旧订阅尚未认领的排队消息失效；不会补发历史消息。已经发送或正在发送的邮件无法撤回。是否保存？", "单独保存工单订阅", { confirmButtonText: "确认切换订阅期", cancelButtonText: "取消", type: "warning" });
  } catch { busy.value = false; return; }
  error.value = ""; success.value = "";
  try {
    const t = data.value.tickets;
    await adminApi("/notification-settings/tickets", { method: "PATCH", body: {
      enabled: t.enabled, admin_ids: t.admin_ids, expected_version: t.version, confirm_new_period: true,
    } });
    success.value = "工单订阅已保存，以下 UTC 时间为新起点；历史消息不会补发。";
    data.value.tickets = (await adminApi<NotificationSettings>("/notification-settings")).tickets;
  } catch (e) { error.value = message(e) + "；请重新加载核对订阅起点后再操作。"; }
  finally { busy.value = false; }
}
onMounted(load);
</script>
<template>
  <section class="admin-query-page notification-settings">
    <div class="page-heading"><div><p class="eyebrow">超级管理员 · 通知设置</p><h1>邮件与通知</h1><p class="muted">保存配置不发送测试邮件。通知仍由独立邮件 Worker 异步发送，未知结果保留人工核验。</p></div><el-button :disabled="busy" :loading="loading" @click="load">重新加载配置</el-button></div>
    <el-alert v-if="!writable" title="仅超级管理员可查看和修改邮件配置" type="warning" :closable="false" />
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="success" :title="success" type="success" :closable="false" role="status" />
    <div v-if="writable" v-loading="loading">
      <template v-if="data">
        <el-card shadow="never">
          <template #header><h2>SMTP 与现有通知场景</h2></template>
          <el-form label-position="top" :disabled="busy || loading" @submit.prevent="saveMail">
            <el-form-item label="邮件总开关"><el-switch v-model="data!.mail.enabled" aria-label="邮件总开关" /></el-form-item>
            <p class="muted">关闭后停止新通知占位和发送认领，已在发送中的邮件无法撤回。密码找回邮件随总开关启用，仍须客户主动申请。</p>
            <div class="settings-grid">
              <el-form-item label="SMTP 主机"><el-input v-model="data!.mail.config.host" aria-label="SMTP 主机" maxlength="253" required /></el-form-item>
              <el-form-item label="SMTP 端口"><el-input-number v-model="data!.mail.config.port" aria-label="SMTP 端口" :min="1" :max="65535" /></el-form-item>
              <el-form-item label="加密方式"><el-select v-model="data!.mail.config.security" aria-label="加密方式"><el-option label="SSL / TLS（SMTPS）" value="smtps" /><el-option label="STARTTLS" value="starttls" /></el-select></el-form-item>
              <el-form-item label="SMTP 用户名"><el-input v-model="data!.mail.config.username" aria-label="SMTP 用户名" maxlength="255" autocomplete="off" /></el-form-item>
              <el-form-item label="发件邮箱"><el-input v-model="data!.mail.config.from" aria-label="发件邮箱" type="email" maxlength="100" required /></el-form-item>
              <el-form-item label="发件名称"><el-input v-model="data!.mail.config.from_name" aria-label="发件名称" maxlength="100" required /></el-form-item>
            </div>
            <el-form-item label="SMTP 密钥"><el-tag :type="data!.mail.password_configured ? 'success' : 'info'">{{ data!.mail.password_configured ? '已配置' : '未配置' }}</el-tag></el-form-item>
            <el-form-item label="密钥处理"><el-radio-group v-model="passwordAction" aria-label="密钥处理" @change="password = ''"><el-radio value="keep">保留现有密钥</el-radio><el-radio value="replace">显式替换密钥</el-radio></el-radio-group></el-form-item>
            <el-form-item v-if="passwordAction === 'replace'" label="新 SMTP 密钥"><el-input v-model="password" aria-label="新 SMTP 密钥" type="password" maxlength="4096" autocomplete="new-password" required /></el-form-item>
            <div class="settings-grid">
              <el-form-item label="本站地址（仅 origin）"><el-input v-model="data!.mail.site_url" aria-label="本站地址" type="url" placeholder="https://shop.example" maxlength="255" required /></el-form-item>
              <el-form-item label="邮件主题前缀"><el-input v-model="data!.mail.subject_prefix" aria-label="邮件主题前缀" maxlength="40" /></el-form-item>
            </div>
            <el-form-item label="任务人工核验告警"><el-switch v-model="data!.mail.task_alerts" aria-label="任务人工核验告警" /></el-form-item>
            <el-form-item label="任务告警管理员收件人"><el-select v-model="data!.mail.admin_ids" multiple aria-label="任务告警管理员收件人"><el-option v-for="a in data!.recipients" :key="a.id" :value="a.id" :label="`${a.username} · ${a.email} (#${a.id})`" /></el-select></el-form-item>
            <el-form-item label="客户到期提醒"><el-switch v-model="data!.mail.due_reminders" aria-label="客户到期提醒" /></el-form-item>
            <p class="muted">开启任务告警或到期提醒后，现有扫描会为仍符合条件的来源占位。客户提醒覆盖未来 7 天内到期的已交付启用实例。</p>
            <el-button native-type="submit" type="primary" :loading="busy">保存邮件配置</el-button>
            <p class="muted">仅保存本区；不会切换工单订阅起点，也不会保存下方未提交的工单变更。</p>
          </el-form>
        </el-card>
        <el-card shadow="never">
          <template #header><h2>工单邮件订阅 · 单独保存</h2></template>
          <el-alert title="每次保存都切换订阅期；旧排队消息失效，历史消息不补发" type="warning" :closable="false" />
          <p class="muted">新工单和客户追加通知指定管理员；管理员公开回复通知原客户。结单记录不发普通回复邮件。启用前需先保存并开启上方邮件总开关。</p>
          <el-form label-position="top" :disabled="busy || loading" @submit.prevent="saveTickets">
            <el-form-item label="工单通知"><el-switch v-model="data!.tickets.enabled" aria-label="工单通知" /></el-form-item>
            <el-form-item label="工单管理员收件人"><el-select v-model="data!.tickets.admin_ids" multiple aria-label="工单管理员收件人"><el-option v-for="a in data!.recipients" :key="a.id" :value="a.id" :label="`${a.username} · ${a.email} (#${a.id})`" /></el-select></el-form-item>
            <p>当前已保存订阅起点（UTC）：{{ data!.tickets.activated_at || '尚未建立' }}</p>
            <el-button native-type="submit" type="primary" :loading="busy">单独保存工单订阅</el-button>
          </el-form>
        </el-card>
      </template>
    </div>
  </section>
</template>
<style scoped>
.notification-settings .el-card + .el-card { margin-top: 20px; }
.settings-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; }
.notification-settings :deep(.el-select) { width: 100%; }
.notification-settings p { overflow-wrap: anywhere; }
.notification-settings h2 { margin: 0; font-size: 18px; }
@media (max-width: 767px) { .settings-grid { grid-template-columns: minmax(0, 1fr); } }
</style>
