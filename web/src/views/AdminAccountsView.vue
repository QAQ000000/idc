<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { adminApi, type Page } from '../api/admin';
import { roles, passwordError, type AdminAccount } from '../api/adminAccounts';
import { message } from '../api/request';
import { useAdmin } from '../stores/admin';
const route=useRoute(), router=useRouter(), identity=useAdmin();
const filters=reactive({ q:String(route.query.q||''), role:String(route.query.role||'all'), status:String(route.query.status||'all') });
const data=ref<Page<AdminAccount>>(), busy=ref(false), error=ref('');
const creating=ref(false), saving=ref(false), createError=ref('');
const form=reactive({username:'',email:'',role:'support',status:'active',password:'',confirm:''});
function clearSecrets() { form.password='';form.confirm=''; }
async function load() {
  busy.value=true;error.value='';data.value=undefined;
  try { data.value=await adminApi<Page<AdminAccount>>('/accounts?'+new URLSearchParams({...filters,page:String(route.query.page||1),per_page:'20'})); }
  catch(e){error.value=message(e);} finally {busy.value=false;}
}
async function search(page=1) {
  const target={path:'/admin/accounts',query:{...filters,page:String(page)}};
  if(router.resolve(target).fullPath===route.fullPath) await load(); else await router.push(target);
}
function reset() { Object.assign(filters,{q:'',role:'all',status:'all'});void search(); }
async function create() {
  if(saving.value)return;
  createError.value=passwordError(form.password,form.confirm);if(createError.value)return;
  saving.value=true;
  try {
    const result=await adminApi<{admin_id:number}>('/accounts',{method:'POST',body:{username:form.username,email:form.email,role:form.role,status:form.status,password:form.password}});
    creating.value=false;await router.push('/admin/accounts/'+result.admin_id);
  } catch(e){createError.value=message(e)+'；如结果不确定，请关闭弹窗，按原用户名查询账号后再决定。';}
  finally{clearSecrets();saving.value=false;}
}
onMounted(load);onBeforeUnmount(clearSecrets);
</script>
<template>
  <section>
    <div class="page-heading"><div><p class="eyebrow">管理工作区</p><h1>管理员账号</h1><p class="muted">管理固定角色与登录状态。调整角色或状态后，目标账号须重新登录。</p></div><el-button v-if="identity.admin?.role==='super_admin'" type="primary" @click="creating=true;createError=''">新增管理员</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-card shadow="never">
      <el-form class="task-filters" label-position="top" @submit.prevent="search()">
        <el-form-item label="用户名／邮箱"><el-input v-model="filters.q" aria-label="用户名／邮箱" maxlength="100" /></el-form-item>
        <el-form-item label="角色"><el-select v-model="filters.role" aria-label="角色"><el-option label="全部角色" value="all" /><el-option v-for="(label,key) in roles" :key="key" :label="label" :value="key" /></el-select></el-form-item>
        <el-form-item label="登录状态"><el-select v-model="filters.status" aria-label="登录状态"><el-option label="全部状态" value="all" /><el-option label="启用" value="active" /><el-option label="停用" value="disabled" /></el-select></el-form-item>
        <el-form-item label="操作"><el-button native-type="submit" type="primary" :disabled="busy">查询</el-button><el-button :disabled="busy" @click="reset">重置</el-button></el-form-item>
      </el-form>
      <div v-loading="busy">
        <el-table v-if="!error" :data="data?.items||[]" row-key="id" empty-text="没有符合条件的管理员">
          <el-table-column label="账号" min-width="230"><template #default="{row}"><RouterLink :to="'/admin/accounts/'+row.id">#{{ row.id }} · {{ row.username }}</RouterLink><div>{{ row.email }}</div></template></el-table-column>
          <el-table-column label="角色" min-width="130"><template #default="{row}">{{ roles[row.role as keyof typeof roles] }}</template></el-table-column>
          <el-table-column label="登录状态" min-width="150"><template #default="{row}"><el-tag :type="row.status==='active'?'success':'info'">{{ row.status==='active'?'启用':'停用' }}</el-tag><div v-if="!row.login_supported">当前登录方式不支持</div></template></el-table-column>
          <el-table-column prop="last_login_at" label="最近登录（UTC）" min-width="180" />
        </el-table>
      </div>
      <el-button v-if="error" :loading="busy" @click="load">重新加载列表</el-button>
      <el-pagination v-if="data" class="task-pagination" :current-page="data.page" :page-size="data.per_page" :total="data.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy" @current-change="search" />
    </el-card>
    <el-dialog v-model="creating" title="新增管理员" width="min(540px, 94vw)" :close-on-click-modal="false" :before-close="(done: () => void)=>{if(!saving){clearSecrets();done();}}" @closed="clearSecrets">
      <el-alert v-if="createError" :title="createError" type="error" :closable="false" role="alert" />
      <el-form label-position="top" @submit.prevent="create">
        <el-form-item label="用户名"><el-input v-model="form.username" aria-label="新管理员用户名" required maxlength="50" autocomplete="off" /></el-form-item>
        <el-form-item label="邮箱"><el-input v-model="form.email" aria-label="新管理员邮箱" type="email" required maxlength="100" autocomplete="off" /></el-form-item>
        <el-form-item label="分配角色"><el-select v-model="form.role" aria-label="分配角色"><el-option v-for="(label,key) in roles" :key="key" :label="label" :value="key" /></el-select></el-form-item>
        <el-form-item label="初始状态"><el-select v-model="form.status" aria-label="初始状态"><el-option label="启用" value="active" /><el-option label="停用" value="disabled" /></el-select></el-form-item>
        <el-form-item label="初始密码（12–72 字节）"><el-input v-model="form.password" aria-label="初始密码" type="password" autocomplete="new-password" required maxlength="72" /></el-form-item>
        <el-form-item label="确认初始密码"><el-input v-model="form.confirm" aria-label="确认初始密码" type="password" autocomplete="new-password" required maxlength="72" /></el-form-item>
        <el-button native-type="submit" type="primary" :loading="saving">创建账号</el-button><el-button :disabled="saving" @click="creating=false;clearSecrets()">取消</el-button>
      </el-form>
    </el-dialog>
  </section>
</template>
