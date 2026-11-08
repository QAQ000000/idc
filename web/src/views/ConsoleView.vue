<script setup lang="ts">
import { onBeforeUnmount, ref } from "vue";
import { useRoute } from "vue-router";
import RFB from "@novnc/novnc";
import { api, message, ApiError } from "../api/request";
const route = useRoute();
const screen = ref<HTMLDivElement>();
const state = ref("未连接"), error = ref(""), busy = ref(false);
let rfb: RFB | undefined;
let generation = 0;
function disconnect() { generation++; const previous = rfb; rfb = undefined; previous?.disconnect(); busy.value = false; state.value = "已断开"; }
async function connect() {
  if (busy.value || state.value === "已连接") return;
  disconnect(); const attempt = generation; busy.value = true; error.value = ""; state.value = "正在连接";
  try {
    const auth = await api<{path: string}>(`/instances/${route.params.id}/console`, { method: "POST", body: {} });
    if (attempt !== generation) return;
    const token = sessionStorage.getItem("jianyun_token");
    if (!token || !screen.value || !/^\/console\/ws\/[a-f0-9]{64}$/.test(auth.path)) throw new Error("连接授权无效，请重新登录");
    const client = new RFB(screen.value, `${location.protocol === "https:" ? "wss:" : "ws:"}//${location.host}${auth.path}`, { wsProtocols: ["binary", "jianyun." + token], shared: true });
    rfb = client;
    const current = () => attempt === generation && rfb === client;
    client.scaleViewport = true;
    client.resizeSession = false;
    client.addEventListener("connect", () => { if (!current()) return; state.value = "已连接"; busy.value = false; });
    client.addEventListener("disconnect", () => { if (!current()) return; state.value = "已断开"; busy.value = false; });
    client.addEventListener("credentialsrequired", () => { if (!current()) return; error.value = "控制台认证失败，请重新连接或联系客服"; disconnect(); busy.value = false; });
    client.addEventListener("securityfailure", () => { if (!current()) return; error.value = "控制台连接被拒绝"; disconnect(); busy.value = false; });
  } catch (e) { if (attempt !== generation) return; error.value = e instanceof ApiError ? message(e) : "无法建立控制台连接，请稍后重试"; state.value = "连接失败"; busy.value = false; }
}
function logout() { disconnect(); }
window.addEventListener("jianyun:unauthorized", logout);
onBeforeUnmount(() => { disconnect(); window.removeEventListener("jianyun:unauthorized", logout); });
</script>
<template>
  <section>
    <h1>实例控制台</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert title="控制台最长连接 15 分钟。断线后请手动重新连接；关闭页面会结束连接。" type="info" :closable="false" />
    <div class="actions">
      <el-tag>{{ state }}</el-tag>
      <el-button :loading="busy" :disabled="state === '已连接'" type="primary" @click="connect">连接控制台</el-button>
      <el-button :disabled="!rfb" @click="disconnect">断开连接</el-button>
      <el-button :disabled="state !== '已连接'" @click="rfb?.sendCtrlAltDel()">Ctrl + Alt + Del</el-button>
      <RouterLink :to="`/instances/${route.params.id}`">返回实例详情</RouterLink>
    </div>
    <div ref="screen" class="console-screen" aria-label="实例远程控制台" />
  </section>
</template>
<style scoped>
.console-screen { height: min(72vh, 900px); min-height: 320px; background: #171717; margin-top: 16px; overflow: hidden; }
</style>
