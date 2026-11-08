<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api, message, type Invoice } from "../api/request";
import type { Page } from "../api/admin";
import { ledgerLabels, type WalletEntry } from "../api/finance";
import { useUser } from "../stores/user";
import WalletLedgerTable from "../components/WalletLedgerTable.vue";
const router = useRouter(),
  route = useRoute(),
  account = useUser();
const balance = ref<string>(),
  entries = ref<Page<WalletEntry>>(),
  loading = ref(true),
  creating = ref(false),
  error = ref(""),
  createError = ref(""),
  amount = ref("");
const page = ref(Number(route.query.page) || 1),
  type = ref(String(route.query.type || ""));
let requestKey = crypto.randomUUID();
watch(amount, () => {
  requestKey = crypto.randomUUID();
});
async function load() {
  loading.value = true;
  error.value = "";
  try {
    const [wallet, ledger] = await Promise.all([
      api<{ balance: string }>("/wallet"),
      api<Page<WalletEntry>>(
        "/wallet/transactions?" +
          new URLSearchParams({ page: String(page.value), type: type.value }),
      ),
    ]);
    balance.value = wallet.balance;
    entries.value = ledger;
    if (account.user) account.user.balance = wallet.balance;
  } catch (e) {
    error.value = message(e);
    entries.value = undefined;
    balance.value = undefined;
  } finally {
    loading.value = false;
  }
}
async function recharge() {
  if (creating.value) return;
  creating.value = true;
  createError.value = "";
  try {
    const bill = await api<Invoice>("/wallet/recharges", {
      method: "POST",
      body: { amount: amount.value.trim(), request_key: requestKey },
    });
    await router.push("/invoices/" + bill.invoice_id);
  } catch (e) {
    createError.value =
      message(e) +
      "；请求结果不明时请保留当前金额重试，或先到我的账单核对原单。";
  } finally {
    creating.value = false;
  }
}
function filter(next = 1) {
  void router.push({
    path: "/wallet",
    query: { page: String(next), type: type.value },
  });
}
onMounted(load);
</script>
<template>
  <section class="finance-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">账户资金</p>
        <h1>余额与充值</h1>
        <p class="muted">充值到账后，可用于购买产品和续费已有实例。</p>
      </div>
      <el-button :loading="loading" @click="load">刷新余额与流水</el-button>
    </div>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <div class="wallet-columns">
      <el-card shadow="never">
        <p class="field-label">当前可用余额 · CNY</p>
        <p class="renewal-price">¥{{ balance ?? "—" }}</p>
        <p class="muted">以实际到账流水为准，未付款充值单不会增加余额。</p>
        <div class="actions">
          <RouterLink to="/invoices">查找未付／历史账单 ↗</RouterLink>
          <RouterLink to="/">选购产品 ↗</RouterLink>
        </div>
      </el-card>
      <el-card shadow="never">
        <template #header><h2>充值到账户余额</h2></template>
        <el-alert
          v-if="createError"
          :title="createError"
          type="error"
          :closable="false"
          role="alert"
        />
        <el-form label-position="top" @submit.prevent="recharge">
          <el-form-item label="充值金额（元）">
            <el-input
              v-model="amount"
              aria-label="充值金额"
              inputmode="decimal"
              placeholder="例如 50.00"
              maxlength="11"
              :disabled="creating"
              required
            />
          </el-form-item>
          <div class="amount-shortcuts">
            <el-button
              v-for="value in ['10.00', '50.00', '100.00']"
              :key="value"
              :disabled="creating"
              @click="amount = value"
            >
              ¥{{ value }}
            </el-button>
          </div>
          <p class="muted">
            创建充值账单后使用易支付付款。若已支付，请先查原单，不要重复付款。
          </p>
          <el-button type="primary" native-type="submit" :loading="creating">
            创建充值账单
          </el-button>
        </el-form>
      </el-card>
    </div>
    <el-card shadow="never">
      <template #header>
        <div class="panel-heading">
          <h2>个人钱包流水</h2>
          <span class="muted">金额单位 CNY</span>
        </div>
      </template>
      <div class="finance-toolbar">
        <el-select v-model="type" aria-label="流水类型" @change="filter()">
          <el-option label="全部类型" value="" />
          <el-option
            v-for="(label, key) in ledgerLabels"
            :key="key"
            :label="label"
            :value="key"
          />
        </el-select>
      </div>
      <div v-if="!error" v-loading="loading">
        <WalletLedgerTable :rows="entries?.items || []" />
      </div>
      <el-pagination
        v-if="entries"
        class="task-pagination"
        :current-page="entries.page"
        :page-size="entries.per_page"
        :total="entries.total"
        :pager-count="5"
        layout="prev, pager, next, total"
        @current-change="filter"
      />
      <p class="muted">
        变动后余额是当时的余额。迟到或额外实收单独入账，不改变原账单状态。
      </p>
    </el-card>
  </section>
</template>
