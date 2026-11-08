<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute } from "vue-router";
import { ElMessage } from "element-plus";
import { actionLabels, adminApi, type Resolution, type TaskDetail } from "../api/admin";
import { ApiError, message } from "../api/request";
import { useAdmin } from "../stores/admin";
const route = useRoute(), account = useAdmin();
const detail = ref<TaskDetail>(), busy = ref(false), error = ref(""), auditPage = ref(1);
const dialog = ref(false), submitting = ref(false), formError = ref("");
const form = reactive({ action: "fulfill" as Resolution, token: 0, evidence: "", remote: "", ip: "", confirmed: false });
const needsConfirmation = computed(() => ["retry", "cancel_compensate", "close_unexecuted"].includes(form.action));
const evidenceBytes = computed(() => new TextEncoder().encode(form.evidence.trim()).length);
async function load(page = auditPage.value) {
  busy.value = true; error.value = ""; detail.value = undefined; auditPage.value = page;
  try { detail.value = await adminApi<TaskDetail>(`/tasks/${route.params.id}?audit_page=${page}`); }
  catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
function open(action: Resolution) {
  if (!detail.value || submitting.value) return;
  Object.assign(form, { action, token: detail.value.task.execution_token, evidence: "", remote: detail.value.instance.remote_instance_id || "", ip: "", confirmed: false });
  formError.value = ""; dialog.value = true;
}
async function resolve() {
  if (submitting.value) return;
  formError.value = "";
  if (evidenceBytes.value < 10 || evidenceBytes.value > 1000) { formError.value = "请填写 10–1000 字节的核验材料（中文通常每字 3 字节）"; return; }
  if (needsConfirmation.value && !form.confirmed) { formError.value = "请先完成上游核验并勾选确认"; return; }
  if (form.action === "fulfill" && !form.remote.trim()) { formError.value = "请填写经核验的真实上游资源编号"; return; }
  submitting.value = true;
  try {
    await adminApi(`/tasks/${route.params.id}/resolve`, { method: "POST", body: {
      action: form.action, evidence: form.evidence.trim(), expected_execution_token: form.token,
      ...(needsConfirmation.value ? { confirmed_no_execution_or_pending_request: form.confirmed } : {}),
      ...(form.action === "fulfill" ? { remote_instance_id: form.remote.trim(), ip_address: form.ip.trim() || null } : {}),
    } });
    dialog.value = false; ElMessage.success("处理已提交并写入审计记录"); await load(1);
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) {
      dialog.value = false; ElMessage.error("状态、令牌或业务门禁已变化，操作未执行；请重新核验最新详情。" + message(e)); await load(1);
    } else { formError.value = message(e) + "；提交结果不确定时请关闭弹窗并刷新核对，勿重复处理。"; }
  } finally { submitting.value = false; }
}
onMounted(() => load());
</script>
<template>
  <div class="actions"><RouterLink to="/admin/tasks">返回异常任务</RouterLink><el-button :loading="busy" :disabled="submitting" @click="load()">刷新详情</el-button></div>
  <h1>任务 #{{ route.params.id }}</h1>
  <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
  <div v-loading="busy" class="task-detail">
    <template v-if="detail">
      <el-alert v-if="detail.invoice?.status === 'paid' && detail.task.status !== 'success'" title="客户已付款，履约尚未确认完成。请先核实上游原订单，避免重复购买或重复续费。" type="warning" :closable="false" />
      <el-card>
        <h2>任务与异常</h2>
        <el-descriptions :column="1" border>
          <el-descriptions-item label="状态 / 类型">{{ detail.task.status }} / {{ detail.task.action }}</el-descriptions-item>
          <el-descriptions-item label="异常原因">{{ detail.task.last_error || '无异常码；执行或只读调查仍可能在进行' }}</el-descriptions-item>
          <el-descriptions-item label="执行令牌">{{ detail.task.execution_token }}</el-descriptions-item>
          <el-descriptions-item label="上游操作 / 资源编号">{{ detail.task.upstream_task_id || '未取得' }} / {{ detail.instance.remote_instance_id || '未回填' }}</el-descriptions-item>
          <el-descriptions-item label="重试次数">{{ detail.task.retry_count }} / {{ detail.task.max_retries }}</el-descriptions-item>
          <el-descriptions-item label="租约 / 下次调查 (UTC)">{{ detail.task.lease_until || '无租约' }} / {{ detail.task.next_retry_at || '未安排' }}</el-descriptions-item>
          <el-descriptions-item label="创建 / 更新 (UTC)">{{ detail.task.created_at }} / {{ detail.task.updated_at }}</el-descriptions-item>
        </el-descriptions>
      </el-card>
      <el-card>
        <h2>关联上下文</h2>
        <el-descriptions :column="1" border>
          <el-descriptions-item label="客户">#{{ detail.user.id }} · {{ detail.user.email }}</el-descriptions-item>
          <el-descriptions-item label="账单"><template v-if="detail.invoice">#{{ detail.task.invoice_id }} · {{ detail.invoice.type }} · {{ detail.invoice.status }} · ¥{{ detail.invoice.total }}</template><span v-else>无关联账单（非财务操作）</span></el-descriptions-item>
          <el-descriptions-item label="付款时间 (UTC)">{{ detail.invoice?.paid_at || '—' }} · {{ detail.invoice?.payment_method || '—' }}</el-descriptions-item>
          <el-descriptions-item label="实例">#{{ detail.instance.id }} · {{ detail.task.instance_name }} · {{ detail.instance.status }}</el-descriptions-item>
          <el-descriptions-item label="商品 / 上游">{{ detail.task.product_name }} / #{{ detail.task.provider_id }} {{ detail.task.provider_name }}</el-descriptions-item>
          <el-descriptions-item label="IP / 暂停类型">{{ detail.instance.ip_address || '—' }} / {{ detail.instance.suspend_type }}</el-descriptions-item>
          <el-descriptions-item label="账期 / 到期 (UTC)">{{ detail.instance.billing_cycle }} / {{ detail.instance.due_date || '尚未起租' }}</el-descriptions-item>
          <el-descriptions-item label="续费价格">¥{{ detail.instance.override_price ?? detail.instance.recurring_price }}</el-descriptions-item>
        </el-descriptions>
        <h2>本地收款凭证</h2>
        <el-table :data="detail.receipts" empty-text="暂无收款凭证">
          <el-table-column prop="id" label="编号" width="85" /><el-table-column prop="amount" label="金额" width="100" />
          <el-table-column prop="status" label="状态" min-width="150" /><el-table-column prop="settlement_purpose" label="用途" min-width="180" />
          <el-table-column prop="refund_status" label="退款状态" min-width="120" /><el-table-column prop="refund_amount" label="已退金额" min-width="100" />
        </el-table>
        <p class="muted">本地收款不证明上游已扣款。渠道退款由管理员人工办理；不要把上游取消状态当作退款凭证。</p>
      </el-card>
      <el-card>
        <h2>受控人工处理</h2>
        <el-alert v-if="detail.blocked_by_other_operation" title="同实例存在其他未决操作或退款冻结，暂不允许裁决。" type="warning" :closable="false" />
        <p v-if="!detail.allowed_actions.length" class="muted">{{ account.admin?.role !== 'super_admin' ? '当前账号仅可查看；人工裁决需要启用的超级管理员。' : '当前状态不允许人工裁决。运行中或调查中的任务由 Worker 处理；终态任务不重复操作。' }}</p>
        <p v-else class="muted">请先核实上游原订单、资源归属、扣款与排队请求，再选择处理方式。所有动作要求核验材料并保留审计，提交时重新检查门禁。</p>
        <div class="actions"><el-button v-for="action in detail.allowed_actions" :key="action" :type="action === 'cancel_compensate' ? 'danger' : 'primary'" plain @click="open(action)">{{ actionLabels[action] }}</el-button></div>
      </el-card>
      <el-card>
        <h2>人工处理记录</h2>
        <p class="muted">仅展示管理员裁决记录；Worker 历史仍查运行日志。时间均为 UTC。</p>
        <el-table :data="detail.audit.items" empty-text="暂无人工处理记录">
          <el-table-column prop="created_at" label="时间" width="180" />
          <el-table-column label="经办人" min-width="130"><template #default="{ row }">{{ row.username }} (#{{ row.admin_id }})</template></el-table-column>
          <el-table-column prop="action" label="动作" min-width="170" />
          <el-table-column label="核验材料 / 令牌" min-width="280"><template #default="{ row }"><div class="audit-evidence">{{ row.context.evidence }}</div>原令牌 {{ row.context.previous_execution_token }}<span v-if="row.context.remote_instance_id"> · {{ row.context.remote_instance_id }}</span><span v-if="row.context.amount"> · 钱包 ¥{{ row.context.amount }}</span></template></el-table-column>
        </el-table>
        <el-pagination class="task-pagination" :current-page="auditPage" :total="detail.audit.total" :page-size="20" layout="prev, pager, next, total" :pager-count="5" :disabled="busy" @current-change="load" />
      </el-card>
    </template>
  </div>
  <el-dialog v-model="dialog" class="task-resolution-dialog" :title="actionLabels[form.action]" width="580px" :close-on-click-modal="false" :close-on-press-escape="!submitting" :show-close="!submitting" :before-close="(done: () => void) => { if (!submitting) done(); }" destroy-on-close>
    <el-alert v-if="formError" :title="formError" type="error" :closable="false" role="alert" />
    <el-alert v-if="form.action === 'cancel_compensate'" title="该动作永久取消未交付新购、终止本地实例，并将原收款退到客户站内钱包，不是渠道原路退款。续费任务不支持此动作。" type="warning" :closable="false" />
    <el-alert v-else-if="form.action === 'fulfill'" title="仅依据证据确认原操作，不再次请求上游。电源操作只结束原任务，不改实例权益；当前运行状态不能单独证明重启成功。" type="info" :closable="false" />
    <el-alert v-else-if="form.action === 'close_unexecuted'" title="仅在确认原电源请求未执行且没有排队或延迟请求时结束占用，不重发操作，不修改资源或资金。" type="warning" :closable="false" />
    <el-alert v-else-if="form.action === 'retry'" title="重试会允许 Worker 再次请求上游，只有证实本次未执行且无延迟请求时才可操作。查无记录不足以证明未执行。" type="warning" :closable="false" />
    <p>本次提交使用执行令牌 {{ form.token }}。状态或令牌变化会拒绝提交。</p>
    <el-form label-position="top" :disabled="submitting" @submit.prevent="resolve">
      <template v-if="form.action === 'fulfill'">
        <el-form-item label="真实上游资源编号"><el-input v-model="form.remote" aria-label="真实上游资源编号" maxlength="100" :readonly="detail?.task.action !== 'create'" /></el-form-item>
        <el-form-item v-if="detail?.task.action === 'create'" label="IP 地址（可选）"><el-input v-model="form.ip" aria-label="IP 地址" maxlength="45" /></el-form-item>
      </template>
      <el-form-item label="核验材料（工单号、归属与实际结果，不填写密码或密钥）"><el-input v-model="form.evidence" type="textarea" aria-label="核验材料" :rows="4" maxlength="1000" /><span class="muted">{{ evidenceBytes }} / 1000 字节，至少 10 字节</span></el-form-item>
      <el-checkbox v-if="needsConfirmation" v-model="form.confirmed" class="task-confirm">{{ form.action === "close_unexecuted" ? "已核实本次电源请求未执行，且无排队或延迟执行请求。" : "已核实本次操作未产生新资产、未扣上游款且无延迟执行请求；续费保留原资源。" }}</el-checkbox>
      <div class="actions"><el-button native-type="submit" :type="form.action === 'cancel_compensate' ? 'danger' : 'primary'" :loading="submitting">确认提交并记录审计</el-button><el-button :disabled="submitting" @click="dialog = false">取消</el-button></div>
    </el-form>
  </el-dialog>
</template>
