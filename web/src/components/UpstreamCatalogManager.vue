<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { adminApi } from "../api/admin";
import type { UpstreamCatalog, UpstreamCatalogItem } from "../api/adminProviders";
import { message } from "../api/request";
import AdminSnapshotForm from "./AdminSnapshotForm.vue";
const props = defineProps<{ providerId: number; enabled: boolean }>();
const catalog = ref<UpstreamCatalog>(), loading = ref(false), error = ref("");
const search = ref(""), category = ref(""), onlyNew = ref(false), onlyStock = ref(false), page = ref(1);
const selected = ref<UpstreamCatalogItem>(), drawer = ref(false), formState = ref({ busy: false, unknown: false });
const localLabels: Record<string,string> = { hidden: "下架", active: "在售", retired: "停售" };
const types: Record<string,string> = { cloud: "云服务器", dedicated: "独立服务器", cdn: "CDN", hosting: "虚拟主机", software: "软件", unsupported: "需人工核验" };
const stockLabels = { in_stock: "有货", sold_out: "售罄", unlimited: "不限", unknown: "未知" };
let requestVersion = 0;
const filtered = computed(() => (catalog.value?.items || []).filter(item => (!category.value || item.category === category.value)
  && (!onlyNew.value || !item.imported) && (!onlyStock.value || ["in_stock", "unlimited"].includes(item.stock_state))
  && (!search.value.trim() || `${item.remote_pid} ${item.name}`.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()))));
const rows = computed(() => filtered.value.slice((page.value - 1) * 20, page.value * 20));
watch([search, category, onlyNew, onlyStock], () => page.value = 1);
watch(filtered, value => { if ((page.value - 1) * 20 >= value.length) page.value = 1; });
watch(() => props.enabled, enabled => { if (!enabled) { ++requestVersion; catalog.value = undefined; loading.value = false; } });
async function load(refresh = false) {
  if (loading.value || !props.enabled) return;
  const version = ++requestVersion;
  loading.value = true; error.value = "";
  try {
    const value = await adminApi<UpstreamCatalog>(`/provider-connections/${props.providerId}/catalog?refresh=${refresh ? 1 : 0}`);
    if (version === requestVersion) catalog.value = value;
  } catch (e) { if (version === requestVersion) { catalog.value = undefined; error.value = message(e); } }
  finally { if (version === requestVersion) loading.value = false; }
}
function open(item: UpstreamCatalogItem) {
  if (formState.value.busy || !props.enabled) return;
  if (!formState.value.unknown) { selected.value = item; formState.value = { busy: false, unknown: false }; }
  drawer.value = true;
}
function beforeClose(done: () => void) { if (!formState.value.busy) done(); }
function closed() { if (!formState.value.unknown) selected.value = undefined; }
async function saved() { drawer.value = false; selected.value = undefined; formState.value = { busy: false, unknown: false }; await load(); }
onMounted(() => load());
onBeforeUnmount(() => ++requestVersion);
</script>
<template>
  <section class="catalog-manager" aria-label="上游商品目录">
    <el-alert title="候选不代表可售；导入仍需完整配置预览与核价。库存为目录观察，不是实时预留。" type="info" :closable="false" />
    <div class="catalog-actions"><el-button :loading="loading" :disabled="!enabled" @click="load()">刷新本地导入状态</el-button><el-button :disabled="loading || !enabled" @click="load(true)">强制同步上游目录</el-button></div>
    <p v-if="catalog" class="muted">{{ catalog.items.length }} 个候选 · {{ catalog.cache_hit ? '命中目录缓存' : '本次已同步' }} · 观察时间 {{ catalog.cached_at }} UTC（最长缓存10分钟）。强制同步间隔30秒。</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="!enabled" title="上游已禁用，不提供目录或新的预填导入" type="warning" :closable="false" />
    <el-alert v-if="formState.unknown" title="有一笔导入结果待核对，已保留原确认；不要重新导入其他商品。" type="warning" :closable="false"><el-button @click="drawer = true">重新打开原导入</el-button></el-alert>
    <el-form class="catalog-filters" label-position="top" @submit.prevent>
      <el-form-item label="分类"><el-select v-model="category" aria-label="目录分类" placeholder="全部分类"><el-option label="全部分类" value="" /><el-option v-for="group in catalog?.categories" :key="group.id" :label="`${group.name || '未分类'} (${group.item_count})`" :value="group.name" /></el-select></el-form-item>
      <el-form-item label="名称或 PID"><el-input v-model="search" aria-label="目录搜索" clearable placeholder="搜索候选名称或 PID" /></el-form-item>
      <el-form-item label="快捷筛选"><div><el-checkbox v-model="onlyNew">仅看未导入</el-checkbox><el-checkbox v-model="onlyStock">仅看有货或不限</el-checkbox></div></el-form-item>
    </el-form>
    <el-skeleton v-if="loading && !catalog" :rows="5" animated />
    <p v-if="catalog">当前匹配 {{ filtered.length }} 项</p>
    <el-table v-if="catalog" :data="rows" row-key="remote_pid" empty-text="没有匹配的候选商品" class="catalog-table">
      <el-table-column label="候选商品" min-width="220"><template #default="{ row }"><strong>#{{ row.remote_pid }} · {{ row.name }}</strong><p>{{ row.category }} · {{ types[row.type] || '需人工核验' }}<span v-if="row.hidden"> · 上游隐藏</span></p><p class="catalog-description">{{ row.description }}</p></template></el-table-column>
      <el-table-column label="观察库存" width="110"><template #default="{ row }">{{ stockLabels[row.stock_state as keyof typeof stockLabels] }}<span v-if="row.stock_qty !== null"> · {{ row.stock_qty }}</span></template></el-table-column>
      <el-table-column label="本地导入状态" min-width="150"><template #default="{ row }"><RouterLink v-if="row.imported" :to="`/admin/products/${row.local_product_id}`">已导入 #{{ row.local_product_id }} · {{ localLabels[row.local_status] }}</RouterLink><el-button v-else :disabled="!enabled || formState.busy || formState.unknown || row.type === 'unsupported'" @click="open(row)">预填导入 #{{ row.remote_pid }}</el-button></template></el-table-column>
    </el-table>
    <div v-if="catalog" class="catalog-cards"><el-card v-for="item in rows" :key="item.remote_pid" shadow="never"><h3>#{{ item.remote_pid }} · {{ item.name }}</h3><p>{{ item.category }} · {{ types[item.type] }}</p><p>{{ stockLabels[item.stock_state] }}<span v-if="item.stock_qty !== null"> · {{ item.stock_qty }}</span><span v-if="item.hidden"> · 上游隐藏</span></p><p class="catalog-description">{{ item.description }}</p><RouterLink v-if="item.imported" :to="`/admin/products/${item.local_product_id}`">已导入 #{{ item.local_product_id }} · {{ localLabels[item.local_status || ''] }}</RouterLink><el-button v-else :disabled="!enabled || formState.busy || formState.unknown || item.type === 'unsupported'" @click="open(item)">预填导入 #{{ item.remote_pid }}</el-button></el-card></div>
    <el-pagination v-if="catalog" v-model:current-page="page" :page-size="20" :total="filtered.length" :pager-count="5" layout="prev, pager, next" />
    <p class="muted">刷新浏览器会清除内存草稿，已导入商品可重新读取核对；本批不支持批量导入。</p>
    <el-drawer v-model="drawer" title="从候选预填单品导入" class="catalog-import-drawer" size="min(640px, 100vw)" :close-on-click-modal="!formState.busy" :close-on-press-escape="!formState.busy" :show-close="!formState.busy" :before-close="beforeClose" @closed="closed">
      <template v-if="selected"><p>当前连接 #{{ providerId }} · 候选 #{{ selected.remote_pid }}</p><AdminSnapshotForm :key="selected.remote_pid" :initial="{ provider_id: providerId, remote_pid: selected.remote_pid, name: selected.name, type: selected.type }" embedded @saved="saved" @state="formState = $event" /><el-button v-if="!formState.busy" @click="load()">只读核对目录导入状态</el-button></template>
    </el-drawer>
  </section>
</template>
<style scoped>
.catalog-actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.catalog-actions :deep(.el-button+.el-button){margin-left:0}.catalog-filters{display:grid;grid-template-columns:1fr 1fr;gap:12px}.catalog-filters .el-form-item{min-width:0}.catalog-description{white-space:pre-line;overflow-wrap:anywhere;color:var(--el-text-color-secondary);font-size:13px;max-height:6em;overflow:auto}.catalog-table p{margin:6px 0}.catalog-cards{display:none}.catalog-manager :deep(.el-pagination){margin-top:16px}.catalog-manager :deep(.el-checkbox){margin-right:12px}
@media(max-width:600px){.catalog-filters{grid-template-columns:1fr;gap:0}.catalog-table{display:none}.catalog-cards{display:grid;gap:12px}.catalog-cards h3{font-size:16px;overflow-wrap:anywhere;margin-top:0}}
</style>
