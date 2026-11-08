<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import { useAdmin } from "../stores/admin";
import { adminApi } from "../api/admin";
import type { DriverCatalog } from "../api/adminProviders";
import { message } from "../api/request";
const account = useAdmin(), router = useRouter();
const catalog = ref<DriverCatalog>(), driver = ref(""), name = ref(""), evidence = ref(""), values = reactive<Record<string, string>>({});
const busy = ref(false), loading = ref(false), error = ref(""), attempted = ref(false);
const requestKey = crypto.randomUUID(), writable = computed(() => account.admin?.role === "super_admin");
const selected = computed(() => catalog.value?.items.find(d => d.id === driver.value));
function clearValues() { for (const key of Object.keys(values)) delete values[key]; }
async function load() {
  if (!writable.value || loading.value) return;
  loading.value = true; error.value = "";
  try { catalog.value = await adminApi<DriverCatalog>("/provider-drivers"); driver.value = catalog.value.items[0]?.id || ""; clearValues(); }
  catch (e) { error.value = message(e); } finally { loading.value = false; }
}
async function create() {
  if (busy.value || !catalog.value || !selected.value) return;
  busy.value = true; error.value = ""; attempted.value = true;
  const credentials = Object.fromEntries(selected.value.fields.filter(f => f.key !== "endpoint").map(f => [f.key, values[f.key] || ""]));
  const body = { name: name.value, driver: driver.value, ...(selected.value.fields.some(f => f.key === "endpoint") ? { endpoint: values.endpoint || "" } : {}), credentials, request_key: requestKey, catalog_version: catalog.value.version, evidence: evidence.value };
  try {
    const result = await adminApi<{ provider_id: number }>("/provider-connections", { method: "POST", body });
    await router.push(`/admin/providers/${result.provider_id}`);
  } catch (e) { error.value = message(e) + "；如果响应丢失，请先查询列表及审计，或在本页重新输入相同参数找回原连接。"; }
  finally { clearValues(); busy.value = false; }
}
onMounted(load);
</script>
<template>
  <section class="admin-query-page">
    <RouterLink class="back-link" to="/admin/providers">← 返回上游接入</RouterLink>
    <div class="page-heading"><div><p class="eyebrow">新连接 · 超级管理员</p><h1>新增上游连接</h1><p class="muted">保存后连接账号、地址和驱动固定。创建仅保存配置，不购买或支付。</p></div></div>
    <el-alert v-if="!writable" title="仅超级管理员可新增上游连接" type="warning" :closable="false" role="alert" />
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-card v-if="writable" v-loading="loading" shadow="never">
      <el-button v-if="!catalog" :loading="loading" @click="load">重新加载驱动表单</el-button>
      <el-form v-else label-position="top" :disabled="busy" @submit.prevent="create">
        <el-form-item label="连接名称"><el-input v-model="name" aria-label="连接名称" maxlength="50" required /></el-form-item>
        <el-form-item label="已安装驱动"><el-select v-model="driver" aria-label="已安装驱动" @change="clearValues"><el-option v-for="d in catalog.items" :key="d.id" :value="d.id" :label="d.id" /></el-select></el-form-item>
        <el-form-item v-for="f in selected?.fields || []" :key="f.key" :label="f.label">
          <el-input v-model="values[f.key]" :aria-label="f.label" :type="f.type === 'secret' ? 'password' : 'text'" :maxlength="f.max_length" :required="f.required" autocomplete="off" />
        </el-form-item>
        <el-form-item label="配置依据（不填凭据）"><el-input v-model="evidence" aria-label="连接配置依据" type="textarea" :rows="3" maxlength="1000" required /></el-form-item>
        <p class="muted">秘密参数加密保存且不回显，提交后清空输入。配置齐全不代表认证成功。</p>
        <p v-if="attempted" class="muted">本页保留本次提交身份；重试必须使用相同名称、账号、密钥及配置依据，换账号请另开新建页面。</p>
        <el-button native-type="submit" type="primary" :loading="busy" :disabled="!selected">保存新连接</el-button>
      </el-form>
    </el-card>
  </section>
</template>
