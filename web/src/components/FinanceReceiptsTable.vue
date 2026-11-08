<script setup lang="ts">
import { receiptLabels, type Receipt } from "../api/finance";
defineProps<{ rows: Receipt[] }>();
const purposes: Record<string, string> = {
  wallet_recharge: "正常充值",
  late_wallet_credit: "独立入钱包",
  cloud_consumption: "云消费",
};
const refunds: Record<string, string> = {
  none: "无退款登记", pending: "退款待核验", success: "已登记退款", failed: "退款未成功",
};
</script>
<template>
  <el-table :data="rows" empty-text="暂无收款凭证或付款尝试">
    <el-table-column prop="id" label="凭证编号" width="100" />
    <el-table-column label="账单 / 客户" min-width="180">
      <template #default="{ row }">
        <RouterLink :to="'/admin/finance/invoices/' + row.invoice_id">
          账单 #{{ row.invoice_id }}
        </RouterLink>
        <br>
        客户 #{{ row.user_id }}
        <br>
        {{ row.user_email }}
      </template>
    </el-table-column>
    <el-table-column prop="channel" label="渠道" width="95" />
    <el-table-column label="网关交易号 / 内部流水" min-width="230">
      <template #default="{ row }">
        <span class="mono">{{ row.gateway_trade_no || (row.channel === 'balance' ? '站内余额结算' : row.channel === 'demo' ? '演示凭证' : '尚无实收交易号') }}</span>
        <br>
        <span class="muted mono">{{ row.trade_no }}</span>
      </template>
    </el-table-column>
    <el-table-column label="金额" width="130">
      <template #default="{ row }">
        {{ row.currency }} {{ row.amount }}
      </template>
    </el-table-column>
    <el-table-column label="确认状态 / 用途" min-width="190">
      <template #default="{ row }">
        {{ receiptLabels[row.status] || row.status }}
        <br>
        {{ purposes[row.settlement_purpose || ""] || (row.status === 'pending' ? '尚未结算' : '历史用途待核验') }}
      </template>
    </el-table-column>
    <el-table-column label="付款时间（UTC）" min-width="185">
      <template #default="{ row }">{{ row.paid_at || "尚未付款" }}</template>
    </el-table-column>
    <el-table-column label="退款登记 / 金额" min-width="170">
      <template #default="{ row }">
        {{ refunds[row.refund_status] || row.refund_status }}<br>
        {{ row.currency }} {{ row.refund_amount }}
      </template>
    </el-table-column>
  </el-table>
</template>
