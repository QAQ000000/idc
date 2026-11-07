<script setup lang="ts">
import { onMounted, ref } from "vue";
import { api, message, type Product } from "../api/request";
const products = ref<Product[]>([]),
  loading = ref(false),
  error = ref("");
async function load() {
  loading.value = true;
  error.value = "";
  try {
    products.value = await api<Product[]>("/products");
  } catch (e) {
    error.value = message(e);
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>
<template>
  <section>
    <h1>云产品选购</h1>
    <p class="muted">选择适合你的云产品，价格以订单确认为准。</p>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
    /><el-button v-if="error" @click="load">重新加载</el-button>
    <div v-loading="loading" class="product-grid">
      <el-card v-for="product in products" :key="product.id"
        >
<h2>{{ product.name }}</h2>
        <p v-for="(price, cycle) in product.prices" :key="cycle">
          {{
            cycle === "monthly" ? "月付" : cycle === "annually" ? "年付" : cycle
          }}
          <strong>¥{{ price }}</strong>
        </p>
        <p>
          {{
            Number(product.stock) === -1
              ? "可购买"
              : Number(product.stock) === 0
                ? "暂时售罄"
                : "剩余 " + product.stock + " 件"
          }}
        </p>
        <RouterLink :to="'/checkout/' + product.id"
          ><el-button type="primary" :disabled="Number(product.stock) === 0"
            >选购</el-button
          ></RouterLink
        >
</el-card
      >
    </div>
    <el-empty
      v-if="!loading && !error && products.length === 0"
      description="暂时没有可购买的产品"
    />
  </section>
</template>
