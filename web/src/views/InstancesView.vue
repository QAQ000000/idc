<script setup lang="ts">
import { onMounted, ref } from "vue";
import { api, message, type Instance } from "../api/request";
const rows = ref<Instance[]>([]),
  busy = ref(false),
  error = ref(""),
  page = ref(1);
const labels: Record<string, string> = {
  awaiting_payment: "待付款",
  provisioning: "开通中",
  investigating: "正在核验上游结果",
  needs_review: "需要人工核验",
  delivered: "已交付",
  cancelled: "已取消",
  compensated: "已取消并补偿至余额",
};
async function load() {
  busy.value = true;
  error.value = "";
  try {
    rows.value = await api<Instance[]>("/instances?page=" + page.value);
  } catch (e) {
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
function move(delta: number) {
  page.value += delta;
  void load();
}
onMounted(load);
</script>
<template>
  <section>
    <h1>我的实例</h1>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-button :loading="busy" @click="load">刷新状态</el-button>
    <el-table
      v-loading="busy"
      :data="rows"
      empty-text="暂无实例，选购产品后将在这里显示"
      style="width: 100%"
    >
      <el-table-column label="实例" min-width="180"
        >
<template #default="{ row }"
          >
<RouterLink :to="'/instances/' + row.id">{{ row.name }}</RouterLink>
          <p class="muted">{{ row.product_name }}</p>
</template
        >
</el-table-column
      >
      <el-table-column label="交付状态" min-width="180"
        >
<template #default="{ row }">
{{
          labels[row.delivery_status] || row.delivery_status
        }}
</template>
</el-table-column
      >
      <el-table-column label="到期时间（UTC）" min-width="180"
        >
<template #default="{ row }">
{{
          row.due_date || "交付后起租"
        }}
</template>
</el-table-column
      >
    </el-table>
    <div class="actions">
      <el-button :disabled="busy || page === 1" @click="move(-1)"
        >
上一页
</el-button
      ><span>第 {{ page }} 页</span
      ><el-button :disabled="busy || rows.length < 50" @click="move(1)"
        >
下一页
</el-button
      >
    </div>
  </section>
</template>
