<script setup lang="ts">
import { serviceLabels, type AdminInstance } from "../api/adminQueries";
defineProps<{ rows: AdminInstance[] }>();
</script>
<template>
  <el-table :data="rows" empty-text="没有符合条件的实例" row-key="id">
    <el-table-column label="实例" min-width="200"><template #default="{ row }"><RouterLink :to="`/admin/instances/${row.id}`">#{{ row.id }} · {{ row.name }}</RouterLink></template></el-table-column>
    <el-table-column label="所属客户" min-width="220"><template #default="{ row }"><RouterLink :to="`/admin/customers/${row.user_id}`">#{{ row.user_id }} · {{ row.user_email }}</RouterLink></template></el-table-column>
    <el-table-column label="商品" min-width="180"><template #default="{ row }"><RouterLink :to="`/admin/products/${row.product_id}`">#{{ row.product_id }} · {{ row.product_name }}</RouterLink></template></el-table-column>
    <el-table-column label="服务状态" width="110"><template #default="{ row }">{{ serviceLabels[row.status] || row.status }}</template></el-table-column>
    <el-table-column label="IP" min-width="180"><template #default="{ row }">{{ row.ip_address || '未回填' }}</template></el-table-column>
    <el-table-column label="上游资源编号" min-width="180"><template #default="{ row }">{{ row.remote_instance_id || '未回填' }}</template></el-table-column>
    <el-table-column label="到期日（UTC）" min-width="185"><template #default="{ row }">{{ row.due_date || '尚未起租' }}</template></el-table-column>
  </el-table>
</template>
