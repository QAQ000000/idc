<script setup lang="ts">
import type { Product, Quote } from "../api/request";
defineProps<{
  product: Product; confirmed: Quote | null;
  summary: { label: string; value: string }[]; cycleLabel: string;
  quoting: boolean; busy: boolean; quoteError: string; quoteExpired: boolean;
  quoteNotice: string; canSubmit: boolean; useBalance: boolean;
}>();
const emit = defineEmits<{ requote: []; "update:useBalance": [value: boolean] }>();
</script>
<template>
  <el-card class="checkout-summary" shadow="never">
    <template #header><h2>本次订单摘要</h2></template>
    <p class="field-label">商品</p>
    <h3>{{ product.name }}</h3>
    <p class="muted">{{ cycleLabel }} · CNY</p>
    <el-descriptions v-if="summary.length" :column="1" border>
      <el-descriptions-item
        v-for="item in summary"
        :key="item.label"
        :label="item.label"
      >
        {{ item.value }}
      </el-descriptions-item>
    </el-descriptions>
    <p v-else class="muted">固定套餐，无额外选配。</p>
    <div class="quote-prices" :aria-busy="quoting">
      <p v-if="quoting" role="status">正在核价…</p>
      <p class="field-label">首期应付</p>
      <p class="renewal-price">¥{{ confirmed?.total || "—" }}</p>
      <div class="price-line">
        <span>后续每期续费</span>
        <strong>
          ¥{{ confirmed?.recurring_price || "—" }} / {{ cycleLabel }}
        </strong>
      </div>
      <p v-if="confirmed" class="muted">
        确认报价截止：{{ confirmed.quote_expires_at }} UTC
      </p>
    </div>
    <el-alert
      v-if="quoteError"
      :title="quoteError"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-alert
      v-if="quoteExpired"
      title="确认报价已失效，请重新获取报价并核对后再提交。"
      type="warning"
      :closable="false"
      role="alert"
    />
    <el-alert
      v-if="quoteNotice"
      :title="quoteNotice"
      type="info"
      :closable="false"
      role="status"
    />
    <el-button :disabled="busy" :loading="quoting" @click="emit('requote')">
      重新获取报价
    </el-button>
    <el-divider />
    <el-checkbox :model-value="useBalance" :disabled="busy" @update:model-value="emit('update:useBalance', Boolean($event))">
      立即使用余额付款
    </el-checkbox>
    <p class="muted">
      {{
        useBalance
          ? "提交时按服务端余额和门禁结算；余额不足不会完成付款。"
          : "先创建待付账单，再选择易支付或余额付款。"
      }}
    </p>
    <p class="muted">
      确认报价有效 5 分钟；新购账单报价保留 30
      分钟。租期从交付成功起算，已付款不代表已经开通。
    </p>
    <el-button
      class="checkout-submit"
      type="primary"
      native-type="submit" form="checkout-form"
      :loading="busy"
      :disabled="!canSubmit"
    >
      {{
        useBalance
          ? "确认配置与价格，余额付款"
          : "确认配置与价格，创建账单"
      }}
    </el-button>
  </el-card>
</template>
