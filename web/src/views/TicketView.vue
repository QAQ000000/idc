<script setup lang="ts">
import { onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ElMessageBox } from "element-plus";
import { ticketApi, ticketDraftKey, readTicketDraft, ticketLabels, type TicketDetail, type TicketResult } from "../api/tickets";
import { ApiError, message } from "../api/request";
const props=defineProps<{admin?:boolean}>(),route=useRoute(),router=useRouter(),base=props.admin?"/admin/tickets":"/tickets",id=String(route.params.id);
const data=ref<TicketDetail>(),loading=ref(false),busy=ref(false),error=ref(""),ready=ref(false),storageKey=ref("");
const draft=reactive({content:"",request_key:crypto.randomUUID(),pending:false,close:null as {request_key:string;expected_version:string}|null});
function persist(){if(ready.value&&storageKey.value)sessionStorage.setItem(storageKey.value,JSON.stringify(draft));}
watch(draft,persist,{deep:true});
async function load(){
 if(loading.value)return;loading.value=true;error.value="";data.value=undefined;
 try{data.value=await ticketApi<TicketDetail>(!!props.admin,`/${id}?`+new URLSearchParams({page:String(route.query.page||"1"),per_page:"20"}));}
 catch(e){error.value=message(e);}finally{loading.value=false;}
}
async function page(next:number){await router.push({path:`${base}/${id}`,query:{page:String(next)}});}
async function latest(){if(route.query.page==='last')await load();else await router.replace({path:`${base}/${id}`,query:{page:'last'}});}
async function submit(close=false){
 if(busy.value||!ready.value||!data.value)return;
 if(!close&&!draft.content.trim()){error.value="请填写回复内容";return;}
 busy.value=true;error.value="";
 if(close&&!draft.close)draft.close={request_key:crypto.randomUUID(),expected_version:data.value.version};
 if(!close)draft.pending=true;persist();
 try{
  await ticketApi<TicketResult>(!!props.admin,`/${id}/${close?'close':'replies'}`,{method:"POST",body:close?draft.close:{content:draft.content,request_key:draft.request_key}});
  if(close)draft.close=null;else Object.assign(draft,{content:"",request_key:crypto.randomUUID(),pending:false});persist();await latest();
 }catch(e){
  const definitive=e instanceof ApiError&&e.status>=400&&e.status<500;
  if(definitive){if(close)draft.close=null;else if(e.status!==409){draft.pending=false;draft.request_key=crypto.randomUUID();}}
  if(definitive&&e instanceof ApiError&&e.status===409){await load();if(!close&&data.value?.ticket.status==='closed'){draft.pending=false;draft.request_key=crypto.randomUUID();}}
  error.value=message(e)+(definitive?"；请核对最新会话。":"；结果未知，请核对会话，或使用原请求安全重试。");persist();
 }finally{busy.value=false;}
}
async function close(){
 if(busy.value||draft.pending)return;
 if(!draft.close){try{await ElMessageBox.confirm('确认关闭这张工单？关闭后不能追加回复；如有新问题可另提工单。','关闭工单',{confirmButtonText:'确认关闭',cancelButtonText:'继续沟通',type:'warning'});}catch{return;}}
 await submit(true);
}
onMounted(async()=>{
 try{storageKey.value=await ticketDraftKey(!!props.admin,`thread:${id}`);const saved=readTicketDraft<typeof draft>(storageKey.value);if(saved)Object.assign(draft,saved);ready.value=true;await load();}
 catch(e){error.value=message(e);}
});
</script>
<template>
 <section class="ticket-page">
<RouterLink class="back-link" :to="base">← 返回工单列表</RouterLink>
 <div class="page-heading"><div><p class="eyebrow">{{ admin?'客服工作区':'客户支持' }}</p><h1>工单 #{{ id }}</h1><p class="muted">纯文本沟通；请勿填写密码或密钥。退款、改价、重装不由工单自动执行。</p></div><el-button :loading="loading" :disabled="busy" @click="load">刷新会话</el-button></div>
 <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
 <el-alert v-if="draft.pending||draft.close" title="存在待核对的提交；保留原请求键，可安全重试。不要修改内容另发一遍。" type="warning" :closable="false" />
 <el-skeleton v-if="loading" :rows="6" animated />
 <template v-if="data">
 <el-card shadow="never">
<h2 class="ticket-title">{{ data.ticket.title }}</h2><el-descriptions :column="1" border>
 <el-descriptions-item label="状态"><el-tag>{{ ticketLabels[data.ticket.status]||data.ticket.status }}</el-tag></el-descriptions-item>
 <el-descriptions-item v-if="admin&&data.ticket.customer" label="客户"><RouterLink :to="`/admin/customers/${data.ticket.customer.id}`">#{{ data.ticket.customer.id }} · {{ data.ticket.customer.email }}</RouterLink></el-descriptions-item>
 <el-descriptions-item label="关联实例"><RouterLink v-if="data.instance" :to="`${admin?'/admin':''}/instances/${data.instance.id}`">#{{ data.instance.id }} · {{ data.instance.name }}</RouterLink><span v-else>未关联或实例归属不可确认</span></el-descriptions-item>
 <el-descriptions-item label="创建时间（UTC）">{{ data.ticket.created_at }}</el-descriptions-item><el-descriptions-item v-if="data.ticket.closed_at" label="关闭时间（UTC）">{{ data.ticket.closed_at }}</el-descriptions-item>
 </el-descriptions>
</el-card>
 <el-card shadow="never">
<h2>公开会话</h2><el-empty v-if="!data.replies.items.length" description="暂无公开回复" />
 <article v-for="reply in data.replies.items" :key="reply.id" class="ticket-message" :class="{ 'ticket-message-admin':reply.author_type==='admin' }">
 <div class="ticket-message-meta"><strong>{{ reply.author_type==='admin'?'客服':'客户' }}{{ admin&&reply.admin_id?` #${reply.admin_id}`:'' }}</strong><span>#{{ reply.id }} · {{ reply.created_at }} UTC</span></div><p class="ticket-text">{{ reply.content }}</p>
 </article>
 <el-pagination class="task-pagination" :current-page="data.replies.page" :page-size="data.replies.per_page" :total="data.replies.total" :pager-count="5" layout="prev, pager, next, total" :disabled="busy" @current-change="page" />
 <el-button :disabled="busy" @click="latest">查看最新回复</el-button>
 </el-card>
 <el-card v-if="ready" shadow="never">
 <p v-if="data.ticket.status==='closed'" class="muted">工单已关闭。如需继续帮助，请提交新工单。</p>
 <el-input v-if="data.ticket.status==='closed' && draft.content && !draft.pending" :model-value="draft.content" type="textarea" aria-label="未发送的回复草稿" readonly :rows="3" />
 <el-form v-if="data.ticket.status!=='closed'||draft.pending" label-position="top" @submit.prevent="submit()">
 <el-form-item :label="admin?'公开回复（客户可见）':'追加回复'"><el-input v-model="draft.content" type="textarea" aria-label="回复内容" :rows="5" maxlength="10000" show-word-limit :disabled="busy||draft.pending||!!draft.close" /></el-form-item>
 <div class="actions"><el-button native-type="submit" type="primary" :loading="busy" :disabled="!!draft.close">{{ draft.pending?'重试原回复':'发送回复' }}</el-button><el-button v-if="data.ticket.status!=='closed'" :disabled="busy||draft.pending" @click="close">{{ draft.close?'重试原结单':'关闭工单' }}</el-button></div>
 </el-form>
 <el-button v-else-if="draft.close" :loading="busy" @click="close">重试原结单</el-button>
 <RouterLink v-if="!admin&&data.ticket.status==='closed'" to="/tickets/new">提交新工单</RouterLink>
 </el-card>
 </template><el-button v-if="error&&!data" :disabled="busy" @click="load">重新加载会话</el-button>
 </section>
</template>
