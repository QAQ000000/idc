<script setup lang="ts">
import { ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api, message } from "../api/request";
const route = useRoute(), router = useRouter();
interface Row { invoice_id: number; instance_id: number | null; instance_name: string | null; type: string; total: string; status: string; created_at: string; quote_expired: number | null }
const result = ref<{items: Row[]; total: number; page: number; per_page: number}>();
const busy = ref(false), error = ref("");
const statuses: Record<string, string> = { all: "全部", unpaid: "待付款", paid: "已付款", cancelled: "已取消", refund_pending: "退款处理中", refunded: "已退款" };
const types: Record<string, string> = { new: "新购", renew: "续费", upgrade: "变配补差", recharge: "充值" };
const status = ref("all"), page = ref(1);
let generation = 0;
async function load() {
  const attempt = ++generation;
  busy.value = true; error.value = "";
  status.value = typeof route.query.status === "string" ? route.query.status : "all";
  page.value = /^\d+$/.test(String(route.query.page ?? "1")) ? Number(route.query.page ?? 1) : 1;
  try {
    const data = await api<{items: Row[]; total: number; page: number; per_page: number}>(`/invoices?page=${encodeURIComponent(String(page.value))}&per_page=20&status=${encodeURIComponent(status.value)}`);
    if (attempt === generation) result.value = data;
  } catch (e) { if (attempt === generation) { result.value = undefined; error.value = message(e); } }
  finally { if (attempt === generation) busy.value = false; }
}
function filter(value: string) { void router.push({path: "/invoices", query: {status: value, page: "1"}}); }
function turn(value: number) { void router.push({path: "/invoices", query: {status: status.value, page: String(value)}}); }
watch(() => route.fullPath, load, { immediate: true });
</script>
<template>
  <section>
    <h1>我的账单</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <div class="actions"><el-select :model-value="status" aria-label="账单状态" style="width: 160px" @change="filter"><el-option v-for="(label, value) in statuses" :key="value" :label="label" :value="value" /></el-select><el-button :loading="busy" @click="load">刷新</el-button></div>
    <el-table v-loading="busy" :data="result?.items ?? []" empty-text="没有符合条件的账单" style="width: 100%">
      <el-table-column prop="invoice_id" label="账单编号" width="100" />
      <el-table-column label="类型" width="110"><template #default="{row}">{{ types[row.type] || row.type }}</template></el-table-column>
      <el-table-column prop="instance_name" label="实例" min-width="150" />
      <el-table-column label="金额" width="110"><template #default="{row}">¥{{ row.total }}</template></el-table-column>
      <el-table-column label="状态" width="130"><template #default="{row}">{{ statuses[row.status] || row.status }}<span v-if="row.status === 'unpaid' && row.quote_expired">（报价已过期）</span></template></el-table-column>
      <el-table-column label="创建时间" min-width="200"><template #default="{row}">{{ row.created_at }} UTC</template></el-table-column>
      <el-table-column label="操作" width="130"><template #default="{row}"><RouterLink :to="`/invoices/${row.invoice_id}`">{{ row.status === 'unpaid' && !row.quote_expired && ['new','renew'].includes(row.type) ? '继续原单' : '查看详情' }}</RouterLink></template></el-table-column>
    </el-table>
    <el-pagination v-if="result" layout="prev, pager, next, total" :pager-count="5" :total="result.total" :page-size="result.per_page" :current-page="result.page" @current-change="turn" />
  </section>
</template>
