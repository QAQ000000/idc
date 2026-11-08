<script setup lang="ts">
import { ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api, message } from "../api/request";
import type { Page } from "../api/admin";
import { invoiceLabels, invoiceTypes } from "../api/finance";
import {
  fulfillmentTitle,
  fulfillmentTone,
  statusTone,
  hasCredit,
  type InvoiceRow,
} from "../api/billing";
const route = useRoute(),
  router = useRouter();
const result = ref<Page<InvoiceRow>>(),
  busy = ref(false),
  error = ref(""),
  status = ref("all"),
  type = ref("all");
let generation = 0;
async function load() {
  const attempt = ++generation;
  busy.value = true;
  error.value = "";
  status.value =
    typeof route.query.status === "string" ? route.query.status : "all";
  type.value = typeof route.query.type === "string" ? route.query.type : "all";
  try {
    const data = await api<Page<InvoiceRow>>(
      "/invoices?" +
        new URLSearchParams({
          page: String(route.query.page ?? 1),
          per_page: "20",
          status: status.value,
          type: type.value,
        }),
    );
    if (attempt === generation) result.value = data;
  } catch (e) {
    if (attempt === generation) {
      result.value = undefined;
      error.value = message(e);
    }
  } finally {
    if (attempt === generation) busy.value = false;
  }
}
function filter(page = 1) {
  void router.push({
    path: "/invoices",
    query: { page: String(page), status: status.value, type: type.value },
  });
}
watch(() => route.fullPath, load, { immediate: true });
</script>
<template>
  <section class="billing-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">客户控制台</p>
        <h1>我的账单</h1>
        <p class="muted">在原账单继续付款、查看资金去向和本单履约进度。</p>
      </div>
      <el-button :loading="busy" @click="load">刷新账单</el-button>
    </div>
    <el-card shadow="never">
      <div class="invoice-filters">
        <el-select v-model="type" aria-label="账单类型" @change="filter()">
          <el-option label="全部类型" value="all" />
          <el-option
            v-for="(label, key) in invoiceTypes"
            :key="key"
            :label="label"
            :value="key"
          />
        </el-select>
        <el-select v-model="status" aria-label="账单状态" @change="filter()">
          <el-option label="全部资金状态" value="all" />
          <el-option
            v-for="(label, key) in invoiceLabels"
            :key="key"
            :label="label"
            :value="key"
          />
        </el-select>
        <RouterLink to="/wallet">余额与充值 ↗</RouterLink>
      </div>
      <el-alert
        v-if="error"
        :title="error"
        type="error"
        :closable="false"
        role="alert"
      />
      <el-button v-if="error" @click="load">重新加载账单</el-button>
      <el-skeleton v-if="busy && !result" :rows="6" animated />
      <el-table
        v-else-if="!error"
        v-loading="busy"
        :data="result?.items ?? []"
        empty-text="没有符合条件的账单"
      >
        <el-table-column label="账单 / 类型" width="145">
          <template #default="{ row }">
            <RouterLink :to="'/invoices/' + row.invoice_id">
              #{{ row.invoice_id }}
            </RouterLink>
            <p class="cell-note">{{ invoiceTypes[row.type] || row.type }}</p>
          </template>
        </el-table-column>
        <el-table-column label="关联实例" min-width="170">
          <template #default="{ row }">
            <RouterLink
              v-if="row.instance_id"
              :to="'/instances/' + row.instance_id"
            >
              {{ row.instance_name || "#" + row.instance_id }}
            </RouterLink>
            <span v-else>
              {{ row.type === "recharge" ? "独立充值，无实例" : "无关联实例" }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="本单应付" width="130">
          <template #default="{ row }">
            <strong>¥{{ row.total }}</strong>
          </template>
        </el-table-column>
        <el-table-column label="资金 / 履约状态" min-width="225">
          <template #default="{ row }">
            <div class="invoice-tags">
              <el-tag :type="statusTone(row.status)">
                {{ invoiceLabels[row.status] || row.status }}
              </el-tag>
              <el-tag
                v-if="row.fulfillment_status"
                :type="fulfillmentTone(row.fulfillment_status)"
              >
                {{ fulfillmentTitle(row) }}
              </el-tag>
            </div>
            <p
              v-if="row.status === 'unpaid' && row.quote_expired"
              class="cell-note"
            >
              新购报价已过期
            </p>
            <p v-if="hasCredit(row.wallet_credit_total)" class="cell-note">
              本单已入钱包 ¥{{ row.wallet_credit_total }}
            </p>
            <p v-if="hasCredit(row.late_credit_total)" class="cell-note">
              含迟到／额外实收，父单状态保持
            </p>
          </template>
        </el-table-column>
        <el-table-column
          label="创建时间（UTC）"
          prop="created_at"
          min-width="190"
        />
        <el-table-column label="下一步" min-width="150">
          <template #default="{ row }">
            <RouterLink :to="'/invoices/' + row.invoice_id">
              {{
                row.status === "unpaid" &&
                !row.quote_expired &&
                !hasCredit(row.late_credit_total)
                  ? "继续原单"
                  : "查看详情与资金"
              }}
            </RouterLink>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-if="result && !error"
        class="task-pagination"
        layout="prev, pager, next, total"
        :pager-count="5"
        :total="result.total"
        :page-size="result.per_page"
        :current-page="result.page"
        @current-change="filter"
      />
    </el-card>
    <p class="muted">
      资金状态与履约进度分别确认：已付款不代表已交付，调查或待核验时请保留原单，不要重复付款。
    </p>
  </section>
</template>
