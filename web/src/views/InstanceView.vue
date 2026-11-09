<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api, message, type Instance, type Invoice } from "../api/request";
import InstanceServiceStatusPanel from "../components/InstanceServiceStatusPanel.vue";
import InstancePowerPanel from "../components/InstancePowerPanel.vue";
import InstanceReinstallPanel from "../components/InstanceReinstallPanel.vue";
const powerBlocked = ref(false), reinstallBlocked = ref(false), powerRefreshVersion = ref(0);
function invalidateCredentials() { credentialVersion++; credentials.value = null; }
const route = useRoute(),
  router = useRouter(),
  instance = ref<Instance | null>(null),
  loading = ref(true),
  busy = ref(false),
  error = ref("");
const credentials = ref<{
  username: string | null;
  password: string | null;
  verification?: string;
} | null>(null);
let credentialVersion = 0;
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
  if (busy.value) return;
  loading.value = true;
  busy.value = true;
  error.value = "";
  credentials.value = null;
  credentialVersion++;
  try {
    instance.value = await api<Instance>("/instances/" + route.params.id);
  } catch (e) {
    instance.value = null;
    error.value = message(e);
  } finally {
    busy.value = false;
    loading.value = false;
  }
}
async function reveal() {
  if (busy.value) return;
  if (credentials.value) {
    credentialVersion++;
    credentials.value = null;
    return;
  }
  busy.value = true;
  error.value = "";
  try {
    const version = ++credentialVersion;
    const value = await api<{
      username: string | null;
      password: string | null;
  verification?: string;
    }>("/instances/" + route.params.id + "/credentials");
    if (version === credentialVersion && !document.hidden)
      credentials.value = value;
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
    const invoice = await api<Invoice>(
      "/instances/" + route.params.id + "/renew",
      {
        method: "POST",
        body: { base_due_date: instance.value.due_date },
      },
    );
    await router.push("/invoices/" + invoice.invoice_id);
  } catch (e) {
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
async function setAutomatic(enabled: boolean) {
  if (busy.value || !instance.value) return;
  busy.value = true;
  error.value = "";
  try {
    await api("/instances/" + route.params.id + "/auto-renew", {
      method: "PATCH",
      body: { enabled },
    });
    instance.value.auto_renew = enabled ? 1 : 0;
  } catch (e) {
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
const statusLabels: Record<string, string> = {
  pending: "待交付",
  active: "已交付",
  suspended: "已暂停",
  terminated: "已销毁",
  cancelled: "已取消",
};
const cycles: Record<string, string> = {
  monthly: "月付",
  annually: "年付",
  onetime: "一次性",
};
function clearCredentials() {
  if (document.hidden) {
    credentialVersion++;
    credentials.value = null;
  }
}
onMounted(() => {
  void load();
  document.addEventListener("visibilitychange", clearCredentials);
});
onUnmounted(() => {
  credentialVersion++;
  credentials.value = null;
  document.removeEventListener("visibilitychange", clearCredentials);
});
</script>
<template>
  <section class="instance-page" aria-labelledby="instance-title">
    <RouterLink class="back-link" to="/instances">← 返回实例列表</RouterLink>
    <div class="page-heading">
      <div>
        <p class="eyebrow">
          实例管理
          <span v-if="instance">/ #{{ instance.id }}</span>
        </p>
        <h1 id="instance-title">{{ instance?.name || "实例详情" }}</h1>
        <p v-if="instance" class="muted">{{ instance.product_name }}</p>
      </div>
      <el-button :loading="loading" :disabled="busy" @click="load">
        刷新详情
      </el-button>
    </div>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-card
      v-if="loading"
      shadow="never"
      aria-label="实例加载中"
      aria-busy="true"
    >
      <el-skeleton :rows="8" animated />
    </el-card>
    <template v-else-if="instance">
      <el-alert
        v-if="
          ['provisioning', 'investigating', 'needs_review'].includes(
            instance.renewal_status,
          )
        "
        title="续费款项已记账，结果仍待确认"
        description="请勿重复续费。欠费暂停仅在确认恢复后解除；需要时联系管理员核验。"
        type="warning"
        :closable="false"
      />
      <el-alert
        v-if="
          instance.status === 'suspended' && instance.suspend_type !== 'billing'
        "
        title="当前实例暂停原因需要核验"
        description="请联系客服核验；付款不能直接解除此暂停。"
        type="warning"
        :closable="false"
      />
      <el-alert
        v-if="
          ['investigating', 'needs_review'].includes(instance.delivery_status)
        "
        title="交付结果正在核验，请勿重复购买"
        description="管理员核实后会回填结果或人工处理款项，请保留原账单。"
        type="warning"
        :closable="false"
      />
      <el-alert
        v-if="instance.delivery_status === 'compensated'"
        title="本单已取消，款项已退至账户余额。"
        type="success"
        :closable="false"
      />
      <div class="instance-summary">
        <div>
          <span class="field-label">本地服务记录</span>
          <el-tag
            :type="
              instance.status === 'active'
                ? 'success'
                : instance.status === 'suspended'
                  ? 'warning'
                  : 'info'
            "
          >
            {{ statusLabels[instance.status] || instance.status }}
          </el-tag>
        </div>
        <div>
          <span class="field-label">交付状态</span>
          <strong>
            {{ labels[instance.delivery_status] || instance.delivery_status }}
          </strong>
        </div>
        <div>
          <span class="field-label">IP 地址</span>
          <strong class="mono">{{ instance.ip_address || (instance.delivery_status === "delivered" ? "上游未提供" : "等待交付") }}</strong>
        </div>
        <div>
          <span class="field-label">到期时间（UTC）</span>
          <strong>{{ instance.due_date || "交付后确定" }}</strong>
        </div>
      </div>
      <div class="instance-quick-actions actions">
        <el-button
          v-if="instance.status === 'active' && instance.remote_instance_id && instance.entitlement_state !== 'expired'"
          type="primary"
          @click="router.push(`/instances/${instance.id}/console`)"
        >
VNC 控制台 ↗
</el-button>
      </div>
      <div class="instance-columns">
        <div class="panel-stack">
          <InstancePowerPanel
            v-if="instance.remote_instance_id && instance.status === 'active'"
            :key="instance.id"
            :instance-id="Number(instance.id)"
            :external-blocked="reinstallBlocked"
            :refresh-version="powerRefreshVersion"
            @blocked="powerBlocked = $event"
          />
          <el-card v-else shadow="never">
            <h2>运行状态与电源操作</h2>
            <p class="muted">
              实例交付完成且服务有效后，可查询实际运行状态和可用操作。
            </p>
          </el-card>
          <InstanceServiceStatusPanel v-if="instance.remote_instance_id && instance.service_status_supported" :instance-id="Number(instance.id)" :entitlement="instance.entitlement_state" :observation="instance.service_observation" @refreshed="load" />
          <el-card shadow="never">
            <template #header><h2>系统登录凭据</h2></template>
            <p class="muted">
              登录凭据按需读取，重装或任务核验期间可能暂不可用。
            </p>
            <div class="actions">
              <el-button
                v-if="instance.status === 'active'"
                :disabled="busy"
                :aria-expanded="Boolean(credentials)"
                @click="reveal"
              >
                {{ credentials ? "隐藏凭据" : "查看登录凭据" }}
              </el-button>
            </div>
            <div v-if="credentials" class="credentials-panel">
              <p class="muted">请妥善保管，切换标签页或刷新详情后隐藏。</p>
              <p v-if="credentials.verification === 'upstream_reported'" class="muted">当前凭据来自上游，本站尚未验证系统登录；如登录失败，请联系支持。</p>
              <dl>
                <dt>账号</dt>
                <dd class="mono">{{ credentials.username || "上游未提供" }}</dd>
                <dt>密码</dt>
                <dd class="mono">{{ credentials.password || "上游未提供" }}</dd>
              </dl>
            </div>
          </el-card>
          <el-card shadow="never">
            <details class="auxiliary-details">
              <summary>规格与购买信息</summary>
              <div class="details-content">
                <el-descriptions :column="1" border>
                  <el-descriptions-item label="实例名称">
                    {{ instance.name }}
                  </el-descriptions-item>
                  <el-descriptions-item label="商品">
                    {{ instance.product_name }}
                  </el-descriptions-item>
                  <el-descriptions-item label="资源编号">
                    <span class="mono">
                      {{ instance.remote_instance_id || "等待交付" }}
                    </span>
                  </el-descriptions-item>
                  <el-descriptions-item v-if="instance.reinstalled_image" label="最近确认的重装系统">{{ instance.reinstalled_image }}</el-descriptions-item>
                  <el-descriptions-item label="起租时间（UTC）">
                    {{ instance.start_date || "交付后起租" }}
                  </el-descriptions-item>
                  <el-descriptions-item
                    v-for="(item, index) in instance.configuration"
                    :key="index"
                    :label="item.label"
                  >
                    {{ item.value }}
                  </el-descriptions-item>
                </el-descriptions>
                <div v-if="instance.invoice_id" class="actions">
                  <RouterLink :to="'/invoices/' + instance.invoice_id">
                    查看购买账单 ↗
                  </RouterLink>
                </div>
              </div>
            </details>
          </el-card>
          <InstanceReinstallPanel
            v-if="instance.remote_instance_id"
            :key="`reinstall-${instance.id}`"
            :instance-id="Number(instance.id)"
            @blocked="reinstallBlocked = $event; if ($event) invalidateCredentials()"
            @changed="invalidateCredentials(); powerRefreshVersion++"
          />
        </div>
        <div class="panel-stack">
          <el-card shadow="never">
            <template #header><h2>账期与续费</h2></template>
            <p class="field-label">
              每期续费价格 ·
              {{ cycles[instance.billing_cycle] || instance.billing_cycle }}
            </p>
            <p class="renewal-price">
              <small>¥</small>
              {{ instance.recurring_price }}
            </p>
            <p class="muted">
              {{
                instance.renewal_status === "renewed"
                  ? "本次续费已获上游确认"
                  : instance.renewal_status === "awaiting_payment"
                    ? "续费账单待付款"
                    : [
                          "investigating",
                          "needs_review",
                          "provisioning",
                        ].includes(instance.renewal_status)
                      ? "续费结果等待确认，请勿重复付款"
                      : "按当前实例约定价格续费"
              }}
            </p>
            <div class="actions">
              <el-button
                v-if="instance.renewal_eligible"
                :disabled="
                  busy ||
                  powerBlocked || reinstallBlocked ||
                  ['provisioning', 'investigating', 'needs_review'].includes(
                    instance.renewal_status,
                  )
                "
                type="primary"
                @click="renew"
              >
                续费一个账期
              </el-button>
              <RouterLink
                v-if="instance.renewal_invoice_id"
                :to="'/invoices/' + instance.renewal_invoice_id"
              >
                查看续费账单
              </RouterLink>
            </div>
            <div class="auto-renew-setting">
              <div class="panel-heading">
                <strong>余额自动续费</strong>
                <el-switch
                  :model-value="Boolean(instance.auto_renew)"
                  :disabled="busy"
                  aria-label="余额自动续费"
                  @change="setAutomatic(Boolean($event))"
                />
              </div>
              <p class="muted">
                到期前 7 天出账；开启后每 15
                分钟检查余额并尝试付款，余额不足保留原未付账单。
              </p>
            </div>
          </el-card>
        </div>
      </div>
    </template>
    <el-empty v-else description="无法显示此实例，请检查访问权限或稍后重试">
      <el-button type="primary" @click="load">重新加载</el-button>
    </el-empty>
  </section>
</template>
