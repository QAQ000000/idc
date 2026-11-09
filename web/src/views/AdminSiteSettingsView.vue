<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { onBeforeRouteLeave } from "vue-router";
import { ElMessageBox } from "element-plus";
import { adminApi } from "../api/admin";
import { message } from "../api/request";
import { siteDefaults, siteFields, type SiteSettings } from "../api/siteSettings";
import { useSettingsDraft } from "../composables/useSettingsDraft";
import { useAdmin } from "../stores/admin";
import { useSite } from "../stores/site";

const account = useAdmin(), site = useSite();
const writable = computed(() => account.admin?.role === "super_admin"), endpoint = "/site-settings";
let disposed = false;
const settings = reactive(useSettingsDraft<SiteSettings>(siteFields, {
  read: async () => {
    const value = await adminApi<SiteSettings>(endpoint);
    if (!disposed && writable.value) site.apply(value);
    return { value, options: undefined };
  },
  write: body => adminApi<{ saved: boolean }>(endpoint, { method: "PATCH", body }),
}));
const ready = ref(false), loading = ref(false), loadError = ref("");
const draft = computed(() => settings.draft!);
const changed = computed(() => settings.dirty || settings.busy || settings.needsReview);
async function load() {
  if (!writable.value || loading.value || ready.value) return;
  loading.value = true; loadError.value = "";
  try { const data = await adminApi<SiteSettings>(endpoint); if (!disposed && writable.value) { settings.initialize(data, undefined); site.apply(data); ready.value = true; } }
  catch (e) { if (!disposed) loadError.value = message(e); }
  finally { loading.value = false; }
}
async function adopt(keep: boolean) {
  if (settings.busy || !settings.canAdopt || !writable.value) return;
  if (!keep && settings.dirty) {
    try { await ElMessageBox.confirm("放弃未保存草稿，采用当前站点配置？", "核对站点配置", { confirmButtonText: "采用当前值", cancelButtonText: "保留草稿" }); } catch { return; }
  }
  if (!disposed && writable.value) settings.adopt(keep);
}
function save() {
  if (!writable.value || settings.busy || settings.needsReview || !settings.draft) return;
  for (const key of Object.keys(siteFields(settings.draft)) as (keyof ReturnType<typeof siteFields>)[]) settings.draft[key] = settings.draft[key].trim();
  void settings.save();
}
function beforeUnload(event: BeforeUnloadEvent) { if (changed.value) { event.preventDefault(); event.returnValue = ""; } }
watch(writable, value => { if (value) void load(); });
onBeforeRouteLeave(async () => {
  if (!changed.value) return true;
  try { await ElMessageBox.confirm("存在未保存草稿或待核对结果。离开不会撤销已发出请求，返回后请重新核对。", "离开站点配置？", { confirmButtonText: "离开页面", cancelButtonText: "留在页面", type: "warning" }); return true; } catch { return false; }
});
onMounted(() => { void load(); window.addEventListener("beforeunload", beforeUnload); });
onUnmounted(() => { disposed = true; window.removeEventListener("beforeunload", beforeUnload); });
</script>
<template>
  <section class="admin-query-page site-settings" aria-labelledby="site-title">
    <div class="page-heading"><div><p class="eyebrow">超级管理员 · 站点展示</p><h1 id="site-title">站点基础配置</h1><p class="muted">保存后统一用于导航、页脚和浏览器标题，无需重新构建。</p></div></div>
    <el-alert v-if="!writable" title="仅超级管理员可查看和修改站点配置" type="warning" :closable="false" />
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" role="alert" />
    <el-button v-if="writable && !ready" :loading="loading" @click="load">重新加载配置</el-button>
    <el-card v-if="writable && ready && settings.saved && settings.draft" shadow="never">
      <template #header><h2>基础资料</h2></template>
      <p class="saved-summary">当前站名：{{ settings.saved.site_name || `${siteDefaults.site_name}（默认）` }} · {{ settings.saved.configured ? '已保存配置' : '使用默认值' }}</p>
      <p v-if="settings.dirty" role="status">有未保存内容；草稿不会影响公开页面。</p>
      <el-alert v-if="settings.error" :title="settings.error" type="error" :closable="false" role="alert" />
      <el-alert v-if="settings.notice" :title="settings.notice" type="success" :closable="false" role="status" />
      <div class="actions section-actions">
        <el-button type="primary" :loading="settings.saving" :disabled="settings.busy || settings.needsReview" @click="save">保存站点配置</el-button>
        <el-button :loading="settings.checking" :disabled="settings.busy" @click="settings.check">核对保存结果</el-button>
      </div>
      <div v-if="settings.observed && settings.needsReview" class="reconciliation">
        <h3>最近读到的配置</h3>
        <dl><template v-for="(value, field) in siteFields(settings.observed)" :key="field"><dt>{{ {site_name:'站点名称',logo_path:'Logo 路径',company_name:'公司主体',copyright_text:'版权文本',icp_number:'备案号'}[field] }}</dt><dd>{{ value || '空值（使用默认展示）' }}</dd></template></dl>
        <div class="actions"><el-button :disabled="!settings.canAdopt || settings.busy" @click="adopt(true)">保留草稿并采用当前版本</el-button><el-button :disabled="!settings.canAdopt || settings.busy" @click="adopt(false)">采用当前配置</el-button></div>
      </div>
      <el-form label-position="top" :disabled="settings.busy || settings.needsReview" @submit.prevent="save">
        <el-form-item label="站点名称"><el-input v-model="draft.site_name" aria-label="站点名称" maxlength="80" :placeholder="siteDefaults.site_name" /></el-form-item>
        <el-form-item label="Logo 静态资源路径"><el-input v-model="draft.logo_path" aria-label="Logo 静态资源路径" maxlength="255" placeholder="/images/logo.png" /></el-form-item>
        <p class="muted">仅使用已部署的本站绝对静态路径，如 /images/logo.png；支持 PNG、JPG、GIF、WebP、SVG、ICO。不上传文件，不接受外部地址或查询参数；图片加载失败显示默认标识。</p>
        <details>
          <summary>公司、版权与备案资料</summary>
          <el-form-item label="公司主体"><el-input v-model="draft.company_name" aria-label="公司主体" maxlength="120" /></el-form-item>
          <el-form-item label="版权文本"><el-input v-model="draft.copyright_text" aria-label="版权文本" maxlength="200" :placeholder="siteDefaults.copyright_text" /></el-form-item>
          <el-form-item label="备案号"><el-input v-model="draft.icp_number" aria-label="备案号" maxlength="80" /></el-form-item>
        </details>
        <p class="muted">以上资料均公开显示，请勿填写密钥或内部信息。空站名／版权使用默认文案，空公司／备案隐藏；不会更改支付、邮件、上游连接或登录安全规则。</p>
      </el-form>
    </el-card>
  </section>
</template>
<style scoped>
.section-actions { margin: 16px 0; }
.saved-summary, dd { overflow-wrap: anywhere; }
details summary { cursor: pointer; margin-bottom: 16px; }
.reconciliation { padding: 12px; margin: 12px 0; border: 1px solid var(--el-border-color); border-radius: 8px; }
.reconciliation dl { display: grid; grid-template-columns: minmax(70px, 100px) minmax(0, 1fr); gap: 8px; }
.reconciliation dd { margin: 0; }
</style>
