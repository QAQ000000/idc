<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { adminApi, type Page, type Task } from "../api/admin";
import { message } from "../api/request";
import AdminTaskFilters from "../components/AdminTaskFilters.vue";
const route = useRoute(), router = useRouter();
const fromQuery = (key: string, fallback = "") => typeof route.query[key] === "string" ? route.query[key] as string : fallback;
const applied = {
  status: fromQuery("status", "attention"), action: fromQuery("action"),
  task_id: fromQuery("task_id"), invoice_id: fromQuery("invoice_id"),
  instance_id: fromQuery("instance_id"), user_id: fromQuery("user_id"),
};
const filters = ref({ ...applied }), quickTask = ref(applied.task_id), drawerOpen = ref(false);
const compactQuery = window.matchMedia("(max-width: 1023px)");
const compact = ref(compactQuery.matches);
function syncCompact() { compact.value = compactQuery.matches; if (!compact.value) drawerOpen.value = false; }
function openFilters() { filters.value = { ...applied }; drawerOpen.value = true; }
const labels: Record<keyof typeof applied, string> = { status: "状态", action: "类型", task_id: "任务", invoice_id: "账单", instance_id: "实例", user_id: "客户" };
const values: Record<string, string> = { attention: "异常关注", manual: "待人工核验", create: "新购", power: "电源操作", reinstall: "重装系统", renew: "续费" };
const chips = computed(() => (Object.keys(applied) as (keyof typeof applied)[])
  .filter(key => applied[key] && !(key === "status" && applied[key] === "all"))
  .map(key => ({ key, text: `${labels[key]}：${values[applied[key]] || applied[key]}` })));
const page = ref(Number(route.query.page) || 1), data = ref<Page<Task>>(), busy = ref(false), error = ref("");
async function load(nextPage = page.value) {
  if (busy.value) return;
  page.value = nextPage; busy.value = true; error.value = ""; data.value = undefined;
  try {
    const query = new URLSearchParams({ ...applied, page: String(page.value) });
    data.value = await adminApi<Page<Task>>("/tasks?" + query);
  } catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
async function apply(next = filters.value) {
  drawerOpen.value = false;
  const target = { path: "/admin/tasks", query: { ...next, page: "1" } };
  if (router.resolve(target).fullPath === route.fullPath) await load(1);
  else await router.push(target);
}
function clear() { return apply({ status: "all", action: "", task_id: "", invoice_id: "", instance_id: "", user_id: "" }); }
function remove(key: keyof typeof applied) { return apply({ ...applied, [key]: key === "status" ? "all" : "" }); }
async function paginate(next: number) {
  await router.push({ path: "/admin/tasks", query: { ...applied, page: String(next) } });
}
onMounted(() => { compactQuery.addEventListener("change", syncCompact); void load(); });
onUnmounted(() => compactQuery.removeEventListener("change", syncCompact));
</script>
<template>
  <section class="admin-tasks-page">
  <h1>异常任务</h1>
  <div class="muted tasks-intro">
    未知结果先核验，请勿重复购买。时间均为 UTC。
    <details class="auxiliary-details"><summary>异常关注范围</summary>包括未结案的失败、调查中和租约失效的执行中任务；已结案记录可切换 failed 或 all 查看。</details>
  </div>
  <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
  <el-card>
    <div v-if="compact" class="task-mobile-toolbar">
      <el-form class="task-quick-search" @submit.prevent="apply({ ...applied, task_id: quickTask })">
        <el-input v-model="quickTask" aria-label="快速查询任务 ID" placeholder="任务 ID" inputmode="numeric" clearable />
        <el-button native-type="submit" :disabled="busy">查询</el-button>
      </el-form>
      <el-button type="primary" :aria-expanded="drawerOpen" @click="openFilters">筛选</el-button>
      <el-button :loading="busy" @click="load()">刷新</el-button>
    </div>
    <AdminTaskFilters v-else v-model="filters" :busy="busy" @apply="apply()" @reset="clear" />
    <div class="active-filters" aria-label="已生效筛选">
      <el-tag v-for="chip in chips" :key="chip.key" closable :disable-transitions="true" @close="remove(chip.key)">{{ chip.text }}</el-tag>
      <span v-if="!chips.length" class="muted">全部任务</span>
      <el-button v-if="chips.length" link :disabled="busy" @click="clear">清除筛选</el-button>
      <el-button v-if="!compact" link :loading="busy" @click="load()">刷新</el-button>
    </div>
    <el-table v-loading="busy" :data="data?.items || []" :empty-text="busy ? '正在查询任务…' : error ? '任务查询失败，请刷新重试' : '没有匹配的任务'" row-key="id" style="width:100%">
      <el-table-column label="任务" width="110"><template #default="{ row }"><RouterLink :to="`/admin/tasks/${row.id}`">#{{ row.id }} 详情</RouterLink></template></el-table-column>
      <el-table-column prop="action" label="类型" width="90" /><el-table-column label="状态" min-width="150"><template #default="{ row }">{{ row.reinstall_result === "execution_ended" ? "执行已结束（系统未验证）" : row.reinstall_result === "accepted" ? "上游已受理" : row.reinstall_result === "executing" ? "上游重装中" : row.status }}</template></el-table-column>
      <el-table-column label="账单 / 实例" width="150"><template #default="{ row }">账单 {{ row.invoice_id ? '#' + row.invoice_id : '—' }}<br><RouterLink :to="`/admin/instances/${row.instance_id}`">实例 #{{ row.instance_id }}</RouterLink></template></el-table-column>
      <el-table-column label="账单状态" width="130"><template #default="{ row }">{{ row.invoice_status || '无账单' }}<template v-if="row.total !== null"><br>¥{{ row.total }}</template></template></el-table-column>
      <el-table-column prop="product_name" label="商品" min-width="150" />
      <el-table-column label="上游编号" min-width="170"><template #default="{ row }">{{ row.upstream_task_id || row.remote_instance_id || '未取得' }}</template></el-table-column>
      <el-table-column prop="last_error" label="异常码" min-width="260" /><el-table-column prop="updated_at" label="更新时间 (UTC)" width="180" />
    </el-table>
    <el-pagination v-if="data" class="task-pagination" :current-page="page" :page-size="data.per_page" :total="data.total" layout="prev, pager, next, total" :pager-count="5" :disabled="busy" @current-change="paginate" />
  </el-card>
  <el-drawer v-model="drawerOpen" title="筛选任务" size="min(440px, 100vw)" class="task-filter-drawer">
    <AdminTaskFilters v-model="filters" :busy="busy" @apply="apply()" @reset="clear" />
    <el-button @click="drawerOpen = false">取消，不应用</el-button>
  </el-drawer>
  </section>
</template>
