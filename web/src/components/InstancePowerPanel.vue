<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from "vue";
import { ElMessageBox } from "element-plus";
import { api, message } from "../api/request";
const props = defineProps<{ instanceId: number; externalBlocked?: boolean; refreshVersion?: number }>();
const emit = defineEmits<{ blocked: [value: boolean] }>();
type Action = "start" | "stop" | "reboot";
interface Runtime {
  state: string; checked_at: string; actions: Action[]; blocked: boolean; operation_token: string;
  tasks: { id: number; action: Action; status: string; result: string; created_at: string; updated_at: string }[];
}
const runtime = ref<Runtime>(), busy = ref(false), error = ref(""), notice = ref("");
const actionLabels: Record<Action, string> = { start: "开机", stop: "关机", reboot: "重启" };
const states: Record<string, string> = { on: "运行中", off: "已关机", transitioning: "状态切换中", unknown: "状态暂不可确认", unsupported: "当前实例未开放电源查询" };
const results: Record<string, string> = { pending: "已排队", running: "提交中", investigating: "结果未知，正在只读核验", accepted: "上游已确认接受命令", needs_review: "需要管理员核验，请勿重复操作", not_executed: "管理员已确认未执行并结束" };
const autoRefreshStopped = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined, disposed = false, remotePollingUntil = 0, localPending = false;
async function refresh(manual = false) {
  if (busy.value || disposed) return;
  if (manual) { remotePollingUntil = 0; autoRefreshStopped.value = false; }
  clearTimeout(timer); busy.value = true; error.value = "";
  try {
    const current = await api<Runtime>(`/instances/${props.instanceId}/runtime`);
    if (disposed) return;
    runtime.value = current;
    localPending = current.tasks.some(t => ["pending", "running", "investigating"].includes(t.status));
    if (current.state === "transitioning" && !remotePollingUntil) remotePollingUntil = Date.now() + 120000;
    if (["on", "off", "unsupported"].includes(current.state)) remotePollingUntil = 0;
    emit("blocked", runtime.value.blocked);
  } catch (e) { error.value = message(e); runtime.value = undefined; }
  finally {
    busy.value = false;
    const localTracking = props.externalBlocked || localPending;
    autoRefreshStopped.value = !localTracking && remotePollingUntil > 0 && Date.now() >= remotePollingUntil;
    if (!disposed && (localTracking || remotePollingUntil > Date.now())) {
      const delay = localTracking ? 15000 : Math.min(15000, remotePollingUntil - Date.now());
      timer = setTimeout(() => {
        if (!props.externalBlocked && !localPending && remotePollingUntil > 0 && Date.now() >= remotePollingUntil) {
          autoRefreshStopped.value = true;
          return;
        }
        void refresh();
      }, delay);
    }
  }
}
async function submit(action: Action) {
  if (busy.value || !runtime.value?.actions.includes(action)) return;
  clearTimeout(timer); busy.value = true; error.value = ""; notice.value = "";
  const token = runtime.value.operation_token;
  try {
    await ElMessageBox.confirm(
      action === "start" ? "确认启动此实例？" : `确认${actionLabels[action]}此实例？连接和业务可能中断，请先保存工作。`,
      `确认${actionLabels[action]}`, { confirmButtonText: "确认提交一次", cancelButtonText: "取消", type: "warning" },
    );
  } catch { busy.value = false; await refresh(); return; }
  try {
    const task = await api<{ task_id: number }>(`/instances/${props.instanceId}/power`, {
      method: "POST", body: { action, operation_token: token, request_key: crypto.randomUUID() },
    });
    notice.value = `操作 #${task.task_id} 已提交，可在下方跟踪。`;
  } catch (e) { notice.value = message(e) + "；请先刷新核对任务记录，不要重复提交。"; }
  finally { busy.value = false; await refresh(); }
}
watch(() => [props.externalBlocked, props.refreshVersion], () => { void refresh(); });
onMounted(() => { void refresh(); });
onUnmounted(() => { disposed = true; clearTimeout(timer); });
</script>
<template>
  <el-card class="instance-power-panel" shadow="never">
    <template #header><h2>运行状态与电源操作</h2></template>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" />
    <el-alert v-if="autoRefreshStopped" title="短时自动查询已结束，当前结果尚未确认，可手动刷新运行状态。" type="info" :closable="false" />
    <template v-if="runtime">
      <p class="runtime-state"><el-tag>{{ states[runtime.state] }}</el-tag> <span class="muted">{{ runtime.checked_at }} UTC</span></p>
      <el-alert v-if="runtime.blocked" title="存在排队、未决操作或资金冻结，请等待原任务处理，必要时联系管理员核验。" type="warning" :closable="false" />
      <el-alert v-else-if="externalBlocked" title="重装或其他未决任务正在核验，电源操作暂不可用。" type="warning" :closable="false" />
      <div class="actions">
        <el-button v-for="action in runtime.actions" :key="action" :disabled="busy || externalBlocked" :type="action === 'start' ? 'primary' : 'warning'" @click="submit(action)">{{ actionLabels[action] }}</el-button>
      </div>
      <p v-for="task in runtime.tasks.filter(t => ['pending', 'running', 'investigating'].includes(t.status) || t.result === 'needs_review')" :key="task.id" class="operation-notice" role="status">电源任务 #{{ task.id }} · {{ results[task.result] }}</p>
      <details class="auxiliary-details">
        <summary>电源操作记录与说明</summary>
        <p class="muted">运行状态与交付、暂停状态分别记录。命令被接受不代表系统已完成启动或重启；结果未知时不会自动重发。</p>
        <el-table :data="runtime.tasks" empty-text="暂无电源操作记录">
          <el-table-column prop="id" label="任务" width="85" />
          <el-table-column label="操作" width="85"><template #default="{ row }">{{ actionLabels[row.action as Action] }}</template></el-table-column>
          <el-table-column label="结果" min-width="240"><template #default="{ row }">{{ results[row.result] }}</template></el-table-column>
          <el-table-column prop="updated_at" label="更新时间 (UTC)" min-width="180" />
        </el-table>
        <p class="muted">最近 10 次操作；在途任务每 15 秒查询一次。仅上游执行中时也自动查询，最多 2 分钟；离开页面后停止。</p>
      </details>
    </template>
    <el-button :loading="busy" @click="refresh(true)">查询运行状态和操作结果</el-button>
  </el-card>
</template>
