<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api, message, type Instance, type Invoice } from "../api/request";
import InstancePowerPanel from "../components/InstancePowerPanel.vue";
const powerBlocked = ref(false);
const route = useRoute(),
  router = useRouter(),
  instance = ref<Instance | null>(null),
  busy = ref(false),
  error = ref("");
const credentials = ref<{
  username: string | null;
  password: string | null;
} | null>(null);
const labels: Record<string, string> = {
  awaiting_payment: "待付款",
  provisioning: "开通中",
  investigating: "正在核验上游结果",
  needs_review: "需要人工核验",
  delivered: "已交付",
  cancelled: "已取消",
  compensated: "已取消并补偿至余额",
};
async function load() {
  busy.value = true;
  error.value = "";
  credentials.value = null;
  try {
    instance.value = await api<Instance>("/instances/" + route.params.id);
  } catch (e) {
    instance.value = null;
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
async function reveal() {
  if (credentials.value) {
    credentials.value = null;
    return;
  }
  busy.value = true;
  error.value = "";
  try {
    credentials.value = await api(
      "/instances/" + route.params.id + "/credentials",
    );
  } catch (e) {
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
async function renew() {
  if (busy.value || !instance.value?.due_date) return;
  busy.value = true;
  error.value = "";
  try {
    const invoice = await api<Invoice>("/instances/" + route.params.id + "/renew", {
      method: "POST", body: { base_due_date: instance.value.due_date },
    });
    await router.push("/invoices/" + invoice.invoice_id);
  } catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
async function setAutomatic(enabled: boolean) {
  if (busy.value || !instance.value) return;
  busy.value = true;
  error.value = "";
  try {
    await api("/instances/" + route.params.id + "/auto-renew", { method: "PATCH", body: { enabled } });
    instance.value.auto_renew = enabled ? 1 : 0;
  } catch (e) { error.value = message(e); }
  finally { busy.value = false; }
}
onMounted(load);
</script>
<template>
  <section class="form-page">
    <h1>实例详情</h1>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-card v-if="instance" v-loading="busy">
      <el-alert
v-if="['provisioning','investigating','needs_review'].includes(instance.renewal_status)"
        title="续费权益已记账，上游结果仍待确认，请勿重复续费。欠费暂停仅在上游确认恢复后解除。"
        type="warning" :closable="false" />
      <el-alert
v-if="instance.status === 'suspended' && instance.suspend_type !== 'billing'"
        title="当前为合规、安全或人工暂停，请联系客服核验；付款不能解除此类暂停。"
        type="warning" :closable="false" />
      <el-alert
        v-if="
          ['investigating', 'needs_review'].includes(instance.delivery_status)
        "
        title="上游结果正在核验，请勿重复购买。客服核实后会回填交付结果或处理取消与资金补偿。"
        type="warning"
        :closable="false"
      />
      <el-alert
        v-if="instance.delivery_status === 'compensated'"
        title="本单已取消，款项已退至账户余额。"
        type="success"
        :closable="false"
      />
      <el-descriptions :column="1" border>
        <el-descriptions-item label="实例名称">
{{
          instance.name
        }}
</el-descriptions-item>
        <el-descriptions-item label="商品">
{{
          instance.product_name
        }}
</el-descriptions-item>
        <el-descriptions-item label="交付状态">
{{
          labels[instance.delivery_status]
        }}
</el-descriptions-item>
        <el-descriptions-item label="资源编号">
{{
          instance.remote_instance_id || "等待交付"
        }}
</el-descriptions-item>
        <el-descriptions-item label="IP 地址">
{{
          instance.ip_address || "—"
        }}
</el-descriptions-item>
        <el-descriptions-item label="起租时间（UTC）">
{{
          instance.start_date || "交付后起租"
        }}
</el-descriptions-item>
        <el-descriptions-item label="到期时间（UTC）">
{{
          instance.due_date || "交付后确定"
        }}
</el-descriptions-item>
        <el-descriptions-item label="账期">
{{
          instance.billing_cycle === "monthly" ? "月付" : "年付"
        }}
</el-descriptions-item>
        <el-descriptions-item label="每期续费价格">¥{{ instance.recurring_price }}</el-descriptions-item>
        <el-descriptions-item label="续费状态">{{ instance.renewal_status === 'renewed' ? '上游已确认' : instance.renewal_status === 'awaiting_payment' ? '续费账单待付款' : ['investigating','needs_review','provisioning'].includes(instance.renewal_status) ? '等待确认' : '暂无续费' }}</el-descriptions-item>
        <el-descriptions-item label="余额自动续费">
          <el-switch :model-value="Boolean(instance.auto_renew)" :disabled="busy" aria-label="余额自动续费" @change="setAutomatic(Boolean($event))" />
          <p class="muted">到期前 7 天出账，开启后每 15 分钟检查余额并尝试付款；余额不足保留未付账单。</p>
        </el-descriptions-item>
        <el-descriptions-item v-for="(item, index) in instance.configuration" :key="index" :label="item.label">{{ item.value }}</el-descriptions-item>
      </el-descriptions>
      <div class="actions">
        <RouterLink v-if="instance.status === 'active' && instance.remote_instance_id" :to="`/instances/${instance.id}/console`">VNC 控制台</RouterLink>
        <el-button v-if="instance.renewal_eligible" :disabled="busy || powerBlocked || ['provisioning','investigating','needs_review'].includes(instance.renewal_status)" type="primary" @click="renew">续费一个账期</el-button>
        <RouterLink v-if="instance.renewal_invoice_id" :to="'/invoices/' + instance.renewal_invoice_id">查看续费账单</RouterLink>
        <el-button :disabled="busy" @click="load">刷新状态</el-button
        ><RouterLink
          v-if="instance.invoice_id"
          :to="'/invoices/' + instance.invoice_id"
          >查看账单</RouterLink
        ><el-button
          v-if="instance.status === 'active'"
          :disabled="busy"
          @click="reveal"
          >
{{ credentials ? "隐藏凭据" : "查看登录凭据" }}
</el-button
        >
      </div>
      <el-descriptions v-if="credentials" :column="1" border
        >
<el-descriptions-item label="账号">
{{
          credentials.username || "上游未提供"
        }}
</el-descriptions-item
        ><el-descriptions-item label="密码">
{{
          credentials.password || "上游未提供"
        }}
</el-descriptions-item>
</el-descriptions
      >
    </el-card>
    <el-button v-else :loading="busy" @click="load">重新加载</el-button>
    <InstancePowerPanel v-if="instance && instance.remote_instance_id && instance.status === 'active'" :key="instance.id" :instance-id="Number(instance.id)" @blocked="powerBlocked = $event" />
    <p><RouterLink to="/instances">返回实例列表</RouterLink></p>
  </section>
</template>
