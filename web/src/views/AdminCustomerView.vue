<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { adminApi } from "../api/admin";
import { customerLabels, type CustomerDetail } from "../api/adminQueries";
import { message } from "../api/request";
import AdminInstanceTable from "../components/AdminInstanceTable.vue";
import AdminRelatedLinks from "../components/AdminRelatedLinks.vue";
import AdminCustomerAccountActions from "../components/AdminCustomerAccountActions.vue";
const route = useRoute(), router = useRouter(), data = ref<CustomerDetail>(), busy = ref(false), error = ref("");
let requestVersion=0;
async function load() {
  const current=++requestVersion;
  busy.value = true; error.value = "";
  try {
    const result=await adminApi<CustomerDetail>(`/customers/${route.params.id}?` + new URLSearchParams({ page: String(route.query.page || "1"), per_page: String(route.query.per_page || "20") }));
    if(current===requestVersion)data.value=result;
  }
  catch (e) { if(current===requestVersion)error.value = message(e); }
  finally { if(current===requestVersion)busy.value = false; }
}
async function turn(page: number) { await router.push({ path: route.path, query: { ...route.query, page: String(page) } }); }
onMounted(load);
watch(()=>[route.query.page,route.query.per_page],load);
onBeforeUnmount(()=>requestVersion++);
</script>
<template>
  <section class="admin-query-page">
    <RouterLink class="back-link" to="/admin/customers">← 返回客户查询</RouterLink>
    <div class="page-heading"><div><p class="eyebrow">客户资料</p><h1>客户 #{{ route.params.id }}</h1></div><div class="customer-heading-actions"><AdminCustomerAccountActions v-if="data" :customer="data.customer" @changed="load" /><el-button :loading="busy" @click="load">刷新客户详情</el-button></div></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-card v-if="busy&&!data" shadow="never"><el-skeleton :rows="5" animated /></el-card>
    <template v-if="data">
      <el-card shadow="never">
<h2>账号信息</h2><el-descriptions :column="1" border>
        <el-descriptions-item label="客户 ID">#{{ data.customer.id }}</el-descriptions-item>
        <el-descriptions-item label="邮箱">{{ data.customer.email }}</el-descriptions-item>
        <el-descriptions-item label="账号状态">{{ customerLabels[data.customer.status] || data.customer.status }}</el-descriptions-item>
        <el-descriptions-item label="注册时间（UTC）">{{ data.customer.created_at }}</el-descriptions-item>
      </el-descriptions><h2>关联定位</h2><AdminRelatedLinks :user-id="data.customer.id" />
</el-card>
      <el-card class="query-results" shadow="never">
<h2>关联实例</h2><AdminInstanceTable :rows="data.instances.items" />
        <el-pagination class="task-pagination" :current-page="data.instances.page" :page-size="data.instances.per_page" :total="data.instances.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy" @current-change="turn" />
      </el-card>
    </template>
    <el-button v-if="error" @click="load">重新加载客户详情</el-button>
  </section>
</template>
<style scoped>
.customer-heading-actions{display:flex;gap:10px;flex-wrap:wrap}.customer-heading-actions :deep(.el-button+.el-button){margin-left:0}
</style>
