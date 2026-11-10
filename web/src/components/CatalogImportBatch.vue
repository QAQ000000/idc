<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import { adminApi, type Admin, type Page } from "../api/admin";
import type { SnapshotPreview, ProductDetail, AdminProduct } from "../api/adminProducts";
import { cycleLabels } from "../api/adminProducts";
import type { ProviderDetail, UpstreamCatalogItem } from "../api/adminProviders";
import { ApiError } from "../api/request";
import { ImportBatch, BatchRequestError, type BatchItem, type ItemState } from "../catalog/importBatch";
const props = defineProps<{ providerId: number; selection: UpstreamCatalogItem[]; enabled: boolean; blocked: boolean }>();
const emit = defineEmits<{ state: [value: { running: boolean; unknown: boolean; active: boolean }]; imported: []; created: [] }>();
const opened = ref(false), formError = ref("");
const plan = reactive({ markup_type: "none", markup_value: "0.00", billing_cycles: ["monthly"], evidence: "" });
const identity = sessionStorage.getItem("jianyun_admin_token");
async function request<T>(path: string, options: { method?: string; body?: unknown } = {}): Promise<T> {
  const controller = new AbortController(), timer = window.setTimeout(() => controller.abort(), 90_000);
  try { return await adminApi<T>(path, { ...options, signal: controller.signal }); }
  catch (error) { if (error instanceof ApiError) throw new BatchRequestError(error.message, error.status, error.code, error.businessResponse); throw error; }
  finally { window.clearTimeout(timer); }
}
const batch = reactive(new ImportBatch({
  async guard(readOnly = false) {
    if (!readOnly && !props.enabled) throw new BatchRequestError("连接已禁用，整批暂停", 409, 40910, true);
    if (!identity || sessionStorage.getItem("jianyun_admin_token") !== identity) throw new BatchRequestError("登录身份已变化，整批暂停", 401, 401, true);
    const admin = await request<Admin>("/auth/me");
    if (admin.role !== "super_admin") throw new BatchRequestError("当前身份不再是超级管理员，整批暂停", 403, 403, true);
    if (readOnly) return;
    const provider = await request<ProviderDetail>(`/provider-connections/${props.providerId}`);
    if (!provider.status || !provider.snapshot_supported) throw new BatchRequestError("连接已禁用或导入能力不可用，整批暂停", 409, 40910, true);
  },
  preview: input => request<SnapshotPreview>("/products/preview", { method: "POST", body: input }),
  submit: input => request<{ product_id: number; outcome: string }>("/products/import", { method: "POST", body: input }),
  async reconcile(item) {
    // Recovery must work even when the supplier is unavailable or removed the candidate.
    const products = await request<Page<AdminProduct>>(`/products?provider_id=${props.providerId}&remote_pid=${item.input.remote_pid}&per_page=2`);
    const local = products.total === 1 ? products.items[0] : undefined;
    if (!local || !item.payload) return { matched: false };
    const bytes = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(item.payload.preview_token));
    const operation = Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, "0")).join("");
    const product = await request<ProductDetail & { import_operation: { matched: boolean } }>(`/products/${local.id}?import_operation=${operation}`);
    return { product_id: local.id, matched: product.provider_id === item.input.provider_id && product.remote_pid === item.input.remote_pid && product.import_operation.matched };
  },
}));
const labels: Record<ItemState, string> = { pending: "待预检", previewing: "预检中", ready: "待确认", submitting: "导入中", success: "已导入", existing: "已存在（未改动）", rejected: "单项失败", confirm: "需重新确认", unknown: "待核对" };
const complete = computed(() => batch.items.filter(item => item.state === "success").length);
watch(() => ({ running: batch.running, unknown: batch.unknown, active: !!batch.items.length }), value => emit("state", value), { immediate: true });
watch(complete, (value, old) => { if (value > old) emit("imported"); });
watch(() => batch.items.filter(item => item.state === "existing").length, (value, old) => { if (value > old) emit("imported"); });
watch(() => props.enabled, enabled => { if (!enabled && batch.items.length) batch.stop("连接已禁用，停止后续派发；在途请求仍需核对结果。"); });
function start() {
  formError.value = "";
  if (props.blocked || !props.enabled || !batch.start(props.providerId, props.selection, plan)) { formError.value = "请选择1–20个未导入商品、可售账期，并填写10–1000字节依据；单品未决时不能开始批次。"; return; }
  emit("created");
}
function configuration(item: BatchItem) {
  return (item.preview?.fields || []).map(field => `${field.label}：${field.choices?.find(choice => choice.value === item.preview?.defaults[field.id])?.label || item.preview?.defaults[field.id] || '未选择'}`).join("；") || "无可选配置，采用上游默认配置";
}
function close(done: () => void) { if (!batch.running) done(); }
function beforeUnload(event: BeforeUnloadEvent) { if (batch.running || batch.unknown) event.preventDefault(); }
window.addEventListener("beforeunload", beforeUnload);
onBeforeUnmount(() => { batch.dispose(); window.removeEventListener("beforeunload", beforeUnload); });
</script>
<template>
  <div class="batch-toolbar" aria-label="批量选品操作"><span>{{ batch.items.length ? `本批 ${batch.items.length} 项 · 已导入 ${complete}` : `已选 ${selection.length} 项` }}</span><el-button type="primary" :disabled="!batch.items.length && (!selection.length || blocked || !enabled)" @click="opened = true">{{ batch.items.length ? '查看导入批次' : '批量导入' }}</el-button></div>
  <p v-if="batch.items.length" role="status" class="batch-notice">{{ batch.notice }}<span v-if="batch.unknown"> 存在待核对项。</span></p>
  <el-drawer v-model="opened" title="多选顺序导入" size="min(760px, 100vw)" :before-close="close" :show-close="!batch.running" :close-on-click-modal="!batch.running" :close-on-press-escape="!batch.running" class="catalog-batch-drawer">
    <section aria-label="导入批次">
      <el-alert title="仅导入商品，不购买资源；成功默认下架。停止派发不代表撤销在途请求。" type="info" :closable="false" />
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" />
      <el-form v-if="!batch.items.length" label-position="top" @submit.prevent="start">
        <p>连接 #{{ providerId }} · 已选 {{ selection.length }} 项（最多20项），各商品使用上游默认配置。</p>
        <el-form-item label="统一加价规则"><el-select v-model="plan.markup_type" aria-label="批量加价规则"><el-option label="不加价" value="none" /><el-option label="固定金额" value="fixed" /><el-option label="百分比" value="percent" /></el-select></el-form-item>
        <el-form-item v-if="plan.markup_type !== 'none'" label="加价值（元／%）"><el-input v-model="plan.markup_value" aria-label="批量加价值" inputmode="decimal" /></el-form-item>
        <el-form-item label="统一可售账期"><el-checkbox-group v-model="plan.billing_cycles"><el-checkbox value="monthly">月付</el-checkbox><el-checkbox value="annually">年付</el-checkbox></el-checkbox-group></el-form-item>
        <el-form-item label="导入依据（不填密钥）"><el-input v-model="plan.evidence" aria-label="批量导入依据" type="textarea" :rows="3" maxlength="1000" /></el-form-item>
        <el-button native-type="submit" type="primary" :disabled="blocked || !enabled">建立批次，等待分组预检</el-button>
      </el-form>
      <template v-else>
        <p>统一规则：{{ plan.markup_type === 'none' ? '不加价' : `${plan.markup_type === 'fixed' ? '固定加价' : '百分比加价'} ${plan.markup_value}` }} · {{ plan.billing_cycles.map(c => cycleLabels[c]).join('／') }} · 每项采用预检摘要中的配置。</p>
        <p class="batch-text">导入依据：{{ plan.evidence }}</p>
        <el-alert :title="batch.notice" :type="batch.unknown || batch.paused ? 'warning' : 'info'" :closable="false" />
        <div class="batch-actions">
          <el-button :disabled="batch.running || batch.unknown || !enabled || !batch.items.some(item => item.state === 'pending')" @click="batch.previewNext()">预检下一组（最多5项）</el-button>
          <el-button type="primary" :disabled="batch.running || batch.unknown || !enabled || !batch.ready.length" @click="batch.confirm()">确认本组摘要并顺序导入（{{ batch.ready.length }}项）</el-button>
          <el-button v-if="batch.running" type="warning" @click="batch.stop()">停止后续派发</el-button>
        </div>
        <p>预检不等于确认。请逐项展开摘要核对价格和配置，再确认本组；变价或过期须重新预检并确认。单项规则失败可继续，连接异常整批暂停。</p>
        <article v-for="item in batch.items" :key="item.input.remote_pid" class="batch-item" :aria-label="`批次商品 #${item.input.remote_pid}`">
          <h3>#{{ item.input.remote_pid }} · {{ item.input.name }} <el-tag :type="item.state === 'success' ? 'success' : item.state === 'unknown' ? 'danger' : 'info'">{{ labels[item.state] }}</el-tag></h3>
          <p class="batch-text" role="status">{{ item.message }}</p>
          <template v-if="item.preview">
            <p v-for="cycle in item.preview.billing_cycles" :key="cycle">{{ cycleLabels[cycle] }} · 首期 ¥{{ item.preview.prices[cycle] }} · 续费参考 ¥{{ item.preview.recurring_prices[cycle] }}</p>
            <details><summary>查看配置与确认期限</summary><p class="batch-text">{{ configuration(item) }}</p><p>预检有效至 {{ item.preview.expires_at }} UTC；到期后不能按此确认新导入。</p></details>
          </template>
          <RouterLink v-if="item.productId" :to="`/admin/products/${item.productId}`">查看本地商品 #{{ item.productId }}</RouterLink>
          <div class="batch-actions">
            <el-button v-if="['pending','rejected','confirm','ready'].includes(item.state)" :disabled="batch.running || batch.unknown || !enabled" @click="batch.previewNext(item)">重新预检 #{{ item.input.remote_pid }}</el-button>
            <template v-if="item.state === 'unknown'"><el-button :disabled="batch.running" @click="batch.reconcile(item)">只读核对 #{{ item.input.remote_pid }}</el-button><el-button :disabled="batch.running || !enabled" @click="batch.retry(item)">原确认重试 #{{ item.input.remote_pid }}</el-button></template>
          </div>
        </article>
        <el-button :disabled="batch.running || batch.unknown" @click="batch.clear()">结束本批并清除内存待办</el-button>
      </template>
      <p class="muted">切分区、目录／详情刷新、筛选和分页保留本批。离开此连接或F5会停止后续派发并丢失内存待办；已发送请求不会被撤销，已入库商品可通过目录和审计核对。F5后不自动续跑。</p>
    </section>
  </el-drawer>
</template>
<style scoped>
.batch-toolbar{position:sticky;bottom:8px;z-index:10;display:flex;justify-content:space-between;align-items:center;gap:8px;background:var(--el-bg-color);border:1px solid var(--el-border-color);border-radius:8px;padding:10px;margin-top:12px}.batch-notice,.batch-text{overflow-wrap:anywhere}.batch-actions{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.batch-actions :deep(.el-button+.el-button){margin-left:0}.batch-item{padding:12px 0;border-bottom:1px solid var(--el-border-color);margin-bottom:12px}.batch-item h3{font-size:16px;overflow-wrap:anywhere}.batch-item summary{cursor:pointer}.batch-item p{line-height:1.6}section{min-width:0}
@media(max-width:600px){.batch-actions{flex-direction:column;align-items:stretch}.batch-actions :deep(.el-button){height:auto;min-height:32px;padding:8px;white-space:normal}.batch-toolbar{font-size:13px}}
</style>
