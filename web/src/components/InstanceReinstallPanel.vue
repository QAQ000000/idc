<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { ElMessageBox } from "element-plus";
import { api, message } from "../api/request";
const props = defineProps<{ instanceId: number }>();
const emit = defineEmits<{ blocked: [value: boolean]; changed: [] }>();
interface View {
  state: string; available: boolean; blocked: boolean; operation_token: string;
  images: { id: string; name: string }[]; password_mode: string; data_disk: string;
  tasks: { id: number; status: string; image_name: string; result: string; accepted: boolean; updated_at: string }[];
}
const view = ref<View>(), busy = ref(false), error = ref(""), notice = ref(""), image = ref(""), confirmed = ref(false);
const results: Record<string, string> = { pending: "已排队", running: "提交中", investigating: "完成结果尚待核验", success: "已确认完成，新凭据可在本站查看", needs_review: "需要管理员核验，请勿重复重装", not_executed: "管理员已确认未执行并结束" };
let timer: ReturnType<typeof setTimeout> | undefined, disposed = false, signature = "";
async function refresh() {
  if (busy.value || disposed) return;
  clearTimeout(timer); busy.value = true; error.value = "";
  try {
    const current = await api<View>(`/instances/${props.instanceId}/reinstall`);
    if (disposed) return;
    view.value = current; emit("blocked", current.blocked);
    const next = JSON.stringify(current.tasks.map(t => [t.id, t.status]));
    if (signature && signature !== next) emit("changed");
    signature = next;
    if (!current.images.some(v => v.id === image.value)) { image.value = ""; confirmed.value = false; }
  } catch (e) { error.value = message(e); view.value = undefined; }
  finally {
    busy.value = false;
    if (!disposed && view.value?.tasks.some(t => ["pending", "running", "investigating"].includes(t.status))) timer = setTimeout(refresh, 15000);
  }
}
async function submit() {
  if (busy.value || !view.value?.available || !image.value || !confirmed.value) return;
  const selected = view.value.images.find(v => v.id === image.value); if (!selected) return;
  const token = view.value.operation_token;
  clearTimeout(timer); busy.value = true; error.value = ""; notice.value = "";
  try {
    await ElMessageBox.confirm(`将重装为 ${selected.name}。系统盘全部数据会被清空，无法撤销。数据盘不请求格式化，但仍需提前备份。新密码由上游生成，核验完成后在本站查看。确认已备份并提交一次？`, "再次确认重装系统", { type: "warning", confirmButtonText: "确认清空系统盘并重装", cancelButtonText: "取消" });
  } catch { busy.value = false; await refresh(); return; }
  try {
    emit("changed");
    const task = await api<{ task_id: number }>(`/instances/${props.instanceId}/reinstall`, { method: "POST", body: { image_id: selected.id, operation_token: token, request_key: crypto.randomUUID(), confirmed_system_disk_erase: true } });
    notice.value = `重装任务 #${task.task_id} 已提交。受理不代表完成，请在下方跟踪结果。`;
  } catch (e) { notice.value = message(e) + "；请先刷新核对原任务，勿再次提交。"; }
  finally { confirmed.value = false; busy.value = false; await refresh(); }
}
onMounted(refresh);
onUnmounted(() => { disposed = true; clearTimeout(timer); });
</script>
<template>
  <el-card shadow="never">
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" />
    <el-alert v-if="view?.blocked" title="存在排队、未决操作或资金冻结，暂不能重装。请等待原任务或联系管理员。" type="warning" :closable="false" />
    <p v-for="task in view?.tasks.filter(t => ['pending', 'running', 'investigating'].includes(t.status) || t.result === 'needs_review') || []" :key="task.id" class="operation-notice" role="status">重装任务 #{{ task.id }} · {{ results[task.result] }}</p>
    <!-- Native details keeps capability polling and blocked events mounted. -->
    <details class="auxiliary-details">
      <summary>重装系统与操作记录</summary>
      <div class="details-content">
        <template v-if="view">
          <p v-if="view.state === 'unsupported'" class="muted">当前实例未开放重装能力或没有可用镜像。</p>
          <el-alert v-if="view.state === 'unavailable'" title="暂时无法读取重装能力与镜像，请稍后重新查询。" type="warning" :closable="false" />
          <el-form v-if="view.images.length" label-position="top" @submit.prevent="submit">
            <el-alert title="系统盘全部清空，操作不可撤销；数据盘固定不请求格式化，仍须备份重要数据。" type="warning" :closable="false" />
            <el-form-item label="系统镜像"><el-select v-model="image" aria-label="重装系统镜像" placeholder="请选择上游提供的镜像" :disabled="busy || !view.available" style="width: 100%" @change="confirmed = false"><el-option v-for="os in view.images" :key="os.id" :label="os.name" :value="os.id" /></el-select></el-form-item>
            <p class="muted">密码由上游生成；完成核验后通过本站“查看登录凭据”获取。重装不改变原资源、到期日或续费价。</p>
            <el-checkbox v-model="confirmed" class="task-confirm" :disabled="busy || !view.available" style="height: auto; white-space: normal; margin-bottom: 16px">已备份重要数据，确认允许清空系统盘</el-checkbox>
            <div class="actions"><el-button native-type="submit" type="danger" :disabled="!view.available || !image || !confirmed || busy">重装系统</el-button></div>
          </el-form>
          <el-table :data="view.tasks" empty-text="暂无重装记录">
            <el-table-column prop="id" label="任务" width="85" /><el-table-column prop="image_name" label="镜像" min-width="170" />
            <el-table-column label="结果" min-width="270"><template #default="{ row }"><div v-if="row.accepted">上游已受理</div>{{ results[row.result] }}</template></el-table-column>
            <el-table-column prop="updated_at" label="更新时间 (UTC)" min-width="180" />
          </el-table>
          <p class="muted">最近 10 次操作。在途任务每 15 秒只读查询；无可靠完成回执时需管理员核验，不自动重发，也不以运行状态代替完成结果。</p>
        </template>
        <el-button :loading="busy" @click="refresh">刷新镜像与重装结果</el-button>
      </div>
    </details>
  </el-card>
</template>
