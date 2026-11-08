<script setup lang="ts">
import { useAdmin } from "../stores/admin";
import AdminSnapshotForm from "../components/AdminSnapshotForm.vue";
const account = useAdmin();
</script>
<template>
  <RouterLink to="/admin/products">返回商品列表</RouterLink>
  <h1>从上游导入商品</h1>
  <el-card class="form-page"><AdminSnapshotForm v-if="account.admin?.role === 'super_admin'" /><el-alert v-else title="导入商品需要启用的超级管理员" type="warning" :closable="false" /></el-card>
</template>
