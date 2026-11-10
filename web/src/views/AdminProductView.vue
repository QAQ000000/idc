<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute } from "vue-router";
import { ElMessage } from "element-plus";
import { adminApi } from "../api/admin";
import { cycleLabels, salesLabels, type ProductDetail } from "../api/adminProducts";
import { ApiError, message } from "../api/request";
import { useAdmin } from "../stores/admin";
import { configurationLabel } from "../utils/configOptions";
import AdminSnapshotForm from "../components/AdminSnapshotForm.vue";
const route = useRoute(), account = useAdmin();
const detail = ref<ProductDetail>(), busy = ref(false), saving = ref(false), error = ref(""), dialog = ref(false), formError = ref(""), auditPage = ref(1);
const editable = computed(() => account.admin?.role === "super_admin");
const form = reactive({ name: "", status: "hidden", markup_type: "none", markup_value: "0.00", expected_version: "" });
async function load(page = auditPage.value) {
  busy.value = true; error.value = ""; detail.value = undefined; auditPage.value = page;
  try { detail.value = await adminApi<ProductDetail>(`/products/${route.params.id}?audit_page=${page}`); }
  catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
function edit() {
  if (!detail.value) return;
  Object.assign(form, { name: detail.value.name, status: detail.value.status, markup_type: detail.value.markup_type, markup_value: detail.value.markup_value, expected_version: detail.value.version });
  formError.value = ""; dialog.value = true;
}
async function save() {
  if (saving.value) return;
  saving.value = true; formError.value = "";
  try {
    await adminApi(`/products/${route.params.id}`, { method: "PATCH", body: { ...form } });
    dialog.value = false; ElMessage.success("商品已更新并写入审计；历史账单和实例续费价未改变"); await load(1);
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) { dialog.value = false; await load(1); error.value = message(e) + "；请核对最新数据后再操作。"; }
    else { formError.value = message(e) + "；响应不确定时先刷新详情和审计核对。"; }
  } finally { saving.value = false; }
}
onMounted(() => load());
</script>
<template>
  <div class="actions"><RouterLink to="/admin/products">返回商品列表</RouterLink><el-button :loading="busy" :disabled="saving" @click="load()">刷新详情</el-button></div>
  <h1>商品 #{{ route.params.id }}</h1>
  <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
  <div v-loading="busy" class="task-detail">
    <template v-if="detail">
      <el-card>
        <h2>{{ detail.name }}</h2>
        <el-descriptions :column="1" border>
          <el-descriptions-item label="销售状态">{{ salesLabels[detail.status] }} · {{ detail.provider_status ? '上游已启用' : '上游已禁用' }}</el-descriptions-item>
          <el-descriptions-item label="上游绑定"><RouterLink :to="`/admin/providers/${detail.provider_id}`">#{{ detail.provider_id }} {{ detail.provider_name }}</RouterLink> · {{ detail.driver }} · 商品 PID {{ detail.remote_pid }}</el-descriptions-item>
          <el-descriptions-item label="商品类型 / 库存">{{ detail.type }} / {{ detail.stock === -1 ? '不限或以上游为准' : detail.stock }}</el-descriptions-item>
          <el-descriptions-item label="加价规则">{{ detail.markup_type === 'none' ? '不加价' : detail.markup_type === 'fixed' ? `固定 +¥${detail.markup_value}` : `百分比 +${detail.markup_value}%` }}</el-descriptions-item>
          <el-descriptions-item label="更新时间 (UTC)">{{ detail.updated_at }}</el-descriptions-item>
        </el-descriptions>
        <p class="muted">下架 / 停售阻止新购，保留已付款交付、历史账单付款、存量续费和原单异常调查。商品调价不更改实例的已冻结续费价。</p>
        <div class="actions"><el-button v-if="editable" type="primary" @click="edit">编辑名称、上下架与加价</el-button><p v-else class="muted">当前管理员仅可查看；商品变更需要超级管理员。</p></div>
      </el-card>
      <el-card>
        <h2>可售账期与默认配置参考价</h2>
        <el-table :data="detail.billing_cycles.map(c => ({cycle:c, first:detail!.prices[c], recurring:detail!.recurring_prices[c]}))" empty-text="暂无可售账期">
          <el-table-column label="账期"><template #default="{ row }">{{ cycleLabels[row.cycle] || row.cycle }}</template></el-table-column><el-table-column prop="first" label="首期参考价 (¥)" /><el-table-column label="新购续费参考价 (¥)"><template #default="{ row }">{{ row.recurring || '未记录，需预览' }}</template></el-table-column>
        </el-table>
        <p class="muted">参考价来自本地快照默认配置，并非实时成交价；首期可能含一次性费用。存量实例以各自冻结价格续费。</p>
        <el-alert v-if="detail.configuration_error" :title="detail.configuration_error" type="warning" :closable="false" />
        <el-descriptions v-if="detail.fields.length" :column="1" border><el-descriptions-item v-for="f in detail.fields" :key="f.id" :label="f.label">{{ configurationLabel(f, detail!.defaults[f.id]) }}</el-descriptions-item></el-descriptions>
        <p v-else class="muted">当前为固定商品或无可展示配置项。</p>
      </el-card>
      <el-card v-if="editable && detail.snapshot_supported">
        <h2>导入最新上游快照</h2>
        <AdminSnapshotForm :key="detail.version" :product="detail" @saved="load(1)" />
      </el-card>
      <el-card>
        <h2>商品变更记录</h2>
        <el-table :data="detail.audit.items" empty-text="暂无网页商品变更记录">
          <el-table-column prop="created_at" label="时间 (UTC)" width="180" /><el-table-column prop="username" label="管理员" min-width="140" /><el-table-column prop="action" label="动作" min-width="190" />
          <el-table-column label="变更详情" min-width="320"><template #default="{ row }"><div v-if="row.context.evidence" class="audit-evidence">依据：{{ row.context.evidence }}</div><div v-if="row.context.before">原值：{{ JSON.stringify(row.context.before) }}</div><div v-if="row.context.after">新值：{{ JSON.stringify(row.context.after) }}</div><div v-if="row.context.prices">参考价：{{ JSON.stringify(row.context.prices) }}</div></template></el-table-column>
        </el-table>
        <el-pagination class="task-pagination" :current-page="auditPage" :total="detail.audit.total" :page-size="20" :pager-count="5" layout="prev, pager, next, total" @current-change="load" />
      </el-card>
    </template>
  </div>
  <el-dialog v-model="dialog" class="task-resolution-dialog" title="编辑商品销售设置" width="580px" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" :before-close="(done: () => void) => { if (!saving) done(); }">
    <el-alert v-if="formError" :title="formError" type="error" :closable="false" role="alert" />
    <el-alert title="只修改商品目录；不重算历史账单或实例续费价。保存时检查版本，过期编辑会被拒绝。" type="info" :closable="false" />
    <el-form label-position="top" :disabled="saving" @submit.prevent="save">
      <el-form-item label="商品名称"><el-input v-model="form.name" aria-label="编辑商品名称" maxlength="100" /></el-form-item>
      <el-form-item label="销售状态"><el-select v-model="form.status" aria-label="编辑销售状态"><el-option v-for="(label,key) in salesLabels" :key="key" :value="key" :label="label" /></el-select></el-form-item>
      <el-form-item label="加价类型"><el-select v-model="form.markup_type" aria-label="编辑加价类型"><el-option label="不加价" value="none" /><el-option label="固定金额" value="fixed" /><el-option label="百分比" value="percent" /></el-select></el-form-item>
      <el-form-item v-if="form.markup_type !== 'none'" label="加价值（元 / %）"><el-input v-model="form.markup_value" aria-label="编辑加价值" inputmode="decimal" maxlength="20" /></el-form-item>
      <p class="muted">加价基于已记录的默认配置成本参考，不在原售价上叠加；缺少旧成本时只读补取上游报价，失败则拒绝调价。实际购买仍实时预检。</p>
      <div class="actions"><el-button native-type="submit" type="primary" :loading="saving">确认保存并记录审计</el-button><el-button :disabled="saving" @click="dialog = false">取消</el-button></div>
    </el-form>
  </el-dialog>
</template>
