<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ElMessage } from "element-plus";
import { adminApi } from "../api/admin";
import { fetchAdminCategories, type CategoryItem } from "../api/adminCategories";
import { categoryOptionsFor } from "../utils/categoryOptions";
import { cycleLabels, type ProductDetail, type Provider, type SnapshotPreview } from "../api/adminProducts";
import { ApiError, message } from "../api/request";
import ConfigOptionRenderer from "./ConfigOptionRenderer.vue";
import { configurationComplete } from "../utils/configOptions";
const props = defineProps<{ product?: ProductDetail; initial?: { provider_id: number; remote_pid: number; name: string; type: string }; embedded?: boolean }>();
const emit = defineEmits<{ saved: [productId: number]; state: [state: { busy: boolean; unknown: boolean }] }>();
const route = useRoute(), router = useRouter(), providers = ref<Provider[]>([]);
const categoryTree = ref<CategoryItem[]>([]);
const selectedCategoryPath = ref<number[]>([]);
const categoryOptions = computed(() => categoryOptionsFor(categoryTree.value, props.product?.category_id ?? null));
function onCategoryChange(val: unknown) {
  const arr = Array.isArray(val) ? (val as number[]) : [];
  if (arr.length !== 2) selectedCategoryPath.value = [];
  form.category_id = arr.length === 2 && typeof arr[1] === "number" ? arr[1] : null;
}
const form = reactive({
  provider_id: props.initial ? String(props.initial.provider_id) : typeof route.query.provider_id === "string" ? route.query.provider_id : "",
  remote_pid: props.initial ? String(props.initial.remote_pid) : typeof route.query.remote_pid === "string" ? route.query.remote_pid : "",
  name: props.initial?.name || "",
  type: props.initial?.type || "cloud",
  category_id: null as number | null,
  markup_type: "none",
  markup_value: "0.00",
  billing_cycles: props.product?.billing_cycles || ["monthly"],
});
const options = ref<Record<string, string> | null>(props.product?.defaults || null), preview = ref<SnapshotPreview>(), busy = ref(false), saving = ref(false), error = ref(""), evidence = ref("");
const payload = computed(() => props.product ? { expected_version: props.product.version, billing_cycles: form.billing_cycles, ...(options.value ? { config_options: options.value } : {}) } : { ...form, provider_id: Number(form.provider_id), remote_pid: Number(form.remote_pid), category_id: form.category_id, ...(options.value ? { config_options: options.value } : {}) });
const confirmation = ref<{ token: string; input: object }>();
const unknown = ref(false);
const submitted = ref<object>();
watch(() => ({ busy: busy.value || saving.value, unknown: unknown.value }), value => emit("state", value), { immediate: true });
watch(payload, () => { confirmation.value = undefined; }, { deep: true });
const canPreview = computed(() => form.billing_cycles.length > 0 && (props.product || (form.provider_id && form.remote_pid && form.name.trim()))
  && (!preview.value || !options.value || configurationComplete(preview.value.fields, options.value)));
onMounted(async () => {
  if (!props.product) {
    try {
      providers.value = await adminApi<Provider[]>("/providers");
      const cats = await fetchAdminCategories();
      categoryTree.value = cats.tree;
    } catch (e) {
      error.value = message(e);
    }
  }
});
async function fetchPreview() {
  if (busy.value || saving.value || unknown.value || !canPreview.value) return;
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
function resetDefaults() { if (unknown.value || saving.value) return; options.value = null; preview.value = undefined; confirmation.value = undefined; }
async function save() {
  if (!confirmation.value || saving.value) return;
  const bytes = new TextEncoder().encode(evidence.value.trim()).length;
  if (bytes < 10 || bytes > 1000) { error.value = "请填写 10–1000 字节的变更依据"; return; }
  saving.value = true; error.value = "";
  if (!unknown.value) submitted.value = { ...confirmation.value.input, evidence: evidence.value.trim(), preview_token: confirmation.value.token };
  try {
    const r = await adminApi<{ product_id: number }>(props.product ? `/products/${props.product.id}/snapshot` : "/products/import", { method: "POST", body: submitted.value });
    unknown.value = false;
    ElMessage.success(props.product ? "快照已刷新；历史订单与实例未改价" : "商品已导入并默认下架，请检查后上架");
    if (props.product || props.embedded) { emit("saved", r.product_id); } else { await router.push(`/admin/products/${r.product_id}`); }
  } catch (e) {
    // An expired replay does not disprove the earlier commit. Keep its original context.
    unknown.value = unknown.value || !(e instanceof ApiError && e.businessResponse && [400, 401, 403, 409, 422].includes(e.status));
    error.value = message(e) + (unknown.value ? "；结果待核对，原参数已保留。请先只读查看商品与审计，勿另建导入；令牌过期后不能直接重放。" : "；请核对后重新预览。");
  }
  finally { saving.value = false; }
}
</script>
<template>
  <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
  <p class="muted">仅从已配置上游读取 Schema 和报价，不购买资源。导入默认下架；刷新保持原销售状态与资源归属。</p>
  <el-alert v-if="unknown" title="导入结果待核对；关闭抽屉会保留原确认，再次打开可核对或在有效期内显式原参数重试。刷新浏览器会清除内存草稿。" type="warning" :closable="false" />
  <el-form label-position="top" :disabled="busy || saving || unknown" @submit.prevent="fetchPreview">
    <template v-if="!product">
      <el-form-item label="已配置上游"><el-select v-model="form.provider_id" aria-label="已配置上游" @change="resetDefaults"><el-option v-for="p in providers" :key="p.id" :value="String(p.id)" :label="`${p.name} · ${p.driver} (#${p.id})`" :disabled="!p.status || !p.snapshot_supported" /></el-select></el-form-item>
      <el-form-item label="上游商品 PID"><el-input v-model="form.remote_pid" aria-label="上游商品 PID" inputmode="numeric" @input="resetDefaults" /></el-form-item>
      <el-form-item label="本地商品名称"><el-input v-model="form.name" aria-label="本地商品名称" maxlength="100" /></el-form-item>
      <el-form-item label="所属分类（一级分组 → 二级分类）">
        <el-cascader
          v-model="selectedCategoryPath" aria-label="导入目标分类"
          :options="categoryOptions"
          placeholder="选择二级分类（不选则为未分类）"
          clearable
          style="width: 100%"
          @change="onCategoryChange"
        />
      </el-form-item>
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
    <p v-if="preview.category_path" class="category-summary">
      目标分类：<strong>{{ preview.category_path }}</strong>
    </p>
    <p class="muted">确认前会再读取上游核对；报价或配置变化需重新预览。预览有效至 {{ preview.expires_at }} UTC。</p>
    <el-form label-position="top" :disabled="saving" @submit.prevent="save"><el-form-item label="变更依据（不填密钥）"><el-input v-model="evidence" :disabled="unknown" type="textarea" aria-label="快照变更依据" :rows="3" maxlength="1000" /></el-form-item><el-button native-type="submit" type="primary" :disabled="!confirmation || busy" :loading="saving">{{ unknown ? '按原确认重试（先只读核对）' : product ? '确认刷新快照' : '确认导入并保持下架' }}</el-button><p v-if="!confirmation" class="muted">参数已变化，请重新预览后确认。</p></el-form>
  </template>
</template>
