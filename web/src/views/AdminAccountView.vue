<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { adminApi } from '../api/admin';
import { roles, auditLabels, passwordError, type AccountDetail } from '../api/adminAccounts';
import { message } from '../api/request';
import { useAdmin } from '../stores/admin';
const route=useRoute(),router=useRouter(),identity=useAdmin();
const data=ref<AccountDetail>(),busy=ref(false),saving=ref(false),error=ref(''),note=ref(''),dialogError=ref('');
const mode=ref<'edit'|'reset'|''>('');
const form=reactive({role:'support',status:'active',evidence:'',password:'',confirm:'',confirmed:false});
const self=computed(()=>Number(data.value?.account.id)===Number(identity.admin?.id));
function clearSecrets(){form.password='';form.confirm='';form.confirmed=false;}
async function load(){
  busy.value=true;data.value=undefined;error.value='';
  try{data.value=await adminApi<AccountDetail>('/accounts/'+route.params.id);}catch(e){error.value=message(e);}finally{busy.value=false;}
}
function open(action:'edit'|'reset'){
  if(!data.value)return;
  Object.assign(form,{role:data.value.account.role,status:data.value.account.status,evidence:''});clearSecrets();dialogError.value='';mode.value=action;
}
async function save(){
  if(saving.value||!data.value)return;
  dialogError.value='';
  if(new TextEncoder().encode(form.evidence.trim()).length<10){dialogError.value='请填写至少 10 字节的修改依据，不要填写密码';return;}
  if(!form.confirmed){dialogError.value='请勾选确认本次操作';return;}
  if(mode.value==='reset'){dialogError.value=passwordError(form.password,form.confirm);if(dialogError.value)return;}
  const action=mode.value;saving.value=true;
  try{
    const result=await adminApi<{self_changed?:boolean}>('/accounts/'+data.value.account.id+(action==='reset'?'/password':''),{method:action==='reset'?'POST':'PATCH',body:{expected_version:data.value.account.version,evidence:form.evidence,...(action==='reset'?{new_password:form.password,confirmed:true}:{role:form.role,status:form.status})}});
    mode.value='';note.value='账号已更新，目标账号的旧登录已失效。';
    if(result.self_changed){identity.logout();await router.push('/admin/login');}else await load();
  }catch(e){mode.value='';note.value=message(e)+'；已重新读取账号与审计，请核对后重新确认，不要盲目重发。';await load();}
  finally{saving.value=false;clearSecrets();}
}
onMounted(load);onBeforeUnmount(clearSecrets);
</script>
<template>
  <section>
    <div class="page-heading"><div><p class="eyebrow">管理工作区</p><h1>管理员详情</h1></div><RouterLink to="/admin/accounts">返回账号列表</RouterLink></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="note" :title="note" type="info" :closable="false" role="status" />
    <el-button :loading="busy" :disabled="saving" @click="load">重新读取账号</el-button>
    <template v-if="data">
      <el-card shadow="never" class="account-card">
        <h2>#{{ data.account.id }} · {{ data.account.username }}</h2>
        <el-descriptions :column="1" border>
          <el-descriptions-item label="邮箱">{{ data.account.email }}</el-descriptions-item>
          <el-descriptions-item label="角色">{{ roles[data.account.role] }}</el-descriptions-item>
          <el-descriptions-item label="登录状态">{{ data.account.status==='active'?'启用':'停用' }}</el-descriptions-item>
          <el-descriptions-item label="二次认证">{{ data.account.second_factor_configured?'已配置（本版暂不支持登录）':'未配置' }}</el-descriptions-item>
          <el-descriptions-item label="最近登录（UTC）">{{ data.account.last_login_at||'暂无' }} · {{ data.account.last_login_ip||'—' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间（UTC）">{{ data.account.created_at }}</el-descriptions-item>
        </el-descriptions>
        <p v-if="!data.account.login_supported" class="muted">当前登录方式不可用，此账号不能作为最后一名可登录超管。</p>
        <div class="account-actions"><el-button type="primary" :disabled="saving" @click="open('edit')">调整角色／状态</el-button><el-button v-if="!self" :disabled="saving" @click="open('reset')">重置他人密码</el-button><RouterLink v-else to="/admin/account/security">修改本人密码</RouterLink></div>
      </el-card>
      <el-card shadow="never" class="account-card">
<h2>最近安全变更（最多 20 条）</h2>
        <el-table :data="data.audit" empty-text="暂无安全变更记录">
          <el-table-column label="操作" min-width="170"><template #default="{row}">{{ auditLabels[row.action]||row.action }}<div>#{{ row.id }} · {{ row.username }}</div></template></el-table-column>
          <el-table-column label="依据与变化" min-width="260"><template #default="{row}"><div v-if="row.context.before">{{ roles[row.context.before.role as keyof typeof roles] }} / {{ row.context.before.status==='active'?'启用':'停用' }} → {{ roles[row.context.after.role as keyof typeof roles] }} / {{ row.context.after.status==='active'?'启用':'停用' }}</div><span class="audit-evidence">{{ row.context.evidence||'—' }}</span></template></el-table-column>
          <el-table-column prop="created_at" label="时间（UTC）" min-width="180" />
        </el-table>
      </el-card>
    </template>
    <el-dialog :model-value="!!mode" :title="mode==='reset'?'重置他人密码':'调整角色／状态'" width="min(540px, 94vw)" :close-on-click-modal="false" :before-close="(done: () => void)=>{if(!saving){mode='';clearSecrets();done();}}" @closed="clearSecrets">
      <el-alert title="此操作会使目标账号的所有旧登录失效。系统始终保留至少一名可登录超级管理员。" type="warning" :closable="false" />
      <el-alert v-if="dialogError" :title="dialogError" type="error" :closable="false" role="alert" />
      <el-form label-position="top" @submit.prevent="save">
        <template v-if="mode==='edit'">
          <el-form-item label="新角色"><el-select v-model="form.role" aria-label="新角色"><el-option v-for="(label,key) in roles" :key="key" :label="label" :value="key" /></el-select></el-form-item>
          <el-form-item label="新状态"><el-select v-model="form.status" aria-label="新状态"><el-option label="启用" value="active" /><el-option label="停用" value="disabled" /></el-select></el-form-item>
          <p v-if="self" class="muted">正在修改本人；成功后会退出管理后台。</p>
        </template>
        <template v-else>
          <el-form-item label="新密码（12–72 字节）"><el-input v-model="form.password" aria-label="重置新密码" type="password" autocomplete="new-password" required maxlength="72" /></el-form-item>
          <el-form-item label="确认新密码"><el-input v-model="form.confirm" aria-label="确认重置新密码" type="password" autocomplete="new-password" required maxlength="72" /></el-form-item>
        </template>
        <el-form-item label="修改依据（不含密码）"><el-input v-model="form.evidence" aria-label="修改依据" type="textarea" :rows="3" maxlength="1000" required /></el-form-item>
        <el-checkbox v-model="form.confirmed" class="account-confirm">{{ mode==='reset'?'确认重置他人密码并撤销其旧登录':'确认以上角色与状态调整，并撤销旧登录' }}</el-checkbox>
        <div class="account-actions"><el-button native-type="submit" type="primary" :loading="saving">确认提交</el-button><el-button :disabled="saving" @click="mode='';clearSecrets()">取消</el-button></div>
      </el-form>
    </el-dialog>
  </section>
</template>
<style scoped>
.account-card{margin-top:16px}.account-card h2{overflow-wrap:anywhere}.account-actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:20px}.audit-evidence{white-space:pre-wrap;overflow-wrap:anywhere}.account-confirm{height:auto;white-space:normal;margin:12px 0}.account-confirm :deep(.el-checkbox__label){white-space:normal;overflow-wrap:anywhere}.account-card :deep(.el-descriptions__content){overflow-wrap:anywhere}
</style>
