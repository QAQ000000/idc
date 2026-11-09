<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { ElMessage } from "element-plus";
import { adminApi } from "../api/admin";
import { ApiError, message } from "../api/request";
import { useAdmin } from "../stores/admin";
import { cycleLabels } from "../api/adminProducts";
interface Prices { frozen_price: string; override_price: string | null; effective_price: string }
interface PriceDetail extends Prices {
  instance_id: number; billing_cycle: string; version: string; editable: boolean; blocked_reason: string | null;
  unpaid_invoices: { id: number; total: string; cycle_marker: string | null }[];
  audit: { id: number; username: string; created_at: string; context: { evidence: string; before: Prices; after: Prices; cancelled_invoice_ids: number[] } }[];
}
const props = defineProps<{ instanceId: number }>();
const emit = defineEmits<{ changed: []; state: [value: { message: string; saving: boolean }] }>();
const account = useAdmin(), detail = ref<PriceDetail>(), busy = ref(false), saving = ref(false), error = ref(""), dialog = ref(false), formError = ref("");
const form = reactive({ mode: "override", price: "", evidence: "", version: "" });
const superAdmin = computed(() => account.admin?.role === "super_admin");
const target = computed(() => form.mode === "restore" ? detail.value?.frozen_price : form.price);
watch(() => [error.value, detail.value?.blocked_reason, saving.value] as const, () => {
  emit("state", { message: error.value ? `续费价格：${error.value}` : detail.value?.blocked_reason ? `暂不可改价：${detail.value.blocked_reason}` : "", saving: saving.value });
}, { immediate: true });
async function load() {
  if (busy.value) return;
  busy.value = true; error.value = ""; detail.value = undefined;
  try { detail.value = await adminApi<PriceDetail>(`/instances/${props.instanceId}/renewal-price`); }
  catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
function edit() {
  if (!detail.value?.editable) return;
  Object.assign(form, { mode: "override", price: detail.value.effective_price, evidence: "", version: detail.value.version });
  formError.value = ""; dialog.value = true;
}
async function save() {
  if (saving.value || !detail.value) return;
  const bytes = new TextEncoder().encode(form.evidence.trim()).length;
  if (bytes < 10 || bytes > 1000) { formError.value = "请填写 10–1000 字节的修改依据"; return; }
  if (form.mode === "override" && (!/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/.test(form.price) || Number(form.price) <= 0)) {
    formError.value = "覆盖价须为 0.01–99999999.99 元，最多两位小数"; return;
  }
  saving.value = true; formError.value = "";
  try {
    await adminApi(`/instances/${props.instanceId}/renewal-price`, { method: "PATCH", body: { override_price: form.mode === "restore" ? null : form.price, expected_version: form.version, evidence: form.evidence.trim() } });
    dialog.value = false; ElMessage.success("续费价已更新，受影响的未付续费单已作废并留审计");
    await load(); emit("changed");
  } catch (e) {
    if (e instanceof ApiError && [400, 409].includes(e.status)) {
      dialog.value = false; await load(); error.value = message(e) + "；请核对最新价格、账单和审计后重新操作。";
    } else {
      // An unknown response may have committed. Invalidate the form before allowing any new edit.
      dialog.value = false; await load(); error.value = message(e) + "；请先核对价格和审计，勿重复提交原操作。";
    }
  } finally { saving.value = false; }
}
onMounted(load);
defineExpose({ reload: load });
</script>
<template>
  <el-card shadow="never">
    <div class="actions"><h2>实例续费价格</h2><el-button :loading="busy" :disabled="saving" @click="load">刷新续费价格</el-button></div>
    <p class="muted">仅调整后续续费；已付账单和已购账期保留，不补差、不变更上游规格。</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-skeleton v-if="busy" :rows="3" animated />
    <template v-if="detail">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="原冻结续费价">¥{{ detail.frozen_price }} / {{ cycleLabels[detail.billing_cycle] || detail.billing_cycle }}</el-descriptions-item>
        <el-descriptions-item label="当前覆盖价">{{ detail.override_price === null ? '未设置，使用原冻结价' : `¥${detail.override_price}` }}</el-descriptions-item>
        <el-descriptions-item label="当前有效续费价">¥{{ detail.effective_price }} / {{ cycleLabels[detail.billing_cycle] || detail.billing_cycle }}</el-descriptions-item>
      </el-descriptions>
      <el-alert v-if="detail.blocked_reason" class="price-warning" :title="`暂不可改价：${detail.blocked_reason}`" type="warning" :closable="false" />
      <p class="muted">未付续费单 {{ detail.unpaid_invoices.length }} 张。保存将作废这些原单，后续按新价重新出账。旧收银台迟到实收只入客户钱包，不按旧价续费。</p>
      <el-button v-if="superAdmin" type="primary" :disabled="!detail.editable || saving" @click="edit">修改续费价格</el-button>
      <p v-else class="muted">当前仅可查看，修改需超级管理员。</p>
      <details class="price-audit">
        <summary>近期改价记录（最多 20 条）</summary>
        <el-table :data="detail.audit" empty-text="暂无续费改价记录" row-key="id">
          <el-table-column prop="created_at" label="时间（UTC）" min-width="180" />
          <el-table-column prop="username" label="管理员" min-width="130" />
          <el-table-column label="修改" min-width="220"><template #default="{ row }">¥{{ row.context.before.effective_price }} → ¥{{ row.context.after.effective_price }}；{{ row.context.after.override_price === null ? '恢复原冻结价' : '使用覆盖价' }}</template></el-table-column>
          <el-table-column label="作废账单" min-width="150"><template #default="{ row }">{{ row.context.cancelled_invoice_ids.map((id: number) => `#${id}`).join('、') || '无' }}</template></el-table-column>
          <el-table-column label="依据" min-width="240"><template #default="{ row }">{{ row.context.evidence }}</template></el-table-column>
        </el-table>
      </details>
    </template>
  </el-card>
  <el-dialog v-model="dialog" class="task-resolution-dialog" title="修改实例续费价格" width="580px" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" :before-close="(done: () => void) => { if (!saving) done(); }" @closed="form.evidence = ''">
    <template v-if="detail">
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" role="alert" />
      <el-alert title="已付账单和已购账期保持；未付续费单将作废，后续按新价出账。" type="warning" :closable="false" />
      <el-form label-position="top" :disabled="saving" @submit.prevent="save">
        <el-form-item label="调整方式"><el-radio-group v-model="form.mode" aria-label="续费调价方式"><el-radio value="override">设置覆盖价</el-radio><el-radio value="restore" :disabled="detail.override_price === null">恢复原冻结价</el-radio></el-radio-group></el-form-item>
        <el-form-item v-if="form.mode === 'override'" label="覆盖续费价（元／原账期）"><el-input v-model="form.price" aria-label="覆盖续费价" inputmode="decimal" maxlength="11" /></el-form-item>
        <p>当前 ¥{{ detail.effective_price }} → 调整后 ¥{{ target || '待填写' }}</p>
        <p class="audit-evidence">将作废的未付续费单：{{ detail.unpaid_invoices.map(v => `#${v.id}（¥${v.total}）`).join('、') || '无' }}</p>
        <el-form-item label="修改依据（不填密码或密钥）"><el-input v-model="form.evidence" type="textarea" aria-label="续费改价依据" :rows="3" maxlength="1000" /></el-form-item>
        <div class="actions"><el-button native-type="submit" type="primary" :loading="saving">确认改价并记录审计</el-button><el-button :disabled="saving" @click="dialog = false">取消</el-button></div>
      </el-form>
    </template>
  </el-dialog>
</template>
<style scoped>
.price-audit { margin-top: 20px; min-width: 0; }
.price-warning { margin-top: 12px; }
</style>
