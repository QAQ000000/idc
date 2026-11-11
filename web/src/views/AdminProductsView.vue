<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { adminApi, type Page } from "../api/admin";
import { fetchAdminCategories, type CategoryItem } from "../api/adminCategories";
import { cycleLabels, salesLabels, type AdminProduct, type Provider } from "../api/adminProducts";
import { message } from "../api/request";
import { useAdmin } from "../stores/admin";
const account = useAdmin(), route = useRoute(), router = useRouter();
const filters = reactive({ status: String(route.query.status || ""), provider_id: String(route.query.provider_id || ""), name: String(route.query.name || "") });
const page = ref(Number(route.query.page) || 1), data = ref<Page<AdminProduct>>(), providers = ref<Provider[]>([]), busy = ref(false), error = ref("");
const categories = ref<CategoryItem[]>([]);
const categoryMap = computed(() => new Map(categories.value.map(c => [c.id, c.name])));
async function load() {
  busy.value = true; error.value = "";
  try {
    const [prodData, provData, catData] = await Promise.all([
      adminApi<Page<AdminProduct>>("/products?" + new URLSearchParams({ ...filters, page: String(page.value) })),
      adminApi<Provider[]>("/providers"),
      fetchAdminCategories(),
    ]);
    data.value = prodData;
    providers.value = provData;
    categories.value = catData.items;
  } catch (e) { error.value = message(e); data.value = undefined; }
  finally { busy.value = false; }
}
async function search(next = 1) {
  const target = { path: "/admin/products", query: { ...filters, page: String(next) } };
  if (router.resolve(target).fullPath === route.fullPath) await load(); else await router.push(target);
}
onMounted(load);
</script>
<template>
  <h1>商品管理</h1>
  <p class="muted">参考价对应快照默认配置；实时售价仍需重新报价。调价只影响后续新购，不改历史账单或实例续费价。</p>
  <div class="actions"><RouterLink v-if="account.admin?.role === 'super_admin'" to="/admin/products/import"><el-button type="primary">从已配置上游导入商品</el-button></RouterLink><el-button :loading="busy" @click="load">刷新列表</el-button></div>
  <el-alert v-if="error" :title="error" type="error" :closable="false" />
  <el-card>
    <el-form class="task-filters" label-position="top" @submit.prevent="search()">
      <el-form-item label="商品名称"><el-input v-model="filters.name" aria-label="商品名称筛选" maxlength="100" /></el-form-item>
      <el-form-item label="销售状态"><el-select v-model="filters.status" aria-label="销售状态筛选"><el-option label="全部状态" value="" /><el-option v-for="(label, key) in salesLabels" :key="key" :label="label" :value="key" /></el-select></el-form-item>
      <el-form-item label="上游绑定"><el-select v-model="filters.provider_id" aria-label="上游绑定筛选"><el-option label="全部上游" value="" /><el-option v-for="p in providers" :key="p.id" :label="`${p.name} (#${p.id})`" :value="String(p.id)" /></el-select></el-form-item>
      <el-form-item label="查询"><el-button native-type="submit" type="primary" :disabled="busy">筛选</el-button></el-form-item>
    </el-form>
    <el-table v-loading="busy" :data="data?.items || []" empty-text="没有匹配的商品">
      <el-table-column label="商品" min-width="190"><template #default="{ row }"><RouterLink :to="`/admin/products/${row.id}`">#{{ row.id }} {{ row.name }}</RouterLink></template></el-table-column>
      <el-table-column label="分类" width="130"><template #default="{ row }"><el-tag v-if="row.category_id" size="small">{{ categoryMap.get(row.category_id) || `#${row.category_id}` }}</el-tag><span v-else class="muted">未分类</span></template></el-table-column>
      <el-table-column label="上游 / 商品编号" min-width="200"><template #default="{ row }">{{ row.provider_name }} (#{{ row.provider_id }})<br>{{ row.driver }} · PID {{ row.remote_pid }}</template></el-table-column>
      <el-table-column label="销售状态" width="120"><template #default="{ row }">{{ salesLabels[row.status] }}<span v-if="!row.provider_status"> / 上游禁用</span></template></el-table-column>
      <el-table-column label="默认配置参考首期价" min-width="200"><template #default="{ row }"><div v-for="cycle in row.billing_cycles" :key="cycle">{{ cycleLabels[cycle] || cycle }} ¥{{ row.prices[cycle] }}</div><span v-if="!row.billing_cycles.length">暂无可售账期</span></template></el-table-column>
      <el-table-column label="加价规则" min-width="150"><template #default="{ row }">{{ row.markup_type === 'none' ? '不加价' : row.markup_type === 'fixed' ? `固定 +¥${row.markup_value}` : `百分比 +${row.markup_value}%` }}</template></el-table-column>
    </el-table>
    <el-pagination v-if="data" class="task-pagination" :current-page="page" :page-size="data.per_page" :total="data.total" :pager-count="5" layout="prev, pager, next, total" @current-change="search" />
  </el-card>
</template>
