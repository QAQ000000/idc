<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  api, message,
  type Product, type Invoice, type ProductConfiguration, type Quote,
} from "../api/request";
import ConfigOptionRenderer from "../components/ConfigOptionRenderer.vue";
const configuration = ref<ProductConfiguration>({
  fields: [], schema_hash: "", defaults: {},
});
const selected = ref<Record<string, string>>({});
const confirmed = ref<Quote | null>(null);
const route = useRoute(),
  router = useRouter();
const product = ref<Product | null>(null),
  cycle = ref("monthly"),
  hostname = ref(""),
  password = ref(""),
  useBalance = ref(false);
const total = ref(""),
  error = ref(""),
  loading = ref(true),
  busy = ref(false);
// 网络错误后保留同一键；修改载荷视为新的购买意图。密码只留在当前组件内存。
let checkoutKey = crypto.randomUUID();
watch([cycle, hostname, password, useBalance, selected], () => {
  checkoutKey = crypto.randomUUID();
});
let quoteVersion = 0;
async function quote() {
  const version = ++quoteVersion;
  total.value = "";
  confirmed.value = null;
  error.value = "";
  try {
    const result = await api<Quote>(
      "/products/" + route.params.id + "/calculate",
      {
        method: "POST",
        body: { billing_cycle: cycle.value, config_options: selected.value },
      },
    );
    if (version === quoteVersion) {
      total.value = result.total;
      confirmed.value = result;
    }
  } catch (e) {
    if (version === quoteVersion) error.value = message(e);
  }
}
onMounted(async () => {
  try {
    product.value =
      (await api<Product[]>("/products")).find(
        (p) => p.id === Number(route.params.id),
      ) || null;
    if (!product.value) throw new Error("产品不存在或已下架");
    configuration.value = await api<ProductConfiguration>("/products/" + route.params.id + "/config");
    const values: Record<string, string> = {};
    for (const field of configuration.value.fields) {
      values[field.id] = configuration.value.defaults[field.id] || field.default;
    }
    selected.value = values;
    cycle.value = Object.keys(product.value.prices)[0] || "monthly";
    await quote();
  } catch (e) {
    error.value = message(e);
  } finally {
    loading.value = false;
  }
});
watch([cycle, selected], () => {
  if (!loading.value) void quote();
}, { flush: "sync" });
async function submit() {
  if (busy.value || !total.value || !confirmed.value) return;
  busy.value = true;
  error.value = "";
  try {
    const result = await api<Invoice>("/orders/checkout", {
      method: "POST",
      body: {
        product_id: Number(route.params.id),
        billing_cycle: cycle.value,
        hostname: hostname.value,
        password: password.value,
        config_options: selected.value,
        use_balance: useBalance.value,
        checkout_key: checkoutKey,
        quote_token: confirmed.value.quote_token,
      },
    });
    password.value = "";
    await router.push("/invoices/" + result.invoice_id);
  } catch (e) {
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <section v-loading="loading" class="form-page">
    <h1>确认订单</h1>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-card v-if="product">
      <h2>{{ product.name }}</h2>
      <el-form label-position="top" @submit.prevent="submit">
        <el-form-item label="付款周期">
          <el-select v-model="cycle" aria-label="付款周期" :disabled="busy">
            <el-option
              v-for="(_, name) in product.prices"
              :key="name"
              :label="name === 'monthly' ? '月付' : '年付'"
              :value="name"
            />
          </el-select>
</el-form-item>
        <ConfigOptionRenderer v-model="selected" :fields="configuration.fields" :disabled="busy" />
        <el-form-item label="实例名称">
          <el-input
            v-model="hostname"
            aria-label="实例名称"
            required
            maxlength="63"
            :disabled="busy"
          />
</el-form-item
        ><el-form-item label="初始密码">
          <el-input
            v-model="password"
            aria-label="初始密码"
            type="password"
            show-password
            autocomplete="new-password"
            required
            minlength="12"
            maxlength="72"
            :disabled="busy"
          />
</el-form-item
        ><el-checkbox v-model="useBalance" :disabled="busy">
          立即使用余额付款
        </el-checkbox>
        <p>
          首期应付：<strong>¥{{ total || "—" }}</strong>
        </p>
        <p>后续每期续费：<strong>¥{{ confirmed?.recurring_price || "—" }}</strong></p>
        <el-button :disabled="busy || loading" @click="quote">重新获取报价</el-button>
        <p class="muted">
          确认报价有效 5 分钟；订单报价保留 30 分钟。付款后等待交付，租期从交付成功起算。
        </p>
        <el-button
          type="primary"
          native-type="submit"
          :loading="busy"
          :disabled="!total"
        >
          提交订单
        </el-button>
      </el-form>
    </el-card>
  </section>
</template>
