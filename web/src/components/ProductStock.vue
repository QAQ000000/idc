<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from "vue";
import type { StockObservation } from "../api/request";
const props = defineProps<{ quota: number; observation?: StockObservation }>();
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 15000); });
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
const fresh = computed(() => {
  const stamp = Date.parse((props.observation?.checked_at ?? "").replace(" ", "T") + "Z");
  return Number.isFinite(stamp) && now.value - stamp < 300000 && stamp <= now.value + 5000;
});
const label = computed(() => ({ in_stock: "有货", sold_out: "售罄", unlimited: "未限制数量", unknown: "暂未确认" })[fresh.value ? props.observation?.state ?? "unknown" : "unknown"]);
</script>
<template>
  <div class="product-stock muted" aria-label="库存信息">
    <p>本地销售配额：{{ Number(quota) < 0 ? "未限制数量" : Number(quota) === 0 ? "已用完" : `剩余 ${quota} 件` }}</p>
    <p>上游库存：{{ label }}<span v-if="fresh && observation?.quantity != null"> · {{ observation.quantity }} 件</span></p>
    <p v-if="observation?.checked_at">核对时间：{{ observation.checked_at }} UTC</p>
    <p v-else>尚无近期核对记录，进入选配后核价确认。</p>
  </div>
</template>
<style scoped>
.product-stock { font-size: 12px; overflow-wrap: anywhere; }
.product-stock p { margin: 4px 0; }
</style>
