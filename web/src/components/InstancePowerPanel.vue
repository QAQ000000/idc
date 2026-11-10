<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { ElMessageBox } from "element-plus";
import { api, ApiError, message } from "../api/request";
const props = defineProps<{ instanceId: number; externalBlocked?: boolean; pageUnavailable?: boolean }>();
const emit = defineEmits<{ blocked: [value: boolean]; state: [value: { blocked: boolean; submitting: boolean; message: string }] }>();
type Action = "start" | "stop" | "reboot";
interface Runtime {
  state: string; checked_at: string; actions: Action[]; blocked: boolean; operation_token: string;
  tasks: { id: number; action: Action; status: string; result: string; created_at: string; updated_at: string }[];
}
type TaskView = Pick<Runtime, "blocked" | "tasks">;
const local = ref<TaskView>(), localError = ref("");
const runtime = ref<Runtime>(), busy = ref(false), submitting = ref(false), error = ref(""), notice = ref("");
const intention = ref<{ requestKey: string; beforeTask: number; taskId?: number; unknown: boolean }>();
const actionLabels: Record<Action, string> = { start: "开机", stop: "关机", reboot: "重启" };
const states: Record<string, string> = { on: "运行中", off: "已关机", transitioning: "状态切换中", unknown: "状态暂不可确认", unsupported: "当前实例未开放电源查询" };
const results: Record<string, string> = { pending: "已排队", running: "提交中", investigating: "结果未知，正在只读核验", accepted: "上游已确认接受命令", needs_review: "需要管理员核验，请勿重复操作", not_executed: "管理员已确认未执行并结束" };
const autoRefreshStopped = ref(false), localPending = ref(false);
const attention = computed(() => local.value?.tasks.filter(t => ["pending", "running", "investigating"].includes(t.status) || t.result === "needs_review") || []);
const blocked = computed(() => !local.value || !!localError.value || submitting.value || !!intention.value || localPending.value || local.value.blocked);
watch(() => ({ blocked: blocked.value, submitting: submitting.value,
  message: [submitting.value ? "电源操作确认／提交中，请保留本次操作。" : intention.value ? intention.value.unknown ? "电源请求结果未知，仅继续核对原任务，请勿再次提交。" : "电源请求已提交，正在读取原任务记录。" : "", localError.value ? `本地电源任务核对失败：${localError.value}；原操作与阻断保留。` : "", ...attention.value.map(t => `电源任务 #${t.id} · ${results[t.result] || t.result}`), local.value?.blocked && !attention.value.length ? "电源操作被未决任务或资金门禁阻断。" : ""].filter(Boolean).join(" ")
}), value => { emit("blocked", value.blocked); emit("state", value); }, { immediate: true });
let timer: ReturnType<typeof setTimeout> | undefined, disposed = false, remotePollingUntil = 0, fullRefreshQueued = false, readingFull = false, confirming = false, capabilityVersion = 0;
function invalidateCapability() {
  capabilityVersion++;
  if (runtime.value) { runtime.value.actions = []; runtime.value.operation_token = ""; }
}
async function refresh(tasksOnly = false, manual = false) {
  if (disposed) return;
  if (manual) { remotePollingUntil = 0; autoRefreshStopped.value = false; }
  if (busy.value) { if (!tasksOnly && !readingFull) fullRefreshQueued = true; return; }
  // A local blocker needs task tracking, not another upstream inspection.
  const tracking = tasksOnly || !local.value || !!localError.value || props.externalBlocked || local.value.blocked || !!intention.value;
  readingFull = !tracking; fullRefreshQueued = false; clearTimeout(timer); busy.value = true;
  const version = capabilityVersion;
  try {
    const current = await api<Runtime | TaskView>(`/instances/${props.instanceId}/runtime${tracking ? "?view=tasks" : ""}`);
    if (disposed) return;
    const wasTracking = localPending.value || !!intention.value;
    const changed = JSON.stringify(local.value?.tasks) !== JSON.stringify(current.tasks);
    localError.value = ""; local.value = { blocked: current.blocked, tasks: current.tasks };
    localPending.value = current.tasks.some(t => ["pending", "running", "investigating"].includes(t.status));
    if (intention.value && current.tasks.some(t => intention.value!.taskId ? t.id === intention.value!.taskId : t.id > intention.value!.beforeTask)) intention.value = undefined;
    if (!tracking) {
      error.value = ""; runtime.value = current as Runtime;
      if (version !== capabilityVersion || props.externalBlocked || current.blocked) {
        invalidateCapability();
        if (!props.externalBlocked && !current.blocked) fullRefreshQueued = true;
      }
      if (runtime.value.state === "transitioning" && !remotePollingUntil) remotePollingUntil = Date.now() + 120000;
      if (["on", "off", "unsupported"].includes(runtime.value.state)) remotePollingUntil = 0;
    } else if (changed || current.blocked) invalidateCapability();
    if (tracking && wasTracking && !localPending.value && !intention.value && !current.blocked && !props.externalBlocked) fullRefreshQueued = true;
  } catch (e) {
    if (!disposed) {
      if (tracking) localError.value = message(e); else error.value = message(e);
      invalidateCapability();
    }
  } finally {
    busy.value = false; readingFull = false;
    const localTracking = props.externalBlocked || localPending.value || !!intention.value || !!localError.value;
    autoRefreshStopped.value = !localTracking && remotePollingUntil > 0 && Date.now() >= remotePollingUntil;
    clearTimeout(timer);
    if (!disposed && fullRefreshQueued && !props.externalBlocked && !local.value?.blocked && !intention.value) timer = setTimeout(() => { void refresh(); }, 0);
    else if (!disposed && (localTracking || remotePollingUntil > Date.now())) {
      const delay = localTracking ? 15000 : Math.min(15000, remotePollingUntil - Date.now());
      timer = setTimeout(() => {
        if (!localTracking && remotePollingUntil > 0 && Date.now() >= remotePollingUntil) { autoRefreshStopped.value = true; return; }
        void refresh(!!localTracking);
      }, delay);
    }
  }
}
async function submit(action: Action) {
  if (busy.value || error.value || blocked.value || props.externalBlocked || props.pageUnavailable || !runtime.value?.actions.includes(action)) return;
  clearTimeout(timer); busy.value = true; submitting.value = true; error.value = ""; notice.value = "";
  const token = runtime.value.operation_token, beforeTask = Math.max(0, ...runtime.value.tasks.map(t => t.id));
  try {
    confirming = true;
    await ElMessageBox.confirm(action === "start" ? "确认启动此实例？" : `确认${actionLabels[action]}此实例？连接和业务可能中断，请先保存工作。`, `确认${actionLabels[action]}`, { confirmButtonText: "确认提交一次", cancelButtonText: "取消", type: "warning" });
  } catch { submitting.value = false; busy.value = false; await refresh(); return; }
  finally { confirming = false; }
  if (disposed) return;
  intention.value = { requestKey: crypto.randomUUID(), beforeTask, unknown: false };
  try {
    const task = await api<{ task_id: number }>(`/instances/${props.instanceId}/power`, { method: "POST", body: { action, operation_token: token, request_key: intention.value.requestKey } });
    if (!Number.isSafeInteger(task?.task_id) || task.task_id <= 0) throw new Error("未取得可信任务编号");
    if (disposed) return;
    intention.value.taskId = task.task_id;
    notice.value = `操作 #${task.task_id} 已提交，可在下方跟踪。`;
  } catch (e) {
    if (disposed) return;
    if (e instanceof ApiError && e.businessResponse && [400, 403, 404, 409, 422].includes(e.status)) intention.value = undefined;
    else intention.value!.unknown = true;
    notice.value = message(e) + "；请先刷新核对任务记录，不要重复提交。";
  } finally { submitting.value = false; busy.value = false; if (!disposed) await refresh(); }
}
watch(() => props.externalBlocked, value => {
  invalidateCapability();
  if (!value && local.value && !local.value.blocked) { if (readingFull) fullRefreshQueued = true; else void refresh(); }
});
onMounted(async () => { await refresh(true); if (!disposed && local.value && !localError.value && !props.externalBlocked && !local.value.blocked) await refresh(); });
onUnmounted(() => { disposed = true; clearTimeout(timer); if (confirming) ElMessageBox.close(); });
defineExpose({ refresh: () => refresh(false, true) });
</script>
<template>
  <el-card class="instance-power-panel" shadow="never">
    <template #header><h2>运行状态与电源操作</h2></template>
    <el-alert v-if="localError" :title="localError" type="error" :closable="false" role="alert" />
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" />
    <el-alert v-if="autoRefreshStopped" title="短时自动查询已结束，当前结果尚未确认，可手动刷新运行状态。" type="info" :closable="false" />
    <p v-if="busy && !submitting" class="muted" role="status">{{ readingFull ? '正在查询运行状态与能力…' : '正在核对本站任务与门禁…' }}</p>
    <p v-else-if="!runtime && blocked" class="muted">正在跟踪本站未决操作；门禁解除后重新查询运行状态。</p>
    <template v-if="runtime">
      <p class="runtime-state"><el-tag>{{ states[runtime.state] }}</el-tag> <span class="muted">{{ runtime.checked_at }} UTC</span></p>
      <el-alert v-if="local?.blocked" title="存在排队、未决操作或资金冻结，请等待原任务处理，必要时联系管理员核验。" type="warning" :closable="false" />
      <el-alert v-else-if="externalBlocked" title="重装或其他未决任务正在核验，电源操作暂不可用。" type="warning" :closable="false" />
      <div class="actions">
        <el-button v-for="action in runtime.actions" :key="action" :disabled="busy || !!error || blocked || externalBlocked || pageUnavailable" :type="action === 'start' ? 'primary' : 'warning'" @click="submit(action)">{{ actionLabels[action] }}</el-button>
      </div>
      <p v-for="task in (local?.tasks || []).filter(t => ['pending', 'running', 'investigating'].includes(t.status) || t.result === 'needs_review')" :key="task.id" class="operation-notice" role="status">电源任务 #{{ task.id }} · {{ results[task.result] }}</p>
      <details class="auxiliary-details">
        <summary>电源操作记录与说明</summary>
        <p class="muted">运行状态与交付、暂停状态分别记录。命令被接受不代表系统已完成启动或重启；结果未知时不会自动重发。</p>
        <el-table :data="local?.tasks || []" empty-text="暂无电源操作记录">
          <el-table-column prop="id" label="任务" width="85" />
          <el-table-column label="操作" width="85"><template #default="{ row }">{{ actionLabels[row.action as Action] }}</template></el-table-column>
          <el-table-column label="结果" min-width="240"><template #default="{ row }">{{ results[row.result] }}</template></el-table-column>
          <el-table-column prop="updated_at" label="更新时间 (UTC)" min-width="180" />
        </el-table>
        <p class="muted">最近 10 次操作；在途任务每 15 秒查询一次。仅上游执行中时也自动查询，最多 2 分钟；离开页面后停止。</p>
      </details>
    </template>
    <el-button :loading="busy" :disabled="busy" @click="refresh(false, true)">查询运行状态和操作结果</el-button>
  </el-card>
</template>
