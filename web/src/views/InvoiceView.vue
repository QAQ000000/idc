<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { api, message, type Invoice } from "../api/request";
import { useUser } from "../stores/user";
const route = useRoute(),
  account = useUser();
const invoice = ref<Invoice | null>(null),
  error = ref(""),
  busy = ref(false);
const labels: Record<string, string> = {
  unpaid: "待付款",
  paid: "已付款",
  cancelled: "已取消",
  refunded: "已退款",
  refund_pending: "退款处理中",
};
const types: Record<string, string> = {new: "新购", renew: "实例续费", upgrade: "变配补差", recharge: "充值"};
const actionable = computed(() => invoice.value?.status === "unpaid" && ["new", "renew"].includes(invoice.value.type));
async function load() {
  busy.value = true;
  error.value = "";
  try {
    invoice.value = await api<Invoice>("/invoices/" + route.params.id);
  } catch (e) {
    invoice.value = null;
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
async function action(name: "pay" | "cancel") {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    invoice.value = await api<Invoice>(
      "/invoices/" + route.params.id + "/" + name,
      { method: "POST", body: name === "pay" ? { gateway: "balance" } : {} },
    );
    await account.load();
  } catch (e) {
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
async function epay() {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    const result = await api<{
      checkout: {
        method: string;
        action: string;
        fields: Record<string, string>;
      };
    }>("/invoices/" + route.params.id + "/pay", {
      method: "POST",
      body: { gateway: "epay" },
    });
    const form = document.createElement("form");
    form.method = "POST";
    form.action = result.checkout.action;
    for (const [name, value] of Object.entries(result.checkout.fields)) {
      const input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      input.value = value;
      form.append(input);
    }
    document.body.append(form);
    form.submit();
    form.remove();
  } catch (e) {
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <section class="form-page">
    <h1>账单详情</h1>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    /><el-card v-if="invoice" v-loading="busy">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="账单类型">{{ types[invoice.type] || invoice.type }}</el-descriptions-item>
        <el-descriptions-item label="账单编号">
          {{ invoice.invoice_id }}
</el-descriptions-item
        ><el-descriptions-item label="状态">
          {{ labels[invoice.status] || invoice.status }}
</el-descriptions-item
        ><el-descriptions-item label="应付金额">
          ¥{{ invoice.total }}
</el-descriptions-item>
        <el-descriptions-item v-if="invoice.type === 'new' && invoice.recurring_price" label="每期续费价">¥{{ invoice.recurring_price }}</el-descriptions-item>
        <el-descriptions-item label="付款渠道">
          {{
            invoice.payment_method === "balance"
              ? "账户余额"
              : invoice.payment_method || "—"
          }}
</el-descriptions-item
        ><el-descriptions-item label="报价截止时间">
          {{
            invoice.quote_expired_at ? invoice.quote_expired_at + " UTC" : "—"
          }}
        </el-descriptions-item>
        <el-descriptions-item v-for="(item, index) in invoice.configuration" :key="index" :label="item.label">{{ item.value }}</el-descriptions-item>
      </el-descriptions>
      <p v-if="invoice.status === 'paid'" class="muted">
        {{ invoice.type === 'renew' ? '续费权益已记账，请查看实例的上游确认状态。欠费暂停不会仅凭付款解除。' : invoice.type === 'recharge' ? '充值款项已记账。' : invoice.type === 'upgrade' ? '补差款项已记账，实际变更以实例及处理记录为准。' : '付款成功，请等待交付。付款状态不代表资源已经开通。' }}
      </p>
      <div class="actions">
        <RouterLink to="/invoices">返回我的账单</RouterLink>
        <RouterLink v-if="invoice.instance_id" :to="'/instances/' + invoice.instance_id"
          >查看实例与交付状态</RouterLink
        ><el-button
          v-if="actionable && !invoice.quote_expired"
          :disabled="busy"
          @click="epay"
          >
易支付付款
</el-button
        >
        <el-button
          v-if="actionable && !invoice.quote_expired"
          type="primary"
          :disabled="busy"
          @click="action('pay')"
        >
          余额付款
</el-button
        ><el-popconfirm
          v-if="actionable"
          title="确认取消这笔未付订单？"
          @confirm="action('cancel')"
        >
          <template #reference>
            <el-button :disabled="busy">取消订单</el-button>
          </template>
</el-popconfirm
        ><el-button :disabled="busy" @click="load">刷新状态</el-button>
      </div>
      <el-alert v-if="invoice.status === 'unpaid' && invoice.quote_expired" title="此新购报价已过期，不能继续付款。请取消原单后重新选购。" type="warning" :closable="false" />
</el-card
    ><el-button v-else :loading="busy" @click="load">重新加载</el-button>
  </section>
</template>
