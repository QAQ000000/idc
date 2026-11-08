<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { adminApi, type Page } from "../api/admin";
import { customerLabels, type AdminInstance, type Customer } from "../api/adminQueries";
import { message } from "../api/request";
import AdminInstanceTable from "../components/AdminInstanceTable.vue";
const props = defineProps<{ kind: "customers" | "instances" }>();
const route = useRoute(), router = useRouter();
const customers = computed(() => props.kind === "customers");
const fields = computed(() => customers.value ? { user_id: "客户 ID", email: "客户邮箱（精确）" } : { instance_id: "实例 ID", name: "实例名称（包含）", ip_address: "IP 地址（精确）", remote_instance_id: "上游资源编号（精确）", user_id: "客户 ID" });
const filters = reactive(Object.fromEntries(Object.keys(fields.value).map(key => [key, typeof route.query[key] === "string" ? route.query[key] : ""])) as Record<string, string>);
const page = ref(String(route.query.page || "1")), size = String(route.query.per_page || "20");
const data = ref<Page<Customer | AdminInstance>>(), busy = ref(false), error = ref("");
async function load() {
  if (busy.value) return;
  busy.value = true; error.value = ""; data.value = undefined;
  try { data.value = await adminApi<Page<Customer | AdminInstance>>(`/${props.kind}?` + new URLSearchParams({ ...filters, page: page.value, per_page: size })); }
  catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
async function search(next = 1) {
  const target = { path: `/admin/${props.kind}`, query: { ...filters, page: String(next), per_page: size } };
  if (router.resolve(target).fullPath === route.fullPath) await load();
  else await router.push(target);
}
function clear() { for (const key of Object.keys(filters)) filters[key] = ""; void search(); }
onMounted(load);
</script>
<template>
  <section class="admin-query-page">
    <div class="page-heading"><div><p class="eyebrow">运营工作区 · 只读</p><h1>{{ customers ? '客户查询' : '实例查询' }}</h1><p class="muted">{{ customers ? '按客户 ID 或完整邮箱定位账号，查看关联实例。' : '查询本地服务记录；服务状态不代表实时电源或上游可用性。' }}</p></div><el-button :loading="busy" @click="load">刷新列表</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-card shadow="never">
      <el-form class="task-filters" label-position="top" @submit.prevent="search()">
        <el-form-item v-for="(label, key) in fields" :key="key" :label="label"><el-input v-model="filters[key]" :aria-label="label" :maxlength="key === 'ip_address' ? 45 : 100" :inputmode="key.endsWith('_id') && key !== 'remote_instance_id' ? 'numeric' : 'text'" /></el-form-item>
        <el-form-item label="操作"><el-button native-type="submit" type="primary" :disabled="busy">查询</el-button><el-button :disabled="busy" @click="clear">清空</el-button></el-form-item>
      </el-form>
    </el-card>
    <el-card class="query-results" shadow="never">
      <div v-if="!error" v-loading="busy">
        <AdminInstanceTable v-if="!customers" :rows="(data?.items || []) as AdminInstance[]" />
        <el-table v-else :data="data?.items || []" empty-text="没有符合条件的客户" row-key="id">
          <el-table-column label="客户" min-width="260"><template #default="{ row }"><RouterLink :to="`/admin/customers/${row.id}`">#{{ row.id }} · {{ row.email }}</RouterLink></template></el-table-column>
          <el-table-column label="账号状态" min-width="120"><template #default="{ row }">{{ customerLabels[row.status] || row.status }}</template></el-table-column>
          <el-table-column prop="created_at" label="注册时间（UTC）" min-width="185" />
        </el-table>
      </div>
      <el-button v-if="error" @click="load">重新加载列表</el-button>
      <el-pagination v-if="data" class="task-pagination" :current-page="data.page" :page-size="data.per_page" :total="data.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy" @current-change="search" />
    </el-card>
  </section>
</template>
