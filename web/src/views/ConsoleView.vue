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
let deadline: ReturnType<typeof setTimeout> | undefined;
let monitor: ReturnType<typeof setInterval> | undefined;
function disconnect() {
  generation++;
  clearTimeout(deadline); clearInterval(monitor);
  const previous = rfb; rfb = undefined; previous?.disconnect();
  busy.value = false; state.value = "已断开";
}
async function connect() {
  if (busy.value || state.value === "已连接") return;
  disconnect(); const attempt = generation; busy.value = true; error.value = ""; state.value = "正在连接";
  try {
    const auth = await api<{websocket_url: string; password: string; binding: string; client_disconnect_at: number}>(`/instances/${route.params.id}/console`, { method: "POST", body: {} });
    if (attempt !== generation) return;
    const url = new URL(auth.websocket_url);
    if (!screen.value || !["wss:", "ws:"].includes(url.protocol) || auth.client_disconnect_at * 1000 <= Date.now()) throw new Error("连接授权无效");
    // Only cloud-issued connection credentials go to the cloud. Never forward the JianYun login token.
    const client = new RFB(screen.value, url.href, { wsProtocols: ["binary"], shared: true, credentials: { password: auth.password } });
    rfb = client;
    const current = () => attempt === generation && rfb === client;
    client.scaleViewport = true; client.resizeSession = false;
    const fail = (text: string) => { if (!current()) return; error.value = text; disconnect(); };
    deadline = setTimeout(() => fail("本次连接已到时，请重新连接"), Math.min(900_000, auth.client_disconnect_at * 1000 - Date.now()));
    let checking = false;
    monitor = setInterval(async () => {
      if (!current() || checking) return;
      checking = true;
      try {
        const check = await api<{binding: string}>(`/instances/${route.params.id}/console/check`, {method: "POST", body: {}});
        if (check.binding !== auth.binding) fail("实例信息已变化，请重新连接");
      } catch { fail("登录或实例状态已变化，请重新登录或核验实例"); }
      finally { checking = false; }
    }, 15_000);
    client.addEventListener("connect", () => { if (!current()) return; state.value = "已连接"; busy.value = false; });
    client.addEventListener("disconnect", () => { if (current()) disconnect(); });
    client.addEventListener("credentialsrequired", () => fail("控制台认证失败，请重新连接或联系客服"));
    client.addEventListener("securityfailure", () => fail("控制台连接被拒绝"));
  } catch (e) {
    if (attempt !== generation) return;
    disconnect(); error.value = e instanceof ApiError ? message(e) : "无法建立控制台连接，请稍后重试"; state.value = "连接失败";
  }
}
function logout() { disconnect(); }
window.addEventListener("jianyun:unauthorized", logout);
onBeforeUnmount(() => { disconnect(); window.removeEventListener("jianyun:unauthorized", logout); });
</script>
<template>
  <section>
    <h1>实例控制台</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />
    <el-alert title="画面直连云控制台。本站页面最长连接 15 分钟，断线后请手动重新连接。" type="info" :closable="false" />
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
