<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAdmin } from "../stores/admin";
import { adminApi } from "../api/admin";
import { type InstanceDetail, serviceLabels } from "../api/adminQueries";
import { invoiceLabels, invoiceTypes } from "../api/finance";
import { cycleLabels } from "../api/adminProducts";
import { message } from "../api/request";
import AdminRelatedLinks from "../components/AdminRelatedLinks.vue";
const route = useRoute(), router = useRouter(), account = useAdmin();
const data = ref<InstanceDetail>(), busy = ref(false), error = ref("");
const finance = computed(() => ["finance", "super_admin"].includes(account.admin?.role || ""));
async function load() {
  if (busy.value) return;
  busy.value = true; error.value = ""; data.value = undefined;
  try { data.value = await adminApi<InstanceDetail>(`/instances/${route.params.id}?` + new URLSearchParams({ page: String(route.query.page || "1"), per_page: String(route.query.per_page || "20") })); }
  catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
async function turn(page: number) { await router.push({ path: route.path, query: { ...route.query, page: String(page) } }); }
onMounted(load);
</script>
<template>
  <section class="admin-query-page">
    <RouterLink class="back-link" to="/admin/instances">← 返回实例查询</RouterLink>
    <div class="page-heading"><div><p class="eyebrow">实例资料 · 只读</p><h1>实例 #{{ route.params.id }}</h1><p class="muted">本地服务记录；不查询实时电源状态，不执行上游操作。</p></div><el-button :loading="busy" @click="load">刷新实例详情</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-card v-if="busy" shadow="never"><el-skeleton :rows="8" animated /></el-card>
    <template v-if="data">
      <el-card shadow="never">
<h2>实例与归属</h2><el-descriptions :column="1" border>
        <el-descriptions-item label="名称">{{ data.instance.name }}</el-descriptions-item>
        <el-descriptions-item label="所属客户"><RouterLink :to="`/admin/customers/${data.instance.user_id}`">#{{ data.instance.user_id }} · {{ data.instance.user_email }}</RouterLink></el-descriptions-item>
        <el-descriptions-item label="商品"><RouterLink :to="`/admin/products/${data.instance.product_id}`">#{{ data.instance.product_id }} · {{ data.instance.product_name }}</RouterLink></el-descriptions-item>
        <el-descriptions-item label="服务状态">{{ serviceLabels[data.instance.status] || data.instance.status }}</el-descriptions-item>
        <el-descriptions-item label="IP">{{ data.instance.ip_address || '未回填' }}</el-descriptions-item>
        <el-descriptions-item label="上游／资源">#{{ data.instance.provider_id }} · {{ data.instance.provider_name }} / {{ data.instance.remote_instance_id || '未回填' }}</el-descriptions-item>
        <el-descriptions-item label="账期">{{ cycleLabels[data.instance.billing_cycle] || data.instance.billing_cycle }}</el-descriptions-item>
        <el-descriptions-item label="到期日（UTC）">{{ data.instance.due_date || '尚未起租' }}</el-descriptions-item>
        <el-descriptions-item label="起租时间（UTC）">{{ data.instance.start_date || '尚未起租' }}</el-descriptions-item>
        <el-descriptions-item label="自动续费">{{ data.instance.auto_renew ? '已开启' : '未开启' }}</el-descriptions-item>
        <el-descriptions-item v-if="data.instance.status === 'suspended'" label="暂停类型">{{ data.instance.suspend_type }}</el-descriptions-item>
      </el-descriptions><h2>关联定位</h2><AdminRelatedLinks :user-id="data.instance.user_id" :instance-id="data.instance.id" />
</el-card>
      <el-card class="query-results" shadow="never">
<h2>关联账单</h2><p class="muted">仅展示归属一致的原账单与状态，资金详情继续由财务权限校验。</p>
        <el-table :data="data.invoices.items" empty-text="此实例暂无关联账单" row-key="invoice_id">
          <el-table-column label="账单" min-width="100"><template #default="{ row }"><RouterLink v-if="finance" :to="`/admin/finance/invoices/${row.invoice_id}`">#{{ row.invoice_id }}</RouterLink><span v-else>#{{ row.invoice_id }}</span></template></el-table-column>
          <el-table-column label="类型" min-width="110"><template #default="{ row }">{{ invoiceTypes[row.type] || row.type }}</template></el-table-column>
          <el-table-column label="状态" min-width="110"><template #default="{ row }">{{ invoiceLabels[row.status] || row.status }}</template></el-table-column>
          <el-table-column prop="created_at" label="创建时间（UTC）" min-width="185" />
          <el-table-column label="任务定位" min-width="150"><template #default="{ row }"><RouterLink :to="{ path: '/admin/tasks', query: { invoice_id: String(row.invoice_id), instance_id: String(data.instance.id), user_id: String(data.instance.user_id), status: 'all' } }">查看本单任务</RouterLink></template></el-table-column>
        </el-table>
        <el-pagination class="task-pagination" :current-page="data.invoices.page" :page-size="data.invoices.per_page" :total="data.invoices.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy" @current-change="turn" />
      </el-card>
    </template>
    <el-button v-if="error" @click="load">重新加载实例详情</el-button>
  </section>
</template>
