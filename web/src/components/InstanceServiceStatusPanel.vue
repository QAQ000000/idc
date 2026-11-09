<script setup lang="ts">
import { ref } from "vue";
import { api, message } from "../api/request";
import { adminApi } from "../api/admin";
import { useAdmin } from "../stores/admin";
import { entitlementLabels, upstreamServiceLabels, type ServiceObservation } from "../api/serviceStatus";
const props=defineProps<{instanceId:number;entitlement:string;observation:ServiceObservation;admin?:boolean}>();
const emit=defineEmits<{refreshed:[]}>();
const busy=ref(false), error=ref(""), notice=ref("");
const account=useAdmin();
async function refresh() {
  if(busy.value)return;busy.value=true;error.value="";notice.value="";
  try {
    const path=`/instances/${props.instanceId}/service-status`;
    const r=props.admin?await adminApi<{applied:boolean;outcome:string}>(path,{method:'POST'}):await api<{applied:boolean;outcome:string}>(path,{method:'POST'});
    notice.value=r.applied?'已核对上游服务状态；账期、款项和资源绑定保持原记录。':r.outcome==='UNSUPPORTED'?'当前驱动未开放服务状态核对。':'存在未决操作或本地状态已变化，本次结果未应用；请保留原任务并稍后刷新。';
    emit('refreshed');
  }catch(e){error.value=message(e);}finally{busy.value=false;}
}
</script>
<template>
  <el-card shadow="never" class="service-status-panel">
    <template #header><h2>权益与上游服务</h2></template>
    <p><el-tag :type="entitlement === 'expired' ? 'warning' : 'info'">{{ entitlementLabels[entitlement] || entitlement }}</el-tag></p>
    <p>{{ upstreamServiceLabels[observation.state] || '上游状态尚未确认' }}<span v-if="observation.state === 'suspended'"> · {{ observation.suspension === 'billing' ? '明确欠费暂停' : observation.suspension === 'nonbilling' ? '非欠费暂停' : '暂停原因不明' }}</span></p>
    <p class="muted">最近核对（UTC）：{{ observation.checked_at || '尚未核对' }}<br>上游到期日（UTC）：{{ observation.remote_due_date || '尚未确认' }}</p>
    <el-alert v-if="observation.review_required" title="服务状态需核验" description="请联系管理员核对暂停原因、原资源和原账单。付款不能直接解除未知或非欠费暂停，请勿重复操作。" type="warning" :closable="false" />
    <el-alert v-if="entitlement === 'expired'" title="本地权益已到期" description="到期不代表上游已停机，关机也不代表欠费暂停。续费结果以原账单和上游确认恢复为准。" type="warning" :closable="false" />
    <p class="muted">魔方代理的到期停机、恢复与资源释放由上游执行，本站只读核对；实际电源状态请查看电源面板。上次核对结果不代表此刻可登录。</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" role="status" />
    <el-button v-if="!admin || account.admin?.role === 'super_admin'" :loading="busy" @click="refresh">核对上游服务状态</el-button>
  </el-card>
</template>
<style scoped>
h2 { margin: 0; font-size: 18px; }
p { overflow-wrap: anywhere; }
.el-alert { margin-bottom: 12px; }
</style>
