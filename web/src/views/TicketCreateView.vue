<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import type { Page } from "../api/admin";
import { ticketApi, ticketDraftKey, readTicketDraft, type TicketResult } from "../api/tickets";
import { ApiError, message } from "../api/request";
const route=useRoute(),router=useRouter();
const form=reactive({title:"",content:"",instance_id:null as number|null,request_key:crypto.randomUUID()});
const ready=ref(false),busy=ref(false),pending=ref(false),error=ref(""),storageKey=ref("");
const resources=ref<Page<{id:number;name:string;status:string}>>(),resourceBusy=ref(false),resourceError=ref(""),searchText=ref("");
const options=ref<{id:number;name:string}[]>([]),selected=ref<{id:number;name:string}>();
const choices=computed(()=>selected.value&&!options.value.some(v=>v.id===selected.value?.id)?[selected.value,...options.value]:options.value);
let generation=0;
async function search(value="",page=1){
 const attempt=++generation;resourceBusy.value=true;resourceError.value="";searchText.value=value;
 try{const data=await ticketApi<Page<{id:number;name:string;status:string}>>(false,"/instances?"+new URLSearchParams({search:value,page:String(page),per_page:"20"}));if(attempt!==generation)return;resources.value=data;options.value=page===1?data.items:[...options.value,...data.items];}
 catch(e){if(attempt===generation)resourceError.value=message(e);}finally{if(attempt===generation)resourceBusy.value=false;}
}
function persist(){if(ready.value&&storageKey.value)sessionStorage.setItem(storageKey.value,JSON.stringify({form:{...form},pending:pending.value}));}
watch(form,persist,{deep:true});
async function submit(){
 if(busy.value||!ready.value)return;
 if(!form.title.trim()||!form.content.trim()){error.value="请填写主题与问题描述";return;}
 busy.value=true;error.value="";pending.value=true;persist();
 try{const result=await ticketApi<TicketResult>(false,"",{method:"POST",body:{...form}});sessionStorage.removeItem(storageKey.value);ready.value=false;await router.push(`/tickets/${result.ticket_id}?page=last`);}
 catch(e){if(e instanceof ApiError&&e.status>=400&&e.status<500&&e.status!==409){pending.value=false;form.request_key=crypto.randomUUID();}error.value=message(e);persist();}
 finally{busy.value=false;}
}
onMounted(async()=>{
 try{storageKey.value=await ticketDraftKey(false,"create");const draft=readTicketDraft<{form:typeof form;pending:boolean}>(storageKey.value);if(draft?.form){Object.assign(form,draft.form);pending.value=!!draft.pending;}else if(typeof route.query.instance_id==='string'&&/^[1-9][0-9]{0,9}$/.test(route.query.instance_id)){form.instance_id=Number(route.query.instance_id);}
 await search(form.instance_id?String(form.instance_id):"");if(form.instance_id){selected.value=options.value.find(v=>v.id===form.instance_id);if(!selected.value)resourceError.value="关联实例未找到，请确认归属后重新选择";}ready.value=true;
 }catch(e){error.value=message(e);}
});
</script>
<template>
 <section class="ticket-page">
<RouterLink class="back-link" to="/tickets">← 返回我的工单</RouterLink><div class="page-heading"><div><p class="eyebrow">客户支持</p><h1>提交工单</h1><p class="muted">请描述问题和出现时间；不要填写登录密码、支付密钥等敏感凭据。</p></div></div>
 <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
 <el-alert v-if="pending" title="提交结果尚待核对：保留原内容与提交键，可安全重试原请求，或先查看工单列表。" type="warning" :closable="false" />
 <el-card shadow="never">
<el-skeleton v-if="!ready" :rows="5" animated /><el-form v-else label-position="top" @submit.prevent="submit">
 <el-form-item label="主题"><el-input v-model="form.title" aria-label="工单主题" maxlength="150" :disabled="busy||pending" /></el-form-item>
 <el-form-item label="关联本人实例（可选）">
<el-select v-model="form.instance_id" aria-label="关联本人实例" filterable remote clearable :remote-method="search" :loading="resourceBusy" :disabled="busy||pending" placeholder="按实例 ID 或名称搜索" @change="selected=choices.find(v=>v.id===form.instance_id)"><el-option v-for="item in choices" :key="item.id" :label="`#${item.id} · ${item.name}`" :value="item.id" /></el-select>
 <el-button v-if="resources && options.length<resources.total" :disabled="resourceBusy||busy||pending" @click="search(searchText,(resources?.page||1)+1)">加载更多实例</el-button>
</el-form-item>
 <el-alert v-if="resourceError" :title="resourceError" type="warning" :closable="false"><el-button @click="search(searchText)">重新查询实例</el-button></el-alert>
 <el-form-item label="问题描述（纯文本）"><el-input v-model="form.content" type="textarea" aria-label="问题描述" :rows="7" maxlength="10000" show-word-limit :disabled="busy||pending" /></el-form-item>
 <div class="actions"><el-button native-type="submit" type="primary" :loading="busy">{{ pending?'重试原提交':'确认提交工单' }}</el-button><RouterLink to="/tickets">查看我的工单</RouterLink></div>
 </el-form>
</el-card>
</section>
</template>
