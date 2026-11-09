<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { useAdmin } from "../stores/admin";
import { adminApi } from "../api/admin";
import { type InstanceDetail, serviceLabels } from "../api/adminQueries";
import { entitlementLabels, upstreamServiceLabels } from "../api/serviceStatus";
import { invoiceLabels, invoiceTypes } from "../api/finance";
import { cycleLabels } from "../api/adminProducts";
import { message } from "../api/request";
import AdminRelatedLinks from "../components/AdminRelatedLinks.vue";
import InstanceServiceStatusPanel from "../components/InstanceServiceStatusPanel.vue";
import ManualAdjustmentPanel from "../components/ManualAdjustmentPanel.vue";
import InstanceRenewalPricePanel from "../components/InstanceRenewalPricePanel.vue";
const route = useRoute(), account = useAdmin();
const data = ref<InstanceDetail>(), busy = ref(false), error = ref("");
const activeTab = ref("overview"), page = ref(Number(route.query.page) || 1);
const pricePanel = ref<InstanceType<typeof InstanceRenewalPricePanel>>();
const adjustmentPanel = ref<InstanceType<typeof ManualAdjustmentPanel>>();
const priceState = ref({ message: "", saving: false }), adjustmentState = ref({ message: "", saving: false });
const finance = computed(() => ["finance", "super_admin"].includes(account.admin?.role || ""));
const submitting = computed(() => priceState.value.saving || adjustmentState.value.saving);
const warnings = computed(() => {
  const i = data.value?.instance;
  if (!i) return [];
  const items: string[] = [];
  if (i.entitlement_state === "expired") items.push("本地权益已到期；不代表上游已停机，请核对原账单与上游账期。");
  if (i.service_observation.review_required) items.push("服务状态需核验；暂停原因或恢复结果未确认，付款不能直接解除未知或非欠费暂停。");
  else if (i.status === "suspended" || i.service_observation.state === "suspended") items.push("服务存在暂停记录；请在服务核对分区核对暂停依据，不能用虚拟机关机代替欠费判断。");
  if (finance.value) items.push(priceState.value.message, adjustmentState.value.message);
  return items.filter(Boolean);
});
async function load(nextPage = page.value) {
  if (busy.value || submitting.value) return;
  busy.value = true; error.value = "";
  try {
    const result = await adminApi<InstanceDetail>(`/instances/${route.params.id}?` + new URLSearchParams({ page: String(nextPage), per_page: String(route.query.per_page || "20") }));
    data.value = result; page.value = result.invoices.page;
  } catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
async function refresh() {
  if (busy.value || submitting.value) return;
  await Promise.all([load(), pricePanel.value?.reload(), adjustmentPanel.value?.reload()]);
}
async function priceChanged() { await Promise.all([load(), adjustmentPanel.value?.reload()]); }
async function adjustmentChanged() { await Promise.all([load(), pricePanel.value?.reload()]); }
onMounted(() => load());
</script>
<template>
  <section class="admin-query-page admin-instance-sample">
    <RouterLink class="back-link" to="/admin/instances">← 返回实例查询</RouterLink>
    <header class="admin-instance-summary">
      <div class="page-heading">
        <div><p class="eyebrow">实例 #{{ route.params.id }}</p><h1 :title="data?.instance.name">{{ data?.instance.name || '实例详情' }}</h1></div>
        <el-button :loading="busy" :disabled="submitting" @click="refresh">刷新实例详情</el-button>
      </div>
      <template v-if="data">
        <div class="summary-facts" aria-label="实例摘要">
          <el-tag>{{ serviceLabels[data.instance.status] || data.instance.status }}</el-tag>
          <span>{{ entitlementLabels[data.instance.entitlement_state] || data.instance.entitlement_state }}</span>
          <span>到期（UTC）：{{ data.instance.due_date || '尚未起租' }}</span>
          <span>IP：{{ data.instance.ip_address || '未回填' }}</span>
        </div>
        <nav class="summary-links" aria-label="实例关联入口">
          <RouterLink :to="`/admin/customers/${data.instance.user_id}`">客户 #{{ data.instance.user_id }}</RouterLink>
          <RouterLink :to="`/admin/products/${data.instance.product_id}`">商品 #{{ data.instance.product_id }}</RouterLink>
          <AdminRelatedLinks :user-id="data.instance.user_id" :instance-id="data.instance.id" />
        </nav>
      </template>
    </header>
    <el-alert v-if="error" :title="error" :description="data ? '本次读取失败，仍显示上次资料；未决操作保留，请重读后核对。' : undefined" type="error" :closable="false" role="alert" />
    <div v-if="warnings.length" class="instance-blockers" aria-label="异常与操作阻断" role="status">
      <el-alert title="需要关注" type="warning" :closable="false"><ul><li v-for="warning in warnings" :key="warning">{{ warning }}</li></ul></el-alert>
    </div>
    <el-card v-if="busy && !data" shadow="never"><el-skeleton :rows="5" animated /></el-card>
    <!-- Non-lazy panes stay mounted: switching sections never discards a financial draft or unknown request. -->
    <el-tabs v-if="data" v-model="activeTab" class="instance-sections" aria-label="实例详情分区">
      <el-tab-pane label="概览与归属" name="overview">
        <el-card shadow="never">
<h2>概览与归属</h2><el-descriptions :column="1" border>
          <el-descriptions-item label="名称">{{ data.instance.name }}</el-descriptions-item>
          <el-descriptions-item label="所属客户"><RouterLink :to="`/admin/customers/${data.instance.user_id}`">#{{ data.instance.user_id }} · {{ data.instance.user_email }}</RouterLink></el-descriptions-item>
          <el-descriptions-item label="商品"><RouterLink :to="`/admin/products/${data.instance.product_id}`">#{{ data.instance.product_id }} · {{ data.instance.product_name }}</RouterLink></el-descriptions-item>
          <el-descriptions-item label="上游／资源"><RouterLink :to="`/admin/providers/${data.instance.provider_id}`">#{{ data.instance.provider_id }} · {{ data.instance.provider_name }}</RouterLink> / {{ data.instance.remote_instance_id || '未回填' }}</el-descriptions-item>
          <el-descriptions-item label="账期">{{ cycleLabels[data.instance.billing_cycle] || data.instance.billing_cycle }}</el-descriptions-item>
          <el-descriptions-item label="起租时间（UTC）">{{ data.instance.start_date || '尚未起租' }}</el-descriptions-item>
          <el-descriptions-item label="自动续费">{{ data.instance.auto_renew ? '已开启' : '未开启' }}</el-descriptions-item>
          <el-descriptions-item v-if="data.instance.status === 'suspended'" label="暂停类型">{{ data.instance.suspend_type }}</el-descriptions-item>
        </el-descriptions>
</el-card>
      </el-tab-pane>
      <el-tab-pane label="服务核对" name="service">
        <InstanceServiceStatusPanel v-if="data.instance.remote_instance_id && data.instance.service_status_supported" admin :instance-id="Number(data.instance.id)" :entitlement="data.instance.entitlement_state" :observation="data.instance.service_observation" @refreshed="load()" />
        <el-card v-else shadow="never"><h2>服务核对</h2><el-empty :description="data.instance.remote_instance_id ? '当前驱动未开放服务状态核对' : '尚未取得上游资源编号，暂不能核对'" /></el-card>
        <p class="muted">{{ upstreamServiceLabels[data.instance.service_observation.state] || '尚未确认上游状态' }}。服务状态、权益到期与实际电源分别判断；本页不下发电源命令。</p>
      </el-tab-pane>
      <el-tab-pane label="价格与补差" name="pricing">
        <template v-if="finance">
          <InstanceRenewalPricePanel ref="pricePanel" :instance-id="data.instance.id" @changed="priceChanged" @state="priceState = $event" />
          <ManualAdjustmentPanel ref="adjustmentPanel" :instance-id="data.instance.id" @changed="adjustmentChanged" @state="adjustmentState = $event" />
        </template>
        <el-card v-else shadow="never"><el-empty description="价格与补差仅财务／超级管理员可查看" /></el-card>
      </el-tab-pane>
      <el-tab-pane label="关联账单" name="invoices">
        <el-card class="query-results" shadow="never">
<h2>关联账单</h2><p class="muted">归属一致的原账单与状态；资金详情继续由财务权限校验。</p>
          <el-table :data="data.invoices.items" :empty-text="error ? '本次读取失败，请重新加载；下方可能为上次结果' : '此实例暂无关联账单'" row-key="invoice_id" :aria-busy="busy">
            <el-table-column label="账单" min-width="100"><template #default="{ row }"><RouterLink v-if="finance" :to="`/admin/finance/invoices/${row.invoice_id}`">#{{ row.invoice_id }}</RouterLink><span v-else>#{{ row.invoice_id }}</span></template></el-table-column>
            <el-table-column label="类型" min-width="110"><template #default="{ row }">{{ invoiceTypes[row.type] || row.type }}</template></el-table-column>
            <el-table-column label="状态" min-width="110"><template #default="{ row }">{{ invoiceLabels[row.status] || row.status }}</template></el-table-column>
            <el-table-column prop="created_at" label="创建时间（UTC）" min-width="185" />
            <el-table-column label="任务定位" min-width="150"><template #default="{ row }"><RouterLink :to="{ path: '/admin/tasks', query: { invoice_id: String(row.invoice_id), instance_id: String(data.instance.id), user_id: String(data.instance.user_id), status: 'all' } }">查看本单任务</RouterLink></template></el-table-column>
          </el-table>
          <el-pagination class="task-pagination" :current-page="page" :page-size="data.invoices.per_page" :total="data.invoices.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy || submitting" @current-change="load" />
        </el-card>
      </el-tab-pane>
    </el-tabs>
    <el-button v-if="error" :disabled="submitting" @click="load()">重新加载实例详情</el-button>
  </section>
</template>
<style scoped>
.admin-instance-summary { margin-bottom: 16px; padding: 16px; border: 1px solid var(--el-border-color-light); border-radius: 12px; background: var(--el-bg-color); }
.admin-instance-summary .page-heading { margin-bottom: 10px; gap: 12px; flex-wrap: wrap; }
.admin-instance-summary h1 { font-size: 24px; overflow-wrap: anywhere; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; }
.summary-facts, .summary-links { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; font-size: 14px; }
.summary-links { margin-top: 12px; }
.summary-links :deep(.query-links) { display: contents; }
.summary-links :deep(p) { flex-basis: 100%; margin: 0; }
.instance-blockers ul { margin: 0; padding-left: 18px; overflow-wrap: anywhere; }
.instance-sections, .instance-sections :deep(.el-tabs__content) { min-width: 0; }
.instance-sections :deep(.el-tabs__item) { padding-inline: 16px; }
.instance-sections :deep(.el-tab-pane) > .el-card + .el-card { margin-top: 16px; }
.instance-sections .query-results { margin-top: 0; }
@media (max-width: 640px) {
  .admin-instance-summary { padding: 12px; }
  .admin-instance-summary h1 { font-size: 20px; }
  .instance-sections :deep(.el-tabs__item) { padding-inline: 8px; font-size: 12px; }
}
</style>
