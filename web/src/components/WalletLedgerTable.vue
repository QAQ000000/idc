<script setup lang="ts">
import { ledgerLabels, type WalletEntry } from "../api/finance";
defineProps<{ rows: WalletEntry[]; admin?: boolean }>();
</script>
<template>
  <el-table :data="rows" empty-text="暂无钱包变动记录">
    <el-table-column prop="id" label="流水编号" width="100" />
    <el-table-column v-if="admin" label="客户" min-width="190">
      <template #default="{ row }">
        #{{ row.user_id }}
        <br>
        {{ row.user_email }}
      </template>
    </el-table-column>
    <el-table-column label="类型" min-width="170">
      <template #default="{ row }">
        {{ ledgerLabels[row.type] || row.type }}
      </template>
    </el-table-column>
    <el-table-column label="变动金额" width="130">
      <template #default="{ row }">
        <strong :class="row.amount.startsWith('-') ? 'money-out' : 'money-in'">
          {{ row.amount.startsWith("-") ? "" : "+" }}{{ row.amount }}
        </strong>
      </template>
    </el-table-column>
    <el-table-column prop="balance_before" label="变动前余额" width="130" />
    <el-table-column prop="balance_after" label="变动后余额" width="130" />
    <el-table-column label="关联账单" width="115">
      <template #default="{ row }">
        <RouterLink
          v-if="row.invoice_id"
          :to="`${admin ? '/admin/finance' : ''}/invoices/${row.invoice_id}`"
        >
          #{{ row.invoice_id }}
        </RouterLink>
        <span v-else>—</span>
      </template>
    </el-table-column>
    <el-table-column label="收款凭证" width="110">
      <template #default="{ row }">
        {{ row.payment_record_id ? "#" + row.payment_record_id : "—" }}
      </template>
    </el-table-column>
    <el-table-column
      prop="created_at"
      label="变动时间（UTC）"
      min-width="185"
    />
  </el-table>
</template>
