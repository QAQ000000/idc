<script setup lang="ts">
const filters = defineModel<{
  status: string; action: string; task_id: string;
  invoice_id: string; instance_id: string; user_id: string;
}>({ required: true });
defineProps<{ busy: boolean }>();
const emit = defineEmits<{ apply: []; reset: [] }>();
</script>
<template>
  <el-form class="task-filters" label-position="top" @submit.prevent="emit('apply')">
    <el-form-item label="任务状态">
      <el-select v-model="filters.status" aria-label="任务状态">
        <el-option label="异常关注" value="attention" />
        <el-option label="待人工核验" value="manual" />
        <el-option v-for="s in ['investigating','failed','pending','running','success','all']" :key="s" :label="s" :value="s" />
      </el-select>
    </el-form-item>
    <el-form-item label="任务类型">
      <el-select v-model="filters.action" aria-label="任务类型">
        <el-option label="全部类型" value="" />
        <el-option label="新购" value="create" />
        <el-option label="电源操作" value="power" />
        <el-option label="重装系统" value="reinstall" />
        <el-option label="续费" value="renew" />
      </el-select>
    </el-form-item>
    <el-form-item label="任务 ID">
      <el-input v-model="filters.task_id" aria-label="任务 ID" inputmode="numeric" />
    </el-form-item>
    <el-form-item label="账单 ID">
      <el-input v-model="filters.invoice_id" aria-label="账单 ID" inputmode="numeric" />
    </el-form-item>
    <el-form-item label="实例 ID">
      <el-input v-model="filters.instance_id" aria-label="实例 ID" inputmode="numeric" />
    </el-form-item>
    <el-form-item label="客户 ID">
      <el-input v-model="filters.user_id" aria-label="客户 ID" inputmode="numeric" />
    </el-form-item>
    <el-form-item class="filter-form-actions" label="查询">
      <el-button type="primary" native-type="submit" :disabled="busy">应用筛选</el-button>
      <el-button :disabled="busy" @click="emit('reset')">清除筛选</el-button>
    </el-form-item>
  </el-form>
</template>
