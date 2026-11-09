<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { ElMessage } from "element-plus";
import { adminApi } from "../api/admin";
import { ApiError, message } from "../api/request";
import { useAdmin } from "../stores/admin";
import { cycleLabels } from "../api/adminProducts";
interface Basis { invoice_id: number; period_start: string; period_end: string; original_price: string; covered_price: string; prior_adjustment_ids: number[] }
interface Detail { effective_price: string; billing_cycle: string; editable: boolean; blocked_reason: string | null; basis: Basis | null; audit: { id: number; invoice_id: number; created_at: string; evidence: string; total: string; new_price: string }[] }
interface Preview { basis: Basis; total: string; new_price: string; coverage_before: string; coverage_after: string; calculated_at: string; expires_at: string; preview_token: string; cancelled_invoice_ids: number[] }
const props = defineProps<{ instanceId: number }>(), emit = defineEmits<{ changed: [] }>();
const account = useAdmin(), detail = ref<Detail>(), busy = ref(false), saving = ref(false), dialog = ref(false), error = ref(""), formError = ref(""), preview = ref<Preview>(), requestKey = ref(""), uncertain = ref(false);
const form = reactive({ price: "", evidence: "", confirmed: false });
const superAdmin = computed(() => account.admin?.role === "super_admin");
async function load() {
  busy.value = true; error.value = "";
  try { detail.value = await adminApi<Detail>(`/instances/${props.instanceId}/manual-adjustment`); }
  catch (e) { detail.value = undefined; error.value = message(e); }
  finally { busy.value = false; }
}
function open() {
  form.price = detail.value?.effective_price || ""; form.evidence = ""; form.confirmed = false;
  formError.value = ""; preview.value = undefined; uncertain.value = false; dialog.value = true;
}
async function quote() {
  if (saving.value) return;
  const bytes = new TextEncoder().encode(form.evidence.trim()).length;
  if (bytes < 10 || bytes > 1000) { formError.value = "请填写 10–1000 字节的实际变配完成依据"; return; }
  if (!/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/.test(form.price) || Number(form.price) <= 0) { formError.value = "目标周期价须为正数，最多两位小数"; return; }
  saving.value = true; formError.value = "";
  try {
    preview.value = await adminApi<Preview>(`/instances/${props.instanceId}/manual-adjustment/preview`, { method: "POST", body: { new_price: form.price, evidence: form.evidence.trim() } });
    requestKey.value = crypto.randomUUID(); form.confirmed = false;
  } catch (e) { formError.value = message(e); }
  finally { saving.value = false; }
}
async function settle() {
  if (saving.value || !preview.value || !form.confirmed) return;
  saving.value = true; formError.value = "";
  try {
    const result = await adminApi<{ invoice_id: number }>(`/instances/${props.instanceId}/manual-adjustment/settle`, { method: "POST", body: { preview_token: preview.value.preview_token, request_key: requestKey.value, confirmed: true } });
    dialog.value = false; preview.value = undefined; uncertain.value = false;
    ElMessage.success(`人工变配已结清，账单 #${result.invoice_id}`); await load(); emit("changed");
  } catch (e) {
    formError.value = message(e);
    if (e instanceof ApiError && [400, 403, 409].includes(e.status)) { preview.value = undefined; form.confirmed = false; await load(); }
    else { uncertain.value = true; formError.value += "；结果未知，请重试原结算或先查看记录，勿另建操作。"; await load(); }
  } finally { saving.value = false; }
}
onMounted(load);
</script>
<template>
  <el-card shadow="never">
    <div class="actions"><h2>手工变配补差</h2><el-button :loading="busy" :disabled="saving" @click="load">刷新补差记录</el-button></div>
    <p class="muted">仅结算管理员已在上游完成的变配。本入口不执行上游操作，不改变到期日；未来续费按新的周期价出账。</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-skeleton v-if="busy && !detail" :rows="3" animated />
    <template v-if="detail">
      <el-alert v-if="detail.blocked_reason" :title="`暂不可补差：${detail.blocked_reason}`" type="warning" :closable="false" />
      <p v-if="detail.basis" class="muted">本期原账单 #{{ detail.basis.invoice_id }}：¥{{ detail.basis.original_price }}；已覆盖周期价 ¥{{ detail.basis.covered_price }}。未来有效续费价 ¥{{ detail.effective_price }} / {{ cycleLabels[detail.billing_cycle] || detail.billing_cycle }}。</p>
      <el-button v-if="superAdmin" type="primary" :disabled="!detail.editable || saving" @click="open">预览已完成变配的补差</el-button>
      <p v-else class="muted">当前仅可查看，结清需超级管理员。</p>
      <details class="adjustment-audit">
        <summary>近期人工结清记录（最多 20 条）</summary>
        <el-table :data="detail.audit" empty-text="暂无人工补差记录" row-key="id">
          <el-table-column label="账单" min-width="100"><template #default="{ row }"><RouterLink :to="`/admin/finance/invoices/${row.invoice_id}`">#{{ row.invoice_id }}</RouterLink></template></el-table-column>
          <el-table-column prop="created_at" label="结清时间（UTC）" min-width="185" />
          <el-table-column label="补差／未来周期价" min-width="180"><template #default="{ row }">¥{{ row.total }} / ¥{{ row.new_price }}</template></el-table-column>
          <el-table-column prop="evidence" label="完成依据" min-width="220" />
        </el-table>
      </details>
    </template>
  </el-card>
  <el-dialog v-model="dialog" class="task-resolution-dialog adjustment-dialog" title="已完成变配：预览与余额结清" width="680px" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" :before-close="(done: () => void) => { if (!saving) done(); }">
    <el-alert title="只在上游实际变配完成后操作。正补差立即扣客户余额，零补差只记结清；降价不自动退款。" type="warning" :closable="false" />
    <el-alert v-if="formError" class="adjustment-warning" :title="formError" type="error" :closable="false" role="alert" />
    <el-form v-if="!preview" label-position="top" @submit.prevent="quote">
      <el-form-item label="新周期价（同时用于后续续费，元）"><el-input v-model="form.price" inputmode="decimal" maxlength="11" :disabled="saving" /></el-form-item>
      <el-form-item label="实际变配完成依据（勿填写密码或密钥）"><el-input v-model="form.evidence" type="textarea" :rows="3" maxlength="1000" :disabled="saving" /></el-form-item>
    </el-form>
    <template v-else>
      <el-descriptions :column="1" border class="adjustment-preview">
        <el-descriptions-item label="原账单／本期实付">#{{ preview.basis.invoice_id }} / ¥{{ preview.basis.original_price }}</el-descriptions-item>
        <el-descriptions-item label="本期（UTC）">{{ preview.basis.period_start }} → {{ preview.basis.period_end }}</el-descriptions-item>
        <el-descriptions-item label="已覆盖／新周期价">¥{{ preview.coverage_before }} → ¥{{ preview.new_price }}</el-descriptions-item>
        <el-descriptions-item label="本次余额补差"><strong>¥{{ preview.total }}</strong></el-descriptions-item>
        <el-descriptions-item label="计价／预览失效（UTC）">{{ preview.calculated_at }} / {{ preview.expires_at }}</el-descriptions-item>
        <el-descriptions-item label="将作废未付续费单">{{ preview.cancelled_invoice_ids.map(id => `#${id}`).join('、') || '无' }}</el-descriptions-item>
      </el-descriptions>
      <p class="muted">按本期实际剩余秒数折算。原资源、账期与历史记录保留；本次结清不代表系统自动核验上游规格。</p>
      <el-checkbox v-model="form.confirmed" class="adjustment-confirm" :disabled="saving">我确认实际变配已完成，依据属实，并授权本次余额结清及后续续费改价</el-checkbox>
    </template>
    <template #footer>
      <el-button :disabled="saving" @click="dialog = false">关闭</el-button>
      <el-button v-if="preview && !uncertain" :disabled="saving" @click="preview = undefined">返回修改</el-button>
      <el-button v-if="!preview" type="primary" :loading="saving" @click="quote">预览补差</el-button>
      <el-button v-else type="primary" :loading="saving" :disabled="!form.confirmed" @click="settle">{{ uncertain ? '重试原结算' : `确认结清 ¥${preview.total}` }}</el-button>
    </template>
  </el-dialog>
</template>
<style scoped>
.adjustment-audit { margin-top: 20px; }
.adjustment-audit summary { cursor: pointer; margin-bottom: 12px; }
.adjustment-warning, .adjustment-preview { margin: 16px 0; }
.adjustment-confirm { width: 100%; height: auto; white-space: normal; align-items: flex-start; }
.adjustment-confirm :deep(.el-checkbox__label) { white-space: normal; overflow-wrap: anywhere; line-height: 1.5; }
.adjustment-confirm :deep(.el-checkbox__input) { margin-top: 3px; }
</style>
