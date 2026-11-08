<script setup lang="ts">
import { computed } from "vue";
import { useAdmin } from "../stores/admin";
const props = defineProps<{ userId: number; instanceId?: number; invoiceId?: number }>();
const account = useAdmin();
const finance = computed(() => ["finance", "super_admin"].includes(account.admin?.role || ""));
const scope = computed(() => ({ user_id: String(props.userId), ...(props.instanceId ? { instance_id: String(props.instanceId) } : {}), ...(props.invoiceId ? { invoice_id: String(props.invoiceId) } : {}) }));
</script>
<template>
  <div class="query-links">
    <RouterLink :to="{ path: '/admin/tasks', query: { ...scope, status: 'attention' } }">查看关联异常任务</RouterLink>
    <RouterLink :to="{ path: '/admin/tasks', query: { ...scope, status: 'all' } }">查看全部关联任务</RouterLink>
    <template v-if="finance">
      <RouterLink v-for="(label, source) in { invoices: '查看关联账单', receipts: '查看收款凭证', transactions: '查看钱包流水' }" :key="source" :to="{ path: '/admin/finance', query: { ...scope, source } }">{{ label }}</RouterLink>
    </template>
  </div>
  <p v-if="!finance" class="muted">金额、收款凭证与钱包流水仅财务／超级管理员可查看。</p>
</template>
