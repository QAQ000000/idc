<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import type { Page } from "../api/admin";
import { ticketApi, ticketLabels, type TicketRow } from "../api/tickets";
import { message } from "../api/request";
const props = defineProps<{ admin?: boolean }>();
const route = useRoute(), router = useRouter(), base = props.admin ? "/admin/tickets" : "/tickets";
const filters = reactive(Object.fromEntries(["status", ...(props.admin ? ["ticket_id", "user_id", "email", "instance_id"] : [])].map(k => [k, String(route.query[k] || "")])) as Record<string, string>);
const data = ref<Page<TicketRow>>(), busy = ref(false), error = ref("");
async function load() {
  if (busy.value) return;
  busy.value = true; error.value = ""; data.value = undefined;
  try { data.value = await ticketApi<Page<TicketRow>>(!!props.admin, "?" + new URLSearchParams({ ...Object.fromEntries(Object.keys(filters).map(k=>[k,String(route.query[k] || "")])), page: String(route.query.page || "1"), per_page: "20" })); }
  catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
async function search(page = 1) {
  const target = { path: base, query: { ...filters, page: String(page) } };
  if (router.resolve(target).fullPath === route.fullPath) await load(); else await router.push(target);
}
function clear() { Object.keys(filters).forEach(k=>filters[k]=""); void search(); }
onMounted(load);
</script>
<template>
  <section class="ticket-page">
    <div class="page-heading"><div><p class="eyebrow">{{ admin ? '管理员工作区' : '客户控制台' }}</p><h1>{{ admin ? '客服工单' : '我的工单' }}</h1><p class="muted">工单用于沟通；退款、改价和重装由原业务入口处理。</p></div><div class="actions"><el-button :loading="busy" @click="load">刷新工单</el-button><el-button v-if="!admin" type="primary" @click="router.push('/tickets/new')">提交工单</el-button></div></div>
    <el-card shadow="never">
      <el-form class="ticket-filters" label-position="top" @submit.prevent="search()">
        <el-form-item label="工单状态"><el-select v-model="filters.status" aria-label="工单状态"><el-option label="全部状态" value="" /><el-option v-for="(label,key) in ticketLabels" :key="key" :label="label" :value="key" /></el-select></el-form-item>
        <template v-if="admin"><el-form-item v-for="(label,key) in { ticket_id:'工单 ID', user_id:'客户 ID', email:'客户邮箱', instance_id:'实例 ID' }" :key="key" :label="label"><el-input v-model="filters[key]" :aria-label="label" maxlength="100" /></el-form-item></template>
        <div class="actions"><el-button native-type="submit" type="primary" :disabled="busy">查询工单</el-button><el-button :disabled="busy" @click="clear">重置筛选</el-button></div>
      </el-form>
    </el-card>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-card shadow="never">
      <el-skeleton v-if="busy" :rows="5" animated />
      <el-table v-else-if="data" :data="data.items" empty-text="暂无符合条件的工单" row-key="id">
        <el-table-column label="工单" min-width="220"><template #default="{row}"><RouterLink :to="`${base}/${row.id}`">#{{ row.id }} · {{ row.title }}</RouterLink></template></el-table-column>
        <el-table-column label="状态" min-width="140"><template #default="{row}"><el-tag :type="row.status==='closed'?'info':'primary'">{{ ticketLabels[row.status] || row.status }}</el-tag></template></el-table-column>
        <el-table-column v-if="admin" label="客户" min-width="230"><template #default="{row}"><RouterLink :to="`/admin/customers/${row.user_id}`">#{{ row.user_id }} · {{ row.email }}</RouterLink></template></el-table-column>
        <el-table-column label="实例" min-width="100"><template #default="{row}">{{ row.instance_id ? `#${row.instance_id}` : '未关联' }}</template></el-table-column>
        <el-table-column prop="updated_at" label="更新时间（UTC）" min-width="185" />
      </el-table>
      <el-button v-if="error" @click="load">重新加载工单</el-button>
      <el-pagination v-if="data" class="task-pagination" :current-page="data.page" :page-size="data.per_page" :total="data.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy" @current-change="search" />
    </el-card>
  </section>
</template>
