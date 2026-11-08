<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { adminApi, type Page } from "../api/admin";
import { message } from "../api/request";
import {
  invoiceLabels,
  invoiceTypes,
  type FinanceInvoice,
  type Receipt,
  type WalletEntry,
} from "../api/finance";
import WalletLedgerTable from "../components/WalletLedgerTable.vue";
import FinanceReceiptsTable from "../components/FinanceReceiptsTable.vue";
const router = useRouter(),
  route = useRoute();
const kinds: Record<string, string> = {
  invoices: "账单",
  receipts: "收款凭证",
  transactions: "钱包流水",
};
const source = ref(
  Object.prototype.hasOwnProperty.call(kinds, String(route.query.source))
    ? String(route.query.source)
    : "invoices",
);
const filters = reactive(
  Object.fromEntries(
    [
      "user_id",
      "email",
      "invoice_id",
      "gateway_trade_no",
      "channel",
      "from",
      "to",
    ].map((key) => [key, String(route.query[key] || "")]),
  ) as Record<string, string>,
);
const page = ref(Number(route.query.page) || 1),
  data = ref<Page<FinanceInvoice | Receipt | WalletEntry>>(),
  loading = ref(false),
  error = ref("");
async function load() {
  loading.value = true;
  error.value = "";
  try {
    data.value = await adminApi<Page<FinanceInvoice | Receipt | WalletEntry>>(
      "/finance/" +
        source.value +
        "?" +
        new URLSearchParams({ ...filters, page: String(page.value) }),
    );
  } catch (e) {
    data.value = undefined;
    error.value = message(e);
  } finally {
    loading.value = false;
  }
}
async function search(next = 1) {
  const target = {
    path: "/admin/finance",
    query: { ...filters, source: source.value, page: String(next) },
  };
  if (router.resolve(target).fullPath === route.fullPath) await load();
  else await router.push(target);
}
function clear() {
  for (const key of Object.keys(filters)) filters[key] = "";
  void search();
}
onMounted(load);
</script>
<template>
  <section class="finance-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">财务工作区 · 只读</p>
        <h1>资金查询</h1>
        <p class="muted">关联原账单、实际收款与钱包变动，协助人工对账。</p>
      </div>
      <el-button :loading="loading" @click="load">刷新查询</el-button>
    </div>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-card shadow="never">
      <el-form
        class="task-filters"
        label-position="top"
        @submit.prevent="search()"
      >
        <el-form-item label="客户 ID">
          <el-input
            v-model="filters.user_id"
            aria-label="客户ID筛选"
            inputmode="numeric"
          />
        </el-form-item>
        <el-form-item label="客户邮箱（精确）">
          <el-input
            v-model="filters.email"
            aria-label="客户邮箱筛选"
            maxlength="100"
          />
        </el-form-item>
        <el-form-item label="账单编号">
          <el-input
            v-model="filters.invoice_id"
            aria-label="账单编号筛选"
            inputmode="numeric"
          />
        </el-form-item>
        <el-form-item label="网关交易号（精确）">
          <el-input
            v-model="filters.gateway_trade_no"
            aria-label="网关交易号筛选"
            maxlength="100"
          />
        </el-form-item>
        <el-form-item label="渠道">
          <el-select v-model="filters.channel" aria-label="渠道筛选">
            <el-option label="全部渠道" value="" />
            <el-option label="易支付" value="epay" />
            <el-option label="账户余额" value="balance" />
          </el-select>
        </el-form-item>
        <el-form-item label="起始时间（UTC）">
          <el-input
            v-model="filters.from"
            aria-label="起始时间UTC"
            placeholder="YYYY-MM-DD HH:mm:ss"
            maxlength="19"
          />
        </el-form-item>
        <el-form-item label="结束时间（UTC）">
          <el-input
            v-model="filters.to"
            aria-label="结束时间UTC"
            placeholder="YYYY-MM-DD HH:mm:ss"
            maxlength="19"
          />
        </el-form-item>
        <el-form-item label="操作">
          <el-button type="primary" native-type="submit" :disabled="loading">
            查询
          </el-button>
          <el-button :disabled="loading" @click="clear">清空</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <el-card class="finance-results" shadow="never">
      <el-tabs v-model="source" @tab-change="search()">
        <el-tab-pane
          v-for="(label, key) in kinds"
          :key="key"
          :name="key"
          :label="label"
        />
      </el-tabs>
      <p class="muted">
        {{
          source === "invoices"
            ? "时间筛选按账单创建时间；账单金额不是额外实收金额。"
            : source === "receipts"
              ? "时间筛选按付款时间（未付按创建时间）；pending 仅表示付款尝试，不证明实收。"
              : "时间筛选按钱包变动时间；按网关查询仅显示可关联该笔实收的流水。"
        }}
      </p>
      <div v-if="!error" v-loading="loading">
        <FinanceReceiptsTable
          v-if="source === 'receipts'"
          :rows="(data?.items || []) as Receipt[]"
        />
        <WalletLedgerTable
          v-else-if="source === 'transactions'"
          :rows="(data?.items || []) as WalletEntry[]"
          admin
        />
        <el-table
          v-else
          :data="data?.items || []"
          empty-text="没有符合条件的账单"
        >
          <el-table-column label="账单" width="110">
            <template #default="{ row }">
              <RouterLink :to="'/admin/finance/invoices/' + row.invoice_id">
                #{{ row.invoice_id }}
              </RouterLink>
            </template>
          </el-table-column>
          <el-table-column label="客户" min-width="190">
            <template #default="{ row }">
              #{{ row.user_id }}
              <br>
              {{ row.user_email }}
            </template>
          </el-table-column>
          <el-table-column label="类型" width="130">
            <template #default="{ row }">
              {{ invoiceTypes[row.type] || row.type }}
            </template>
          </el-table-column>
          <el-table-column label="应付金额" width="130">
            <template #default="{ row }">¥{{ row.total }}</template>
          </el-table-column>
          <el-table-column label="状态" width="120">
            <template #default="{ row }">
              {{ invoiceLabels[row.status] || row.status }}
            </template>
          </el-table-column>
          <el-table-column prop="payment_method" label="结算渠道" width="120" />
          <el-table-column
            prop="created_at"
            label="创建时间（UTC）"
            min-width="185"
          />
        </el-table>
      </div>
      <el-pagination
        v-if="data"
        class="task-pagination"
        :current-page="data.page"
        :page-size="data.per_page"
        :total="data.total"
        :pager-count="5"
        layout="prev, pager, next, total"
        @current-change="search"
      />
    </el-card>
  </section>
</template>
