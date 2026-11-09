<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ElMessage } from "element-plus";
import { adminApi } from "../api/admin";
import { cycleLabels, type ProductDetail, type Provider, type SnapshotPreview } from "../api/adminProducts";
import { message } from "../api/request";
import ConfigOptionRenderer from "./ConfigOptionRenderer.vue";
import { configurationComplete } from "../utils/configOptions";
const props = defineProps<{ product?: ProductDetail }>();
const emit = defineEmits<{ saved: [] }>();
const route = useRoute(), router = useRouter(), providers = ref<Provider[]>([]);
const form = reactive({ provider_id: typeof route.query.provider_id === "string" ? route.query.provider_id : "", remote_pid: typeof route.query.remote_pid === "string" ? route.query.remote_pid : "", name: "", type: "cloud", markup_type: "none", markup_value: "0.00", billing_cycles: props.product?.billing_cycles || ["monthly"] });
const options = ref<Record<string, string> | null>(props.product?.defaults || null), preview = ref<SnapshotPreview>(), busy = ref(false), saving = ref(false), error = ref(""), evidence = ref("");
const payload = computed(() => props.product ? { expected_version: props.product.version, billing_cycles: form.billing_cycles, ...(options.value ? { config_options: options.value } : {}) } : { ...form, provider_id: Number(form.provider_id), remote_pid: Number(form.remote_pid), ...(options.value ? { config_options: options.value } : {}) });
const confirmation = ref<{ token: string; input: object }>();
watch(payload, () => { confirmation.value = undefined; }, { deep: true });
const canPreview = computed(() => form.billing_cycles.length > 0 && (props.product || (form.provider_id && form.remote_pid && form.name.trim()))
  && (!preview.value || !options.value || configurationComplete(preview.value.fields, options.value)));
onMounted(async () => { if (!props.product) { try { providers.value = await adminApi<Provider[]>("/providers"); } catch (e) { error.value = message(e); } } });
async function fetchPreview() {
  if (busy.value || saving.value || !canPreview.value) return;
  error.value = ""; busy.value = true; confirmation.value = undefined;
  try {
    const result = await adminApi<SnapshotPreview>(props.product ? `/products/${props.product.id}/snapshot/preview` : "/products/preview", { method: "POST", body: payload.value });
    options.value = result.defaults; preview.value = result;
    // The input sent for confirmation must include exactly the selected configuration used in this preview.
    await Promise.resolve();
    confirmation.value = { token: result.preview_token, input: JSON.parse(JSON.stringify(payload.value)) as object };
  } catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
function resetDefaults() { options.value = null; preview.value = undefined; confirmation.value = undefined; }
async function save() {
  if (!confirmation.value || saving.value) return;
  const bytes = new TextEncoder().encode(evidence.value.trim()).length;
  if (bytes < 10 || bytes > 1000) { error.value = "请填写 10–1000 字节的变更依据"; return; }
  saving.value = true; error.value = "";
  try {
    const r = await adminApi<{ product_id: number }>(props.product ? `/products/${props.product.id}/snapshot` : "/products/import", { method: "POST", body: { ...confirmation.value.input, evidence: evidence.value.trim(), preview_token: confirmation.value.token } });
    ElMessage.success(props.product ? "快照已刷新；历史订单与实例未改价" : "商品已导入并默认下架，请检查后上架");
    if (props.product) { emit("saved"); } else { await router.push(`/admin/products/${r.product_id}`); }
  } catch (e) { error.value = message(e) + "；若响应丢失，请先查看商品列表和审计，避免另建商品。"; }
  finally { saving.value = false; }
}
</script>
<template>
  <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
  <p class="muted">仅从已配置上游读取 Schema 和报价，不购买资源。导入默认下架；刷新保持原销售状态与资源归属。</p>
  <el-form label-position="top" :disabled="busy || saving" @submit.prevent="fetchPreview">
    <template v-if="!product">
      <el-form-item label="已配置上游"><el-select v-model="form.provider_id" aria-label="已配置上游" @change="resetDefaults"><el-option v-for="p in providers" :key="p.id" :value="String(p.id)" :label="`${p.name} · ${p.driver} (#${p.id})`" :disabled="!p.status || !p.snapshot_supported" /></el-select></el-form-item>
      <el-form-item label="上游商品 PID"><el-input v-model="form.remote_pid" aria-label="上游商品 PID" inputmode="numeric" @input="resetDefaults" /></el-form-item>
      <el-form-item label="本地商品名称"><el-input v-model="form.name" aria-label="本地商品名称" maxlength="100" /></el-form-item>
      <el-form-item label="商品类型"><el-select v-model="form.type" aria-label="商品类型"><el-option v-for="t in ['cloud','dedicated','cdn','hosting','software']" :key="t" :value="t" :label="t" /></el-select></el-form-item>
      <el-form-item label="加价类型"><el-select v-model="form.markup_type" aria-label="导入加价类型"><el-option label="不加价" value="none" /><el-option label="固定金额" value="fixed" /><el-option label="百分比" value="percent" /></el-select></el-form-item>
      <el-form-item v-if="form.markup_type !== 'none'" label="加价值（元 / %）"><el-input v-model="form.markup_value" aria-label="导入加价值" inputmode="decimal" /></el-form-item>
    </template>
    <el-form-item label="可售账期"><el-checkbox-group v-model="form.billing_cycles"><el-checkbox value="monthly">月付</el-checkbox><el-checkbox value="annually">年付</el-checkbox></el-checkbox-group></el-form-item>
    <ConfigOptionRenderer v-if="preview && options" v-model="options" :fields="preview.fields" :disabled="busy || saving" />
    <div class="actions"><el-button native-type="submit" type="primary" :loading="busy" :disabled="!canPreview">读取并预览配置与报价</el-button><el-button @click="resetDefaults">使用上游默认配置</el-button></div>
  </el-form>
  <template v-if="preview">
    <h3>{{ confirmation ? '所选配置参考报价' : '上次预览参考报价（请重新预览确认）' }}</h3>
    <el-table :data="preview.billing_cycles.map(c => ({ cycle: c, first: preview!.prices[c], recurring: preview!.recurring_prices[c] }))">
      <el-table-column label="账期"><template #default="{ row }">{{ cycleLabels[row.cycle] }}</template></el-table-column><el-table-column prop="first" label="首期售价 (¥)" /><el-table-column prop="recurring" label="新购续费参考价 (¥)" />
    </el-table>
    <p class="muted">确认前会再读取上游核对；报价或配置变化需重新预览。预览有效至 {{ preview.expires_at }} UTC。</p>
    <el-form label-position="top" :disabled="saving" @submit.prevent="save"><el-form-item label="变更依据（不填密钥）"><el-input v-model="evidence" type="textarea" aria-label="快照变更依据" :rows="3" maxlength="1000" /></el-form-item><el-button native-type="submit" type="primary" :disabled="!confirmation || busy" :loading="saving">{{ product ? '确认刷新快照' : '确认导入并保持下架' }}</el-button><p v-if="!confirmation" class="muted">参数已变化，请重新预览后确认。</p></el-form>
  </template>
</template>
