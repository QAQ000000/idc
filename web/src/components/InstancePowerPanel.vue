<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { ElMessageBox } from "element-plus";
import { api, message } from "../api/request";
const props = defineProps<{ instanceId: number }>();
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
let timer: ReturnType<typeof setTimeout> | undefined, disposed = false;
async function refresh() {
  if (busy.value || disposed) return;
  clearTimeout(timer); busy.value = true; error.value = "";
  try {
    runtime.value = await api<Runtime>(`/instances/${props.instanceId}/runtime`);
    emit("blocked", runtime.value.blocked);
  } catch (e) { error.value = message(e); runtime.value = undefined; }
  finally {
    busy.value = false;
    if (!disposed && runtime.value?.tasks.some(t => ["pending", "running", "investigating"].includes(t.status))) {
      timer = setTimeout(refresh, 15000);
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
onMounted(refresh);
onUnmounted(() => { disposed = true; clearTimeout(timer); });
</script>
<template>
  <el-card>
    <h2>运行状态与电源操作</h2>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" />
    <template v-if="runtime">
      <p><el-tag>{{ states[runtime.state] }}</el-tag> <span class="muted">查询时间 {{ runtime.checked_at }} UTC</span></p>
      <el-alert v-if="runtime.blocked" title="存在排队、未决操作或资金冻结，请等待原任务处理，必要时联系管理员核验。" type="warning" :closable="false" />
      <p class="muted">运行状态与交付、暂停状态分别记录。命令被接受不代表系统已完成启动或重启；结果未知时不会自动重发。</p>
      <div class="actions">
        <el-button v-for="action in runtime.actions" :key="action" :disabled="busy" :type="action === 'start' ? 'primary' : 'warning'" @click="submit(action)">{{ actionLabels[action] }}</el-button>
      </div>
      <el-table :data="runtime.tasks" empty-text="暂无电源操作记录">
        <el-table-column prop="id" label="任务" width="85" />
        <el-table-column label="操作" width="85"><template #default="{ row }">{{ actionLabels[row.action as Action] }}</template></el-table-column>
        <el-table-column label="结果" min-width="240"><template #default="{ row }">{{ results[row.result] }}</template></el-table-column>
        <el-table-column prop="updated_at" label="更新时间 (UTC)" min-width="180" />
      </el-table>
      <p class="muted">最近 10 次操作；在途任务每 15 秒查询一次，离开页面后停止。</p>
    </template>
    <el-button :loading="busy" @click="refresh">查询运行状态和操作结果</el-button>
  </el-card>
</template>
