<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { api, message, type Invoice } from "../api/request";
import type { Page } from "../api/admin";
import { invoiceLabels, invoiceTypes, type WalletEntry } from "../api/finance";
import { useUser } from "../stores/user";
import WalletLedgerTable from "../components/WalletLedgerTable.vue";
const route = useRoute(),
  account = useUser();
const invoice = ref<Invoice | null>(null),
  error = ref(""),
  busy = ref(false),
  credits = ref<Page<WalletEntry>>(),
  creditPage = ref(1),
  balance = ref<string>();
const actionable = computed(
  () =>
    invoice.value?.status === "unpaid" &&
    ["new", "renew", "recharge"].includes(invoice.value.type),
);
async function walletResult() {
  if (invoice.value?.type !== "recharge") return;
  const [wallet, ledger] = await Promise.all([
    api<{ balance: string }>("/wallet"),
    api<Page<WalletEntry>>(
      `/wallet/transactions?invoice_id=${route.params.id}&page=${creditPage.value}`,
    ),
  ]);
  balance.value = wallet.balance;
  credits.value = ledger;
  if (account.user) account.user.balance = wallet.balance;
}
async function load() {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  credits.value = undefined;
  try {
    invoice.value = await api<Invoice>("/invoices/" + route.params.id);
    await walletResult();
  } catch (e) {
    invoice.value = null;
    balance.value = undefined;
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
    invoice.value = await api<Invoice>(`/invoices/${route.params.id}/${name}`, {
      method: "POST",
      body: name === "pay" ? { gateway: "balance" } : {},
    });
    await walletResult();
    if (invoice.value.type !== "recharge") await account.load();
  } catch (e) {
    error.value = message(e) + "；请先刷新原账单和流水核对结果。";
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
    }>(`/invoices/${route.params.id}/pay`, {
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
    error.value = message(e) + "；如已付款，请先刷新到账结果，勿重复支付。";
  } finally {
    busy.value = false;
  }
}
function turn(page: number) {
  creditPage.value = page;
  void load();
}
onMounted(load);
</script>
<template>
  <section
    :class="invoice?.type === 'recharge' ? 'recharge-detail finance-page' : 'form-page'"
  >
    <div class="page-heading">
      <div>
        <p class="eyebrow">我的账单</p>
        <h1>
          {{ invoice?.type === "recharge" ? "充值账单与到账结果" : "账单详情" }}
        </h1>
      </div>
      <el-button :disabled="busy" :loading="busy" @click="load">
        刷新状态
      </el-button>
    </div>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-card v-if="invoice" v-loading="busy" shadow="never">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="账单类型">
          {{ invoiceTypes[invoice.type] || invoice.type }}
        </el-descriptions-item>
        <el-descriptions-item label="账单编号">
          {{ invoice.invoice_id }}
        </el-descriptions-item>
        <el-descriptions-item label="状态">
          {{ invoiceLabels[invoice.status] || invoice.status }}
        </el-descriptions-item>
        <el-descriptions-item label="应付金额">
          ¥{{ invoice.total }}
        </el-descriptions-item>
        <el-descriptions-item
          v-if="invoice.type === 'new' && invoice.recurring_price"
          label="每期续费价"
        >
          ¥{{ invoice.recurring_price }}
        </el-descriptions-item>
        <el-descriptions-item label="付款渠道">
          {{
            invoice.payment_method === "balance"
              ? "账户余额"
              : invoice.payment_method || "—"
          }}
        </el-descriptions-item>
        <el-descriptions-item label="付款时间UTC">
          {{ invoice.paid_at || "—" }}
        </el-descriptions-item>
        <el-descriptions-item
          v-if="invoice.type !== 'recharge'"
          label="报价截止时间UTC"
        >
          {{ invoice.quote_expired_at || "—" }}
        </el-descriptions-item>
        <el-descriptions-item
          v-for="(item, index) in invoice.configuration"
          :key="index"
          :label="item.label"
        >
          {{ item.value }}
        </el-descriptions-item>
      </el-descriptions>
      <p v-if="invoice.status === 'paid'" class="muted">
        {{
          invoice.type === "renew"
            ? "续费权益已记账，请查看实例的上游确认状态。欠费暂停不会仅凭付款解除。"
            : invoice.type === "recharge"
              ? "充值款项已记入钱包，实际到账及变动后余额见下方流水。"
              : invoice.type === "upgrade"
                ? "补差款项已记账，实际变更以实例及处理记录为准。"
                : "付款成功，请等待交付。付款状态不代表资源已经开通。"
        }}
      </p>
      <div class="actions">
        <RouterLink to="/invoices">返回我的账单</RouterLink>
        <RouterLink v-if="invoice.type === 'recharge'" to="/wallet">
          返回余额与充值
        </RouterLink>
        <RouterLink
          v-if="invoice.instance_id"
          :to="'/instances/' + invoice.instance_id"
        >
          查看实例与交付状态
        </RouterLink>
        <el-button
          v-if="actionable && !invoice.quote_expired"
          :disabled="busy"
          type="primary"
          @click="epay"
        >
          易支付付款
        </el-button>
        <el-button
          v-if="
            actionable && invoice.type !== 'recharge' && !invoice.quote_expired
          "
          :disabled="busy"
          @click="action('pay')"
        >
          余额付款
        </el-button>
        <el-popconfirm
          v-if="actionable"
          title="确认取消这笔未付账单？"
          @confirm="action('cancel')"
        >
          <template #reference>
            <el-button :disabled="busy">取消订单</el-button>
          </template>
        </el-popconfirm>
      </div>
      <el-alert
        v-if="invoice.status === 'unpaid' && invoice.quote_expired"
        class="finance-results"
        title="此新购报价已过期，不能继续付款。请取消原单后重新选购。"
        type="warning"
        :closable="false"
      />
      <el-alert
        v-if="invoice.type === 'recharge' && invoice.status === 'unpaid'"
        class="finance-results"
        title="如已完成支付，请先刷新到账结果，不要再次付款。收银台返回参数不代表实际到账。"
        type="info"
        :closable="false"
      />
      <el-alert
        v-if="invoice.type === 'recharge' && invoice.status === 'cancelled'"
        class="finance-results"
        title="充值账单已取消，请停止向原收银台付款。已收到的迟到款单独入钱包，以流水为准。"
        type="warning"
        :closable="false"
      />
    </el-card>
    <el-button v-else :loading="busy" @click="load">重新加载</el-button>
    <el-card
      v-if="invoice?.type === 'recharge' && credits"
      class="finance-results"
      shadow="never"
    >
      <template #header>
        <div class="panel-heading">
          <h2>本单钱包到账记录</h2>
          <span v-if="balance !== undefined" class="muted">
            当前可用 ¥{{ balance }}
          </span>
        </div>
      </template>
      <WalletLedgerTable :rows="credits?.items || []" />
      <el-pagination
        v-if="credits"
        class="task-pagination"
        :current-page="credits.page"
        :page-size="credits.per_page"
        :total="credits.total"
        :pager-count="5"
        layout="prev, pager, next, total"
        @current-change="turn"
      />
      <p class="muted">
        独立迟到或额外实收不修改父账单状态；当前余额可能已用于其他消费。
      </p>
    </el-card>
  </section>
</template>
