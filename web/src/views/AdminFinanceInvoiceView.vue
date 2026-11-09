<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { adminApi } from "../api/admin";
import { message } from "../api/request";
import {
  invoiceLabels,
  invoiceTypes,
  type FinanceDetail,
} from "../api/finance";
import { fulfillmentLabels } from "../api/billing";
import WalletLedgerTable from "../components/WalletLedgerTable.vue";
import FinanceReceiptsTable from "../components/FinanceReceiptsTable.vue";
const route = useRoute(),
  data = ref<FinanceDetail>(),
  loading = ref(false),
  error = ref(""),
  receiptPage = ref(1),
  transactionPage = ref(1);
let generation = 0;
async function load() {
  const attempt = ++generation;
  loading.value = true;
  error.value = "";
  try {
    const result = await adminApi<FinanceDetail>(
      `/finance/invoices/${route.params.id}?receipts_page=${receiptPage.value}&transactions_page=${transactionPage.value}`,
    );
    if (attempt === generation) data.value = result;
  } catch (e) {
    if (attempt === generation) {
      data.value = undefined;
      error.value = message(e);
    }
  } finally {
    if (attempt === generation) loading.value = false;
  }
}
function receiptTurn(page: number) {
  receiptPage.value = page;
  void load();
}
function transactionTurn(page: number) {
  transactionPage.value = page;
  void load();
}
onMounted(load);
</script>
<template>
  <section class="finance-page">
    <RouterLink class="back-link" to="/admin/finance">
      ← 返回资金查询
    </RouterLink>
    <div class="page-heading">
      <div>
        <p class="eyebrow">财务工作区 · 只读</p>
        <h1>账单资金详情 #{{ route.params.id }}</h1>
      </div>
      <el-button :loading="loading" @click="load">刷新资金详情</el-button>
    </div>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-card v-if="loading && !data" shadow="never">
      <el-skeleton :rows="7" animated />
    </el-card>
    <template v-if="data">
      <div class="wallet-columns">
        <el-card shadow="never">
          <template #header><h2>原账单</h2></template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="账单类型">
              {{ invoiceTypes[data.invoice.type] || data.invoice.type }}
            </el-descriptions-item>
            <el-descriptions-item label="状态">
              {{ invoiceLabels[data.invoice.status] || data.invoice.status }}
            </el-descriptions-item>
            <el-descriptions-item v-if="data.invoice.fulfillment_status" label="履约／结清">{{ fulfillmentLabels[data.invoice.fulfillment_status] || data.invoice.fulfillment_status }}</el-descriptions-item>
            <el-descriptions-item label="应付金额">
              ¥{{ data.invoice.total }}
            </el-descriptions-item>
            <el-descriptions-item label="结算渠道">
              {{ data.invoice.payment_method === "manual_zero" ? "零额人工结清" : data.invoice.payment_method || "—" }}
            </el-descriptions-item>
            <el-descriptions-item label="关联实例">
              <RouterLink v-if="data.invoice.instance_id" :to="`/admin/instances/${data.invoice.instance_id}`">#{{ data.invoice.instance_id }}</RouterLink>
              <span v-else>无实例（独立充值）</span>
            </el-descriptions-item>
            <el-descriptions-item label="付款时间UTC">
              {{ data.invoice.paid_at || "—" }}
            </el-descriptions-item>
          </el-descriptions>
        </el-card>
        <el-card shadow="never">
          <template #header><h2>客户与当前钱包</h2></template>
          <p class="muted">
            <RouterLink :to="`/admin/customers/${data.customer.id}`">客户 #{{ data.customer.id }} · {{ data.customer.email }}</RouterLink>
          </p>
          <p class="field-label">当前可用余额 · CNY</p>
          <p class="renewal-price">¥{{ data.customer.balance }}</p>
          <p class="muted">
            当前余额可能已用于后续消费，不等于本单原始到账额。账单取消不代表没有独立迟到款；退款仍须凭真实凭证人工办理。
          </p>
          <RouterLink
            :to="{
              path: '/admin/finance',
              query: {
                user_id: String(data.customer.id),
                source: 'transactions',
              },
            }"
          >
            查看该客户全部钱包流水 ↗
          </RouterLink>
        </el-card>
      </div>
      <el-card shadow="never">
        <template #header><h2>付款尝试与实收凭证</h2></template>
        <FinanceReceiptsTable :rows="data.receipts.items" />
        <el-pagination
          class="task-pagination"
          :current-page="data.receipts.page"
          :page-size="data.receipts.per_page"
          :total="data.receipts.total"
          :pager-count="5"
          layout="prev, pager, next, total"
          @current-change="receiptTurn"
        />
      </el-card>
      <el-card class="finance-results" shadow="never">
        <template #header><h2>关联钱包流水</h2></template>
        <WalletLedgerTable :rows="data.transactions.items" admin />
        <el-pagination
          class="task-pagination"
          :current-page="data.transactions.page"
          :page-size="data.transactions.per_page"
          :total="data.transactions.total"
          :pager-count="5"
          layout="prev, pager, next, total"
          @current-change="transactionTurn"
        />
        <p class="muted">
          外部直付云消费不会产生虚假的钱包支出；其资金凭证在上方收款列表中。
        </p>
      </el-card>
    </template>
    <el-button v-if="error" @click="load">重新加载资金详情</el-button>
  </section>
</template>
