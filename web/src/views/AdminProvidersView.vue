<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAdmin } from "../stores/admin";
import { adminApi, type Page } from "../api/admin";
import type { ProviderConnection } from "../api/adminProviders";
import { message } from "../api/request";
const route = useRoute(), router = useRouter(), account = useAdmin();
const writable = computed(() => account.admin?.role === "super_admin");
const filters = reactive(Object.fromEntries(["name", "driver", "status"].map(key => [key, typeof route.query[key] === "string" ? route.query[key] : ""])) as Record<string, string>);
const data = ref<Page<ProviderConnection>>(), busy = ref(false), error = ref("");
async function load() {
  if (busy.value) return;
  busy.value = true; data.value = undefined; error.value = "";
  try { data.value = await adminApi<Page<ProviderConnection>>("/provider-connections?" + new URLSearchParams({ ...filters, page: String(route.query.page || "1"), per_page: String(route.query.per_page || "20") })); }
  catch (e) { error.value = message(e); } finally { busy.value = false; }
}
async function search(page = 1) {
  const target = { path: "/admin/providers", query: { ...filters, page: String(page), per_page: String(route.query.per_page || "20") } };
  if (router.resolve(target).fullPath === route.fullPath) await load(); else await router.push(target);
}
function clear() { for (const key of Object.keys(filters)) filters[key] = ""; void search(); }
onMounted(load);
</script>
<template>
  <section class="admin-query-page">
    <div class="page-heading"><div><p class="eyebrow">运营工作区 · 已安装驱动</p><h1>上游接入</h1><p class="muted">连接账号与资源绑定固定；换账号请新建连接。</p></div><el-button v-if="writable" type="primary" @click="router.push('/admin/providers/new')">新增上游连接</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-card shadow="never">
<el-form class="task-filters" label-position="top" @submit.prevent="search()">
      <el-form-item label="名称（包含）"><el-input v-model="filters.name" aria-label="上游名称筛选" maxlength="50" /></el-form-item>
      <el-form-item label="驱动标识"><el-input v-model="filters.driver" aria-label="上游驱动筛选" maxlength="50" /></el-form-item>
      <el-form-item label="启用状态"><el-select v-model="filters.status" aria-label="上游状态筛选"><el-option label="全部" value="" /><el-option label="启用" value="1" /><el-option label="禁用" value="0" /></el-select></el-form-item>
      <el-form-item label="操作"><el-button native-type="submit" type="primary" :disabled="busy">查询</el-button><el-button :disabled="busy" @click="clear">清空</el-button><el-button :loading="busy" @click="load">刷新列表</el-button></el-form-item>
    </el-form>
</el-card>
    <el-card class="query-results" shadow="never">
      <el-table v-if="!error" v-loading="busy" :data="data?.items || []" empty-text="没有符合条件的上游连接" row-key="id">
        <el-table-column label="上游连接" min-width="220"><template #default="{ row }"><RouterLink :to="`/admin/providers/${row.id}`">#{{ row.id }} · {{ row.name }}</RouterLink></template></el-table-column>
        <el-table-column prop="driver" label="驱动" min-width="100" />
        <el-table-column label="状态" min-width="100"><template #default="{ row }">{{ row.status ? '启用' : '禁用' }}</template></el-table-column>
        <el-table-column label="配置状态" min-width="180"><template #default="{ row }">{{ !row.driver_installed ? '驱动不可用' : row.configuration_complete ? '已配置（未代表验证通过）' : '配置不完整／不支持' }}</template></el-table-column>
        <el-table-column prop="product_count" label="商品数" width="100" /><el-table-column prop="instance_count" label="实例数" width="100" />
      </el-table>
      <el-button v-if="error" @click="load">重新加载列表</el-button>
      <el-pagination v-if="data" class="task-pagination" :current-page="data.page" :page-size="data.per_page" :total="data.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy" @current-change="search" />
    </el-card>
  </section>
</template>
