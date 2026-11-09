<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import { ElMessageBox } from 'element-plus';
import { adminApi } from '../api/admin';
import { ApiError, message } from '../api/request';
import { passwordError } from '../api/adminAccounts';
import type { CustomerAccountDetail } from '../api/customerAccounts';
import { customerLabels, type Customer } from '../api/adminQueries';
import { useAdmin } from '../stores/admin';

const props=defineProps<{customer:Customer}>(),emit=defineEmits<{changed:[]}>(),identity=useAdmin();
const allowed=computed(()=>identity.admin?.role==='super_admin');
const visible=ref(false),data=ref<CustomerAccountDetail>(),loading=ref(false),saving=ref(false),error=ref(''),note=ref('');
const mode=ref<''|'status'|'password'>(''),editVersion=ref(''),needsReview=ref(false);
const pending=ref<{key:string;version:string;action:string}>();
const form=reactive({status:'suspended',evidence:'',password:'',confirm:'',confirmed:false});
const dirty=computed(()=>!!mode.value&&(!!form.evidence||!!form.password||!!form.confirm||form.confirmed));
const locked=computed(()=>saving.value||loading.value||!!pending.value||needsReview.value);
let generation=0,alive=true;
function clearSecrets(){form.password='';form.confirm='';}
function background(){if(document.visibilityState!=='visible')clearSecrets();}
function leaveEvent(e:BeforeUnloadEvent){clearSecrets();if(saving.value||pending.value||dirty.value){e.preventDefault();e.returnValue='';}}
async function read(){
  if(!alive||loading.value||saving.value||!allowed.value)return;
  const current=++generation;loading.value=true;error.value='';
  try{
    const key=pending.value?.key;
    const result=await adminApi<CustomerAccountDetail>(`/customers/${props.customer.id}/account`+(key?'?request_key='+encodeURIComponent(key):''));
    if(current!==generation||!allowed.value)return;
    if(!result?.account?.version||result.account.id!==props.customer.id||!Array.isArray(result.audit))throw new Error('账号查询结果不完整');
    data.value=result;
    if(pending.value){
      const op=result.operation;
      if(op&&op.context.request_key===pending.value.key&&Number(op.admin_id)===identity.admin?.id&&op.action===pending.value.action){
        pending.value=undefined;needsReview.value=false;mode.value='';form.evidence='';form.confirmed=false;
        note.value='已通过原操作审计确认提交成功，旧登录与找回链接已失效。';emit('changed');
      }else if(result.account.version!==pending.value.version){
        pending.value=undefined;needsReview.value=true;note.value='账号已发生其他变更，未查到本次操作审计；请核对记录，再明确采用最新版本。';
      }else{note.value='暂未查到本次操作审计，结果仍未知；仅可继续只读核对，不会自动重发。';}
    }
  }catch(e){if(current===generation)error.value=message(e);}
  finally{if(current===generation)loading.value=false;}
}
async function open(){visible.value=true;if(!mode.value&&!pending.value)await read();}
function edit(action:'status'|'password'){
  if(!data.value||locked.value||!allowed.value)return;
  mode.value=action;editVersion.value=data.value.account.version;
  form.status=data.value.account.status==='active'?'suspended':'active';form.evidence='';form.confirmed=false;clearSecrets();error.value='';note.value='';
}
function adopt(){
  if(!data.value||pending.value||saving.value||loading.value||error.value)return;
  needsReview.value=false;mode.value='';clearSecrets();form.evidence='';form.confirmed=false;note.value='已采用最新账号；请重新选择操作并确认。';
}
async function save(){
  if(locked.value||!data.value||!mode.value||!allowed.value)return;
  error.value='';
  const evidence=form.evidence.trim(),bytes=new TextEncoder().encode(evidence).length;
  if(bytes<10||bytes>1000){error.value='操作依据须为 10–1000 字节，请勿填写密码';return;}
  if(!form.confirmed){error.value='请确认目标客户及本次操作影响';return;}
  if(mode.value==='password'){error.value=passwordError(form.password,form.confirm);if(error.value)return;}
  const key=crypto.randomUUID(),isPassword=mode.value==='password';
  const operation={key,version:editVersion.value,action:isPassword?'customer_password_reset':'customer_status_update'};
  const body={expected_version:editVersion.value,request_key:key,evidence,confirmed:true,...(isPassword?{new_password:form.password}:{status:form.status})};
  saving.value=true;clearSecrets();
  try{
    const result=await adminApi<{customer_id:number;request_key:string;sessions_revoked:boolean}>(`/customers/${props.customer.id}/${isPassword?'password':'status'}`,{method:isPassword?'POST':'PATCH',body});
    if(!alive||!allowed.value)return;
    if(result?.customer_id!==props.customer.id||result.request_key!==key||result.sessions_revoked!==true)throw new Error('账号保存结果不完整');
    mode.value='';form.evidence='';form.confirmed=false;note.value='账号已更新，旧登录与找回链接已失效。';emit('changed');
  }catch(e){
    if(!alive||!allowed.value)return;
    if(e instanceof ApiError&&e.businessResponse&&e.status>=400&&e.status<500){
      error.value=message(e);
      if(e.status===409){needsReview.value=true;note.value='保存冲突；先只读核对账号与审计，再采用最新版本。';}
    }else{pending.value=operation;note.value=message(e)+'；保存结果未知，正在只读核对原操作。';}
  }finally{saving.value=false;clearSecrets();}
  if(!mode.value||pending.value||needsReview.value)await read();
}
function close(){if(saving.value)return;visible.value=false;clearSecrets();}
async function mayLeave(){
  clearSecrets();if(!saving.value&&!pending.value&&!dirty.value)return true;
  try{await ElMessageBox.confirm('有未保存内容或结果未明的账号操作。离开后请从账号审计核对，勿直接重复重置。','确认离开',{type:'warning',confirmButtonText:'离开并清除草稿',cancelButtonText:'继续核对'});return true;}catch{return false;}
}
onBeforeRouteLeave(mayLeave);
onBeforeRouteUpdate((to,from)=>to.params.id===from.params.id?true:mayLeave());
watch(allowed,(value)=>{if(!value){generation++;loading.value=false;visible.value=false;data.value=undefined;clearSecrets();}});
onMounted(()=>{document.addEventListener('visibilitychange',background);window.addEventListener('pagehide',clearSecrets);window.addEventListener('beforeunload',leaveEvent);});
onBeforeUnmount(()=>{alive=false;generation++;clearSecrets();document.removeEventListener('visibilitychange',background);window.removeEventListener('pagehide',clearSecrets);window.removeEventListener('beforeunload',leaveEvent);});
</script>
<template>
  <el-button v-if="allowed" type="primary" @click="open">账号操作</el-button>
  <el-dialog :model-value="visible&&allowed" title="客户账号操作" width="min(580px, 94vw)" :close-on-click-modal="false" :before-close="(done:()=>void)=>{if(!saving){close();done();}}" @closed="clearSecrets">
    <div class="customer-account-dialog">
      <p class="customer-identity"><strong>#{{ customer.id }}</strong> · {{ customer.email }}</p>
      <el-alert title="停用会停止登录、主动操作和新余额代扣，可能影响到期续费；不会停机、取消已付任务或阻断旧款入账。重新启用后，符合条件的原单可再次自动代扣。" type="warning" :closable="false" />
      <el-alert v-if="note" :title="note" type="info" :closable="false" role="status" />
      <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
      <div class="customer-account-actions"><el-button :loading="loading" :disabled="saving" @click="read">只读核对账号与审计</el-button></div>
      <template v-if="data">
        <p>当前账号：<strong>{{ customerLabels[data.account.status] }}</strong></p>
        <div v-if="!mode&&!pending&&!needsReview" class="customer-account-actions">
          <el-button :disabled="locked" @click="edit('status')">{{ data.account.status==='active'?'停用客户':'启用客户' }}</el-button>
          <el-button :disabled="locked" @click="edit('password')">重置客户密码</el-button>
        </div>
        <el-button v-if="needsReview&&!pending" :disabled="saving||loading||!!error" @click="adopt">已核对，采用最新版本</el-button>
        <el-form v-if="mode" label-position="top" @submit.prevent="save" @focusout="(e:FocusEvent)=>{if(!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node|null))clearSecrets();}">
          <p>{{ mode==='password'?'显式重置密码；不自动发送邮件，请通过可信渠道告知客户。':form.status==='suspended'?'即将停用此客户账号。':'即将启用此客户账号。' }} 每次变更均永久撤销旧登录及旧找回链接。</p>
          <fieldset :disabled="locked" class="customer-account-fields">
            <div v-if="mode==='password'">
              <el-form-item label="新密码（12–72 字节）"><el-input v-model="form.password" aria-label="客户新密码" type="password" autocomplete="new-password" maxlength="72" :disabled="locked" /></el-form-item>
              <el-form-item label="确认新密码"><el-input v-model="form.confirm" aria-label="确认客户新密码" type="password" autocomplete="new-password" maxlength="72" :disabled="locked" /></el-form-item>
              <p class="muted">离开密码编辑表单、关闭弹窗或切到后台会清空明文。</p>
            </div>
            <el-form-item label="操作依据（不含密码）"><el-input v-model="form.evidence" aria-label="客户账号操作依据" type="textarea" :rows="2" maxlength="1000" :disabled="locked" /></el-form-item>
            <el-checkbox v-model="form.confirmed" :disabled="locked">确认目标客户及本次操作、续费与会话影响</el-checkbox>
          </fieldset>
          <div class="customer-account-actions"><el-button native-type="submit" type="primary" :loading="saving" :disabled="locked">确认提交账号操作</el-button><el-button :disabled="saving||!!pending" @click="mode='';clearSecrets()">取消编辑</el-button></div>
        </el-form>
        <el-collapse class="customer-audit">
          <el-collapse-item title="近期账号操作记录" name="audit">
          <el-empty v-if="!data.audit.length" description="暂无账号操作记录" :image-size="50" />
          <article v-for="row in data.audit" :key="row.id"><strong>#{{ row.id }} · {{ row.action==='customer_password_reset'?'重置密码':'变更启停' }}</strong><p>{{ row.username }} · {{ row.created_at }} UTC</p><p v-if="row.context.after_status">{{ customerLabels[row.context.before_status||''] }} → {{ customerLabels[row.context.after_status] }}</p><p>{{ row.context.evidence }}</p><small>操作编号 {{ row.context.request_key }}</small></article>
        </el-collapse-item>
        </el-collapse>
      </template>
      <div class="customer-account-actions"><el-button :disabled="saving" @click="close">关闭</el-button></div>
    </div>
  </el-dialog>
</template>
<style scoped>
.customer-account-dialog{display:grid;gap:12px;overflow-wrap:anywhere}.customer-identity{margin:0}.customer-account-actions{display:flex;flex-wrap:wrap;gap:10px}.customer-account-actions :deep(.el-button+.el-button){margin-left:0}.customer-account-fields{border:0;padding:0;margin:0;min-width:0}.customer-account-dialog :deep(.el-checkbox){height:auto;white-space:normal;margin:12px 0}.customer-account-dialog :deep(.el-checkbox__label){white-space:normal;overflow-wrap:anywhere}.customer-audit article{padding:12px 0;border-bottom:1px solid var(--el-border-color)}.customer-audit p{white-space:pre-wrap;margin:6px 0}
</style>
