<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from "vue";
import { useRoute } from "vue-router";
import { api, message, type Invoice } from "../api/request";
import type { Page } from "../api/admin";
import { invoiceLabels, invoiceTypes, type WalletEntry } from "../api/finance";
import {
  fulfillmentTitle,
  fulfillmentTone,
  statusTone,
  fulfillmentMessage,
  hasCredit,
} from "../api/billing";
import { useUser } from "../stores/user";
import WalletLedgerTable from "../components/WalletLedgerTable.vue";
const route = useRoute(),
  account = useUser();
const invoice = ref<Invoice>(),
  error = ref(""),
  walletError = ref(""),
  busy = ref(false),
  walletBusy = ref(false),
  credits = ref<Page<WalletEntry>>(),
  creditPage = ref(1),
  balance = ref<string>(),
  unknown = ref(false);
let disposed = false,
  walletGeneration = 0;
const actionable = computed(
  () =>
    !unknown.value &&
    invoice.value?.status === "unpaid" &&
    ["new", "renew", "recharge"].includes(invoice.value.type),
);
const explanation = computed(() =>
  invoice.value ? fulfillmentMessage(invoice.value) : "",
);
async function walletResult() {
  const attempt = ++walletGeneration;
  walletBusy.value = true;
  walletError.value = "";
  credits.value = undefined;
  balance.value = undefined;
  try {
    const [wallet, ledger] = await Promise.all([
      api<{ balance: string }>("/wallet"),
      api<Page<WalletEntry>>(
        `/wallet/transactions?invoice_id=${route.params.id}&page=${creditPage.value}`,
      ),
    ]);
    if (attempt === walletGeneration && !disposed) {
      balance.value = wallet.balance;
      credits.value = ledger;
      if (account.user) account.user.balance = wallet.balance;
    }
  } catch (e) {
    if (attempt === walletGeneration && !disposed)
      walletError.value = message(e);
  } finally {
    if (attempt === walletGeneration && !disposed) walletBusy.value = false;
  }
}
async function refresh() {
  const result = await api<Invoice>("/invoices/" + route.params.id);
  if (disposed) return;
  invoice.value = result;
  unknown.value = false;
  await walletResult();
}
async function load() {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    await refresh();
  } catch (e) {
    if (!disposed) {
      invoice.value = undefined;
      credits.value = undefined;
      balance.value = undefined;
      error.value = message(e);
    }
  } finally {
    if (!disposed) busy.value = false;
  }
}
async function action(name: "pay" | "cancel") {
  if (busy.value || !actionable.value) return;
  busy.value = true;
  error.value = "";
  try {
    await api<Invoice>(`/invoices/${route.params.id}/${name}`, {
      method: "POST",
      body: name === "pay" ? { gateway: "balance" } : {},
    });
    await refresh();
  } catch (e) {
    if (!disposed) {
      unknown.value = true;
      error.value =
        message(e) + "；处理结果待确认，请先刷新原账单和流水，勿重复支付。";
    }
  } finally {
    if (!disposed) busy.value = false;
  }
}
async function epay() {
  if (busy.value || !actionable.value) return;
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
    if (disposed) return;
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
    if (!disposed) {
      unknown.value = true;
      error.value =
        message(e) + "；请先刷新原账单；如已付款，请核对到账结果，勿重复支付。";
    }
  } finally {
    if (!disposed) busy.value = false;
  }
}
function turn(page: number) {
  creditPage.value = page;
  void walletResult();
}
onMounted(load);
onBeforeUnmount(() => {
  disposed = true;
  ++walletGeneration;
});
</script>
<template>
  <section class="billing-page">
    <RouterLink class="back-link" to="/invoices">← 返回我的账单</RouterLink>
    <div class="page-heading">
      <div>
        <p class="eyebrow">我的账单 · #{{ route.params.id }}</p>
        <h1>{{ invoiceTypes[invoice?.type || ""] || "账单" }}详情</h1>
        <p class="muted">资金状态、履约进度与实际钱包变动分别展示。</p>
      </div>
      <el-button :loading="busy" @click="load">刷新状态</el-button>
    </div>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-card v-if="busy && !invoice" shadow="never">
      <el-skeleton :rows="7" animated />
    </el-card>
    <template v-if="invoice">
      <div class="invoice-summary">
        <div>
          <p class="field-label">本单应付 · CNY</p>
          <p class="renewal-price">¥{{ invoice.total }}</p>
        </div>
        <div>
          <p class="field-label">资金状态</p>
          <el-tag :type="statusTone(invoice.status)">
            {{ invoiceLabels[invoice.status] || invoice.status }}
          </el-tag>
          <p
            v-if="invoice.status === 'unpaid' && invoice.quote_expired"
            class="muted"
          >
            新购报价已过期
          </p>
        </div>
        <div>
          <p class="field-label">本单履约</p>
          <el-tag
            v-if="invoice.fulfillment_status"
            :type="fulfillmentTone(invoice.fulfillment_status)"
          >
            {{ fulfillmentTitle(invoice) }}
          </el-tag>
          <p v-else class="muted">
            {{
              invoice.type === "recharge"
                ? "独立充值，无资源交付"
                : invoice.status === "unpaid"
                  ? "付款后进入履约"
                  : "以原记录及管理员核验为准"
            }}
          </p>
        </div>
      </div>
      <el-alert
        v-if="explanation"
        :title="explanation"
        :type="
          invoice.fulfillment_status === 'needs_review' ? 'warning' : 'info'
        "
        :closable="false"
        role="status"
      />
      <el-alert
        v-if="hasCredit(invoice.late_credit_total)"
        :title="`本单迟到／额外实收 ¥${invoice.late_credit_total} 已单独记入钱包，原账单状态保持。`"
        :description="
          invoice.status === 'unpaid'
            ? '这笔款项没有结清原账单。请先核对余额与流水，再决定是否继续原单，勿重复扫码。'
            : '请到余额与充值或下方流水核对资金去向；不代表再次交付或已退款。'
        "
        type="warning"
        :closable="false"
        role="status"
      />
      <div class="invoice-detail-columns">
        <el-card shadow="never">
          <template #header><h2>原账单与冻结配置</h2></template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="账单编号">
              #{{ invoice.invoice_id }}
            </el-descriptions-item>
            <el-descriptions-item label="账单类型">
              {{ invoiceTypes[invoice.type] || invoice.type }}
            </el-descriptions-item>
            <el-descriptions-item v-if="invoice.instance_id" label="关联实例">
              <RouterLink :to="'/instances/' + invoice.instance_id">
                {{ invoice.instance_name || "#" + invoice.instance_id }}
              </RouterLink>
            </el-descriptions-item>
            <el-descriptions-item
              v-if="invoice.type === 'new' && invoice.recurring_price"
              label="冻结每期续费价"
            >
              ¥{{ invoice.recurring_price }}
            </el-descriptions-item>
            <el-descriptions-item label="付款渠道">
              {{
                invoice.payment_method === "balance"
                  ? "账户余额"
                  : invoice.payment_method === "manual_zero"
                    ? "零额人工结清"
                    : invoice.payment_method || "尚未结算"
              }}
            </el-descriptions-item>
            <el-descriptions-item label="创建时间UTC">
              {{ invoice.created_at }}
            </el-descriptions-item>
            <el-descriptions-item label="付款时间UTC">
              {{ invoice.paid_at || "尚未结算" }}
            </el-descriptions-item>
            <el-descriptions-item
              v-if="invoice.type === 'new'"
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
        </el-card>
        <el-card shadow="never">
          <template #header><h2>下一步</h2></template>
          <template v-if="invoice.status === 'unpaid'">
            <el-alert
              v-if="invoice.quote_expired"
              title="此新购报价已过期，不能继续付款。请取消原单后重新选购并确认新报价。"
              type="warning"
              :closable="false"
            />
            <p class="muted">
              如已付款，请先刷新本单核对真实到账，不要再次付款。收银台返回参数不能确认结算。
            </p>
            <div class="actions">
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
                  actionable &&
                  invoice.type !== 'recharge' &&
                  !invoice.quote_expired
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
          </template>
          <p v-if="invoice.status === 'cancelled'" class="muted">
            账单已取消，请停止向原收银台付款。真实迟到款单独入钱包，以实际流水为准。
          </p>
          <p
            v-if="['refund_pending', 'refunded'].includes(invoice.status)"
            class="muted"
          >
            退款与资源处置分别核验。具体到账方式以钱包流水、实际退款凭证及管理员记录为准。
          </p>
          <div class="actions">
            <RouterLink
              v-if="invoice.instance_id"
              class="primary-link"
              :to="'/instances/' + invoice.instance_id"
            >
              {{
                invoice.status === "paid"
                  ? "进入对应实例，查看履约结果"
                  : "查看对应实例"
              }}
              ↗
            </RouterLink>
            <RouterLink
              v-if="
                invoice.type === 'recharge' ||
                hasCredit(invoice.wallet_credit_total)
              "
              to="/wallet"
            >
              查看余额与钱包流水 ↗
            </RouterLink>
          </div>
          <p
            v-if="
              invoice.fulfillment_status === 'needs_review' ||
              invoice.fulfillment_status === 'investigating'
            "
            class="muted"
          >
            联系管理员时提供账单 #{{
              invoice.invoice_id
            }}；保留原单，不重新购买。
          </p>
        </el-card>
      </div>
      <el-card class="finance-results" shadow="never">
        <template #header>
          <div class="panel-heading">
            <h2>本单钱包变动与资金去向</h2>
            <span v-if="balance !== undefined" class="muted">
              当前可用 ¥{{ balance }}
            </span>
          </div>
        </template>
        <p v-if="hasCredit(invoice.wallet_credit_total)" class="muted">
          本单累计入钱包 ¥{{
            invoice.wallet_credit_total
          }}，当前余额可能已用于后续消费。
        </p>
        <el-alert
          v-if="walletError"
          :title="'账单已读取，钱包流水暂不可用：' + walletError"
          type="warning"
          :closable="false"
          role="alert"
        />
        <el-button
          v-if="walletError"
          :loading="walletBusy"
          @click="walletResult"
        >
          重试钱包查询
        </el-button>
        <div v-else v-loading="walletBusy">
          <WalletLedgerTable v-if="credits" :rows="credits.items" />
          <el-skeleton v-else :rows="3" animated />
        </div>
        <el-pagination
          v-if="credits && !walletError"
          class="task-pagination"
          :current-page="credits.page"
          :page-size="credits.per_page"
          :total="credits.total"
          :pager-count="5"
          layout="prev, pager, next, total"
          @current-change="turn"
        />
        <p class="muted">
          外部直付消费不产生虚假的钱包支出；没有钱包流水不代表未付款。余额付款记支出，充值／迟到实收记入账。资金以服务端结算与凭证为准。
        </p>
      </el-card>
    </template>
    <el-button v-else-if="!busy" @click="load">重新加载账单</el-button>
  </section>
</template>
