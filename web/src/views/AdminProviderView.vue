<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAdmin } from "../stores/admin";
import { adminApi } from "../api/admin";
import { providerActions, type ProviderDetail, type ConnectionVerification } from "../api/adminProviders";
import { message } from "../api/request";
import UpstreamCatalogManager from "../components/UpstreamCatalogManager.vue";
const section = ref("overview"), catalogVisited = ref(false);
watch(section, value => { if (value === "catalog") catalogVisited.value = true; });
let requestVersion = 0;
const account = useAdmin(), route = useRoute(), router = useRouter();
const data = ref<ProviderDetail>(), loading = ref(false), busy = ref(false), error = ref(""), operationError = ref("");
const name = ref(""), evidence = ref(""), pid = ref(""), cycle = ref("monthly"), verification = ref<ConnectionVerification>();
const auditLabels: Record<string, string> = { evidence: "依据", before_name: "原名称", after_name: "名称", remote_pid: "商品 PID", billing_cycle: "账期", outcome: "结果", source: "来源" };
function auditValue(key: string, value: string | number): string | number {
  if (key === "outcome") return value === "verified" ? "读取成功" : "未能读取配置或报价";
  if (key === "source" && value === "admin_web") return "管理后台";
  if (key === "billing_cycle") return value === "monthly" ? "月付" : "年付";
  return value;
}
const writable = computed(() => account.admin?.role === "super_admin");
async function load() {
  const version = ++requestVersion;
  loading.value = true; error.value = ""; verification.value = undefined;
  try {
    const result = await adminApi<ProviderDetail>(`/provider-connections/${route.params.id}?` + new URLSearchParams({ audit_page: String(route.query.audit_page || "1") }));
    if (version !== requestVersion) return;
    data.value = result; name.value = result.name;
  } catch (e) { if (version === requestVersion) error.value = message(e); } finally { if (version === requestVersion) loading.value = false; }
}
async function rename() {
  if (!data.value || busy.value) return;
  busy.value = true; operationError.value = "";
  try {
    await adminApi(`/provider-connections/${data.value.id}`, { method: "PATCH", body: { name: name.value, evidence: evidence.value, expected_version: data.value.version } });
    evidence.value = ""; await load();
  } catch (e) { operationError.value = message(e) + "；请刷新详情后重新确认，不要直接重发旧操作。"; } finally { busy.value = false; }
}
async function verify() {
  if (!data.value || busy.value) return;
  busy.value = true; verification.value = undefined; operationError.value = "";
  try {
    const result = await adminApi<ConnectionVerification>(`/provider-connections/${data.value.id}/verify`, { method: "POST", body: { remote_pid: pid.value, billing_cycle: cycle.value, expected_version: data.value.version } });
    await load(); if (data.value) verification.value = result;
  } catch (e) { operationError.value = message(e) + "；未执行购买或付款，请刷新详情核对验证审计。"; } finally { busy.value = false; }
}
async function turn(page: number) { await router.push({ path: route.path, query: { audit_page: String(page) } }); }
watch(() => route.query.audit_page, load);
onMounted(load);
onBeforeUnmount(() => ++requestVersion);
</script>
<template>
  <section class="admin-query-page">
    <RouterLink class="back-link" to="/admin/providers">← 返回上游接入</RouterLink>
    <div class="page-heading"><div><p class="eyebrow">连接详情 · 固定资源命名空间</p><h1>上游连接 #{{ route.params.id }}</h1></div><el-button :loading="loading" :disabled="busy" @click="load">刷新详情</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="operationError" :title="operationError" type="error" :closable="false" role="alert" />
    <el-card v-if="loading && !data" shadow="never"><el-skeleton :rows="6" animated /></el-card>
    <template v-if="data">
      <p class="muted">{{ data.name }} · {{ data.status ? "启用" : "禁用" }} · {{ data.driver }}</p>
      <el-tabs v-model="section" aria-label="上游连接分区">
        <el-tab-pane label="连接概况与维护" name="overview">
      <el-card shadow="never">
<h2>连接与绑定</h2><el-descriptions :column="1" border>
        <el-descriptions-item label="名称">{{ data.name }}</el-descriptions-item><el-descriptions-item label="驱动">{{ data.driver }} · {{ data.driver_installed ? '已安装' : '不可用' }}</el-descriptions-item>
        <el-descriptions-item label="启用状态">{{ data.status ? '启用' : '禁用' }}</el-descriptions-item>
        <el-descriptions-item label="配置状态">{{ data.configuration_complete ? '已配置（不代表验证通过）' : '配置不完整／不支持表单' }}</el-descriptions-item>
        <el-descriptions-item v-for="f in data.configuration_fields" :key="f.key" :label="f.label">{{ f.configured ? '已配置' : '未配置' }}</el-descriptions-item>
        <el-descriptions-item label="关联商品"><RouterLink :to="{ path: '/admin/products', query: { provider_id: String(data.id) } }">查看 {{ data.product_count }} 个商品</RouterLink></el-descriptions-item>
        <el-descriptions-item label="关联实例"><RouterLink :to="{ path: '/admin/instances', query: { provider_id: String(data.id) } }">查看 {{ data.instance_count }} 个实例</RouterLink></el-descriptions-item>
        <el-descriptions-item label="创建时间（UTC）">{{ data.created_at }}</el-descriptions-item>
      </el-descriptions><p class="muted">账号、地址与驱动不能在此替换；换账号请新建连接，原资源仍由原连接处理。</p>
</el-card>
      <el-card v-if="writable" class="query-results" shadow="never">
<h2>修改显示名称</h2><el-form label-position="top" :disabled="busy || loading" @submit.prevent="rename">
        <el-form-item label="连接名称"><el-input v-model="name" aria-label="连接名称" maxlength="50" required /></el-form-item>
        <el-form-item label="修改依据（不填凭据）"><el-input v-model="evidence" aria-label="连接改名依据" type="textarea" :rows="2" maxlength="1000" required /></el-form-item>
        <el-button native-type="submit" :loading="busy">保存显示名称</el-button>
      </el-form>
</el-card>
      <el-card v-if="writable" class="query-results" shadow="never">
<h2>只读连接验证</h2><p class="muted">读取指定商品的默认配置与一个账期的报价；不会下单、支付或刷新商品快照。</p>
        <el-alert v-if="!data.snapshot_supported || !data.status" title="当前连接不支持在线配置验证或已禁用" type="info" :closable="false" />
        <el-form v-else class="task-filters" label-position="top" :disabled="busy || loading" @submit.prevent="verify">
          <el-form-item label="上游商品 PID"><el-input v-model="pid" aria-label="连接验证商品 PID" inputmode="numeric" required /></el-form-item>
          <el-form-item label="报价账期"><el-select v-model="cycle" aria-label="连接验证账期"><el-option label="月付" value="monthly" /><el-option label="年付" value="annually" /></el-select></el-form-item>
          <el-form-item label="操作"><el-button native-type="submit" type="primary" :loading="busy">读取配置与报价</el-button></el-form-item>
        </el-form>
        <template v-if="verification"><el-alert title="本次配置与报价读取成功" type="success" :closable="false" /><p>商品 #{{ verification.remote_pid }} · {{ verification.field_count }} 个配置项 · 首期 ¥{{ verification.first_price }}／周期 ¥{{ verification.recurring_price }}（CNY）</p><p class="muted">验证时间 {{ verification.verified_at }} UTC；这是本次查询结果，不保证后续可售或交付。</p></template>
        <RouterLink v-if="data.status && data.snapshot_supported" class="back-link" :to="{ path: '/admin/products/import', query: { provider_id: String(data.id), ...(verification ? { remote_pid: String(verification.remote_pid) } : {}) } }">进入商品预览与导入</RouterLink>
      </el-card>
      <el-card class="query-results" shadow="never">
<h2>操作审计</h2>
        <el-table :data="data.audit.items" empty-text="暂无连接操作记录" row-key="id">
          <el-table-column label="操作" min-width="120"><template #default="{ row }">#{{ row.id }} · {{ providerActions[row.action] || row.action }}</template></el-table-column>
          <el-table-column label="管理员" min-width="140"><template #default="{ row }">{{ row.username || '原管理员已删除' }}<template v-if="row.admin_id"> (#{{ row.admin_id }})</template></template></el-table-column>
          <el-table-column label="记录" min-width="280"><template #default="{ row }"><p v-for="(value, key) in row.context" :key="key">{{ auditLabels[key] || key }}：{{ auditValue(String(key), value) }}</p></template></el-table-column>
          <el-table-column prop="created_at" label="时间（UTC）" min-width="185" />
        </el-table>
        <el-pagination class="task-pagination" :current-page="data.audit.page" :page-size="data.audit.per_page" :total="data.audit.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy || loading" @current-change="turn" />
      </el-card>
        </el-tab-pane>
        <el-tab-pane v-if="writable" label="上游商品与选品导入" name="catalog">
          <UpstreamCatalogManager v-if="catalogVisited && data.catalog_supported" :provider-id="data.id" :enabled="!!data.status" />
          <el-alert v-else title="当前驱动不支持目录发现，可继续从连接概况按 PID 预览导入" type="info" :closable="false" />
        </el-tab-pane>
      </el-tabs>
    </template>
    <el-button v-if="error" @click="load">重新加载连接详情</el-button>
  </section>
</template>
