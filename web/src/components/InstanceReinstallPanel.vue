<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import { api, ApiError, message } from "../api/request";
const props = defineProps<{ instanceId: number; externalBlocked?: boolean; pageUnavailable?: boolean }>();
const emit = defineEmits<{ blocked: [value: boolean]; changed: []; state: [value: { blocked: boolean; submitting: boolean; message: string }] }>();
interface View {
  state: string; available: boolean; blocked: boolean; operation_token: string;
  images: { id: string; name: string }[]; password_mode: string; data_disk: string;
  tasks: { id: number; status: string; image_name: string; result: string; accepted: boolean; updated_at: string }[];
}
const view = ref<View>(), busy = ref(false), submitting = ref(false), error = ref(""), notice = ref(""), image = ref(""), confirmed = ref(false);
const intention = ref<{ requestKey: string; beforeTask: number; taskId?: number; unknown: boolean }>();
const results: Record<string, string> = { pending: "已排队", running: "提交中", accepted: "上游已受理，自动跟踪执行状态", executing: "已观察到上游重装执行，自动跟踪中", awaiting_credentials: "上游已空闲，等待凭据同步", query_unavailable: "上游已受理，当前查询未能确认结果，继续只读跟踪", execution_ended: "上游执行已结束，新凭据已同步；请登录检查系统结果", investigating: "提交结果未知，正在只读调查，请勿重复提交", success: "已确认完成，新凭据可在本站查看", needs_review: "需要管理员核验，请勿重复重装", not_executed: "管理员已确认未执行并结束" };
const hasPending = (current?: View) => !!current?.tasks.some(t => ["pending", "running", "investigating"].includes(t.status));
const attention = computed(() => view.value?.tasks.filter(t => ["pending", "running", "investigating"].includes(t.status) || t.result === "needs_review") || []);
const blocked = computed(() => !view.value || !!error.value || submitting.value || !!intention.value || hasPending(view.value) || view.value.blocked);
watch(() => ({ blocked: blocked.value, submitting: submitting.value,
  message: [submitting.value ? "重装操作确认／提交中，请保留本次操作。" : intention.value ? intention.value.unknown ? "重装请求结果未知，仅继续核对原任务，请勿再次清盘。" : "重装请求已提交，正在读取原任务记录。" : "", error.value ? `重装查询失败：${error.value}；原任务与阻断保留。` : "", ...attention.value.map(t => `重装任务 #${t.id} · ${results[t.result] || t.result}`), view.value?.blocked && !attention.value.length ? "重装被未决操作或资金门禁阻断。" : ""].filter(Boolean).join(" ")
}), value => { emit("blocked", value.blocked); emit("state", value); }, { immediate: true });
let timer: ReturnType<typeof setTimeout> | undefined, disposed = false, signature = "", submittedTaskId: number | undefined, notifiedTaskId: number | undefined, fullRefreshQueued = false, confirming = false;
async function refresh(tasksOnly = false) {
  if (disposed) return;
  if (busy.value) { if (!tasksOnly) fullRefreshQueued = true; return; }
  fullRefreshQueued = false; clearTimeout(timer); busy.value = true;
  try {
    const tracking = tasksOnly && !!view.value;
    const update = await api<View | Pick<View, "blocked" | "tasks">>(`/instances/${props.instanceId}/reinstall${tracking ? "?view=tasks" : ""}`);
    if (disposed) return;
    const current = tracking ? { ...view.value!, ...update, available: false, operation_token: "" } : update as View;
    error.value = ""; view.value = current;
    if (intention.value && current.tasks.some(t => intention.value!.taskId ? t.id === intention.value!.taskId : t.id > intention.value!.beforeTask)) intention.value = undefined;
    const submitted = current.tasks.find(task => task.id === submittedTaskId);
    if (submitted?.accepted && submitted.status !== "failed" && notifiedTaskId !== submitted.id) {
      notifiedTaskId = submitted.id; notice.value = ""; ElMessage.success("重装请求成功，正在获取实时状态");
    }
    if (current.tasks[0] && ["success", "failed"].includes(current.tasks[0].status)) notice.value = "";
    const next = JSON.stringify(current.tasks.map(t => [t.id, t.status, t.result]));
    if (!tracking || current.blocked || signature !== next) confirmed.value = false;
    if (signature && signature !== next) emit("changed");
    signature = next;
    if (!current.images.some(v => v.id === image.value)) image.value = "";
    if (tracking && !hasPending(current) && !intention.value) fullRefreshQueued = true;
  } catch (e) {
    if (!disposed) { error.value = message(e); confirmed.value = false; if (view.value) { view.value.available = false; view.value.operation_token = ""; } }
  } finally {
    busy.value = false; clearTimeout(timer);
    if (!disposed && fullRefreshQueued) timer = setTimeout(() => { void refresh(); }, 0);
    else if (!disposed && (hasPending(view.value) || intention.value)) timer = setTimeout(() => { void refresh(true); }, 15000);
  }
}
async function submit() {
  if (busy.value || blocked.value || props.externalBlocked || props.pageUnavailable || !view.value?.available || !image.value || !confirmed.value) return;
  const selected = view.value.images.find(v => v.id === image.value); if (!selected) return;
  const token = view.value.operation_token, beforeTask = Math.max(0, ...view.value.tasks.map(t => t.id));
  clearTimeout(timer); busy.value = true; submitting.value = true; error.value = ""; notice.value = "";
  try {
    confirming = true;
    await ElMessageBox.confirm(`将重装为 ${selected.name}。系统盘全部数据会被清空，无法撤销。数据盘不请求格式化，但仍需提前备份。新密码由上游生成，执行结束并同步后在本站查看。确认已备份并提交一次？`, "再次确认重装系统", { type: "warning", confirmButtonText: "确认清空系统盘并重装", cancelButtonText: "取消" });
  } catch { confirmed.value = false; submitting.value = false; busy.value = false; await refresh(); return; }
  finally { confirming = false; }
  if (disposed) return;
  intention.value = { requestKey: crypto.randomUUID(), beforeTask, unknown: false };
  try {
    emit("changed");
    const task = await api<{ task_id: number }>(`/instances/${props.instanceId}/reinstall`, { method: "POST", body: { image_id: selected.id, operation_token: token, request_key: intention.value.requestKey, confirmed_system_disk_erase: true } });
    if (!Number.isSafeInteger(task?.task_id) || task.task_id <= 0) throw new Error("未取得可信任务编号");
    if (disposed) return;
    submittedTaskId = task.task_id; intention.value.taskId = task.task_id;
    notice.value = `重装请求 #${task.task_id} 已提交，等待上游响应。`;
  } catch (e) {
    if (disposed) return;
    if (e instanceof ApiError && e.businessResponse && [400, 403, 404, 409, 422].includes(e.status)) intention.value = undefined;
    else intention.value!.unknown = true;
    notice.value = message(e) + "；请先刷新核对原任务，勿再次提交。";
  } finally { confirmed.value = false; submitting.value = false; busy.value = false; if (!disposed) await refresh(); }
}
watch(() => props.externalBlocked, () => { confirmed.value = false; void refresh(); });
onMounted(() => { void refresh(); });
onUnmounted(() => { disposed = true; clearTimeout(timer); if (confirming) ElMessageBox.close(); });
defineExpose({ refresh: () => refresh(false) });
</script>
<template>
  <el-card class="instance-reinstall-panel" shadow="never">
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" />
    <el-alert v-if="view?.blocked" title="存在进行中的操作或资金冻结，暂不能重装。原任务会自动跟踪，异常时再联系管理员。" type="warning" :closable="false" />
    <p v-for="task in view?.tasks.filter((t, index) => index === 0 || ['pending', 'running', 'investigating'].includes(t.status) || t.result === 'needs_review') || []" :key="task.id" class="operation-notice" role="status">重装任务 #{{ task.id }} · {{ results[task.result] }}</p>
    <!-- Native details keeps task polling and blocked events mounted. -->
    <details class="auxiliary-details">
      <summary>重装系统与操作记录</summary>
      <div class="details-content">
        <template v-if="view">
          <p v-if="view.state === 'unsupported'" class="muted">当前实例未开放重装能力或没有可用镜像。</p>
          <el-alert v-if="view.state === 'unavailable'" title="暂时无法读取重装能力与镜像，请稍后重新查询。" type="warning" :closable="false" />
          <el-form v-if="view.images.length" label-position="top" @submit.prevent="submit">
            <el-alert title="系统盘全部清空，操作不可撤销；数据盘固定不请求格式化，仍须备份重要数据。" type="warning" :closable="false" />
            <el-form-item label="系统镜像"><el-select v-model="image" aria-label="重装系统镜像" placeholder="请选择上游提供的镜像" :disabled="busy || blocked || externalBlocked || pageUnavailable || !view.available" style="width: 100%" @change="confirmed = false"><el-option v-for="os in view.images" :key="os.id" :label="os.name" :value="os.id" /></el-select></el-form-item>
            <p class="muted">密码由上游生成；执行结束并同步后通过本站“查看登录凭据”获取。重装不改变原资源、到期日或续费价。</p>
            <el-checkbox v-model="confirmed" class="task-confirm" :disabled="busy || blocked || externalBlocked || pageUnavailable || !view.available" style="height: auto; white-space: normal; margin-bottom: 16px">已备份重要数据，确认允许清空系统盘</el-checkbox>
            <div class="actions"><el-button native-type="submit" type="danger" :disabled="!view.available || !image || !confirmed || busy || blocked || externalBlocked || pageUnavailable">重装系统</el-button></div>
          </el-form>
          <el-table :data="view.tasks" empty-text="暂无重装记录">
            <el-table-column prop="id" label="任务" width="85" /><el-table-column prop="image_name" label="镜像" min-width="170" />
            <el-table-column label="结果" min-width="270"><template #default="{ row }"><div v-if="row.accepted">上游已受理</div>{{ results[row.result] }}</template></el-table-column>
            <el-table-column prop="updated_at" label="更新时间 (UTC)" min-width="180" />
          </el-table>
          <p class="muted">最近 10 次操作。在途任务每 15 秒读取本站记录，上游状态由 Worker 跟踪；上游恢复空闲仅表示执行结束，不保证系统可登录。结果未知、超时等异常才转管理员核验，始终不自动重发。</p>
        </template>
        <el-button :loading="busy" @click="refresh(false)">刷新镜像与重装结果</el-button>
      </div>
    </details>
  </el-card>
</template>
