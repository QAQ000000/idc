<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { adminApi } from '../api/admin';
import { passwordError, type AdminAccount } from '../api/adminAccounts';
import { message } from '../api/request';
import { useAdmin } from '../stores/admin';
const identity=useAdmin(),account=ref<AdminAccount>(),busy=ref(false),saving=ref(false),error=ref(''),done=ref(false);
const current=ref(''),password=ref(''),confirm=ref('');
function clear(){current.value='';password.value='';confirm.value='';}
async function load(){
  busy.value=true;error.value='';account.value=undefined;
  try{account.value=(await adminApi<{account:AdminAccount}>('/auth/security')).account;}catch(e){error.value=message(e);}finally{busy.value=false;}
}
async function save(){
  if(saving.value||!account.value)return;
  error.value=passwordError(password.value,confirm.value);if(error.value)return;
  saving.value=true;
  try{await adminApi('/auth/password',{method:'POST',body:{current_password:current.value,new_password:password.value,expected_version:account.value.version}});done.value=true;identity.logout();}
  catch(e){error.value=message(e)+'；密码输入已清除。如结果不确定，请尝试重新登录。';account.value=undefined;}
  finally{saving.value=false;clear();}
}
onMounted(load);onBeforeUnmount(clear);
</script>
<template>
  <el-card class="auth-card">
    <h1>管理员账户安全</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="done" title="管理员密码已更新，所有旧登录已失效，请重新登录。" type="success" :closable="false" />
    <el-form v-else-if="account" label-position="top" @submit.prevent="save">
      <p>{{ account.username }} · {{ account.email }}</p><p class="muted">验证当前密码后修改，客户登录不受影响。</p>
      <el-form-item label="当前管理员密码"><el-input v-model="current" aria-label="当前管理员密码" type="password" autocomplete="current-password" required maxlength="72" /></el-form-item>
      <el-form-item label="新管理员密码（12–72 字节）"><el-input v-model="password" aria-label="新管理员密码" type="password" autocomplete="new-password" required maxlength="72" /></el-form-item>
      <el-form-item label="确认新密码"><el-input v-model="confirm" aria-label="确认新密码" type="password" autocomplete="new-password" required maxlength="72" /></el-form-item>
      <el-button native-type="submit" type="primary" :loading="saving">确认修改并退出登录</el-button>
    </el-form>
    <el-button v-else-if="!done" :loading="busy" @click="load">重新读取账号</el-button>
    <p><RouterLink to="/admin/login">返回管理员登录</RouterLink></p>
  </el-card>
</template>
