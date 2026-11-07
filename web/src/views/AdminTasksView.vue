<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { adminApi, type Page, type Task } from "../api/admin";
import { message } from "../api/request";
const route = useRoute(), router = useRouter();
const filters = reactive({
  status: typeof route.query.status === "string" ? route.query.status : "attention",
  action: typeof route.query.action === "string" ? route.query.action : "",
  task_id: typeof route.query.task_id === "string" ? route.query.task_id : "",
  invoice_id: typeof route.query.invoice_id === "string" ? route.query.invoice_id : "",
  instance_id: typeof route.query.instance_id === "string" ? route.query.instance_id : "",
});
const page = ref(Number(route.query.page) || 1), data = ref<Page<Task>>(), busy = ref(false), error = ref("");
async function load(nextPage = page.value) {
  if (busy.value) return;
  page.value = nextPage; busy.value = true; error.value = ""; data.value = undefined;
  try {
    const query = new URLSearchParams({ ...filters, page: String(page.value) });
    data.value = await adminApi<Page<Task>>("/tasks?" + query);
  } catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
async function filter() {
  const target = { path: "/admin/tasks", query: { ...filters, page: "1" } };
  if (router.resolve(target).fullPath === route.fullPath) await load(1);
  else await router.push(target);
}
async function paginate(next: number) {
  await router.push({ path: "/admin/tasks", query: { ...filters, page: String(next) } });
}
onMounted(() => load());
</script>
<template>
  <h1>异常任务</h1>
  <p class="muted">异常关注包括未结案的失败、调查中和租约失效的执行中任务。已结案记录可切换 failed 或 all 查看。未知结果需先核验，不能直接重新购买。时间均为 UTC。</p>
  <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
  <el-card>
    <el-form class="task-filters" label-position="top" @submit.prevent="filter">
      <el-form-item label="任务状态">
<el-select v-model="filters.status" aria-label="任务状态">
        <el-option label="异常关注" value="attention" /><el-option label="待人工核验" value="manual" />
        <el-option v-for="s in ['investigating','failed','pending','running','success','all']" :key="s" :label="s" :value="s" />
      </el-select>
</el-form-item>
      <el-form-item label="任务类型"><el-select v-model="filters.action" aria-label="任务类型"><el-option label="全部类型" value="" /><el-option label="新购" value="create" /><el-option label="续费" value="renew" /></el-select></el-form-item>
      <el-form-item label="任务 ID"><el-input v-model="filters.task_id" aria-label="任务 ID" inputmode="numeric" /></el-form-item>
      <el-form-item label="账单 ID"><el-input v-model="filters.invoice_id" aria-label="账单 ID" inputmode="numeric" /></el-form-item>
      <el-form-item label="实例 ID"><el-input v-model="filters.instance_id" aria-label="实例 ID" inputmode="numeric" /></el-form-item>
      <el-form-item label="查询"><el-button type="primary" native-type="submit" :disabled="busy">筛选</el-button><el-button :loading="busy" @click="load()">刷新</el-button></el-form-item>
    </el-form>
    <el-table v-loading="busy" :data="data?.items || []" empty-text="没有匹配的任务" row-key="id" style="width:100%">
      <el-table-column label="任务" width="110"><template #default="{ row }"><RouterLink :to="`/admin/tasks/${row.id}`">#{{ row.id }} 详情</RouterLink></template></el-table-column>
      <el-table-column prop="action" label="类型" width="90" /><el-table-column prop="status" label="状态" width="125" />
      <el-table-column label="账单 / 实例" width="150"><template #default="{ row }">账单 #{{ row.invoice_id }}<br>实例 #{{ row.instance_id }}</template></el-table-column>
      <el-table-column label="收款" width="130"><template #default="{ row }">{{ row.invoice_status || '无账单' }}<br>¥{{ row.total || '—' }}</template></el-table-column>
      <el-table-column prop="product_name" label="商品" min-width="150" />
      <el-table-column label="上游编号" min-width="170"><template #default="{ row }">{{ row.upstream_task_id || row.remote_instance_id || '未取得' }}</template></el-table-column>
      <el-table-column prop="last_error" label="异常码" min-width="260" /><el-table-column prop="updated_at" label="更新时间 (UTC)" width="180" />
    </el-table>
    <el-pagination v-if="data" class="task-pagination" :current-page="page" :page-size="data.per_page" :total="data.total" layout="prev, pager, next, total" :pager-count="5" :disabled="busy" @current-change="paginate" />
  </el-card>
</template>
