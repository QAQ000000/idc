<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from "vue";
import { configurationComplete, configurationLabel } from "../utils/configOptions";
import { useRoute, useRouter } from "vue-router";
import {
  api,
  ApiError,
  message,
  type Product,
  type Invoice,
  type ProductConfiguration,
  type Quote,
} from "../api/request";
import CheckoutSummary from "../components/CheckoutSummary.vue";
import ConfigOptionRenderer from "../components/ConfigOptionRenderer.vue";
import ProductStock from "../components/ProductStock.vue";
const compact = ref(window.matchMedia("(max-width: 1023px)").matches);
const summaryOpen = ref(false), bottomBar = ref<HTMLElement>();
const barHeight = ref(0), viewportBottom = ref(0);
const compactQuery = window.matchMedia("(max-width: 1023px)");
let barObserver: ResizeObserver | undefined;
let invalidInput: HTMLInputElement | undefined;
function revealInvalidInput(event: Event) {
  if (summaryOpen.value && event.target instanceof HTMLInputElement) {
    invalidInput = event.target;
    summaryOpen.value = false;
  }
}
function focusInvalidInput(event: Event) {
  if (!invalidInput) return;
  event.preventDefault();
  invalidInput.focus();
  invalidInput.scrollIntoView({ block: "center" });
  keepInputVisible();
  invalidInput = undefined;
}
function syncCompact() { compact.value = compactQuery.matches; if (!compact.value) summaryOpen.value = false; }
function keepInputVisible() {
  if (!compact.value) return;
  requestAnimationFrame(() => {
    const input = document.activeElement;
    if (!(input instanceof HTMLInputElement) || !input.closest("#checkout-form")) return;
    const viewport = window.visualViewport;
    const top = (viewport?.offsetTop || 0) + 12;
    const bottom = (viewport?.offsetTop || 0) + (viewport?.height || innerHeight) - barHeight.value - 12;
    const bounds = input.getBoundingClientRect();
    if (bounds.bottom > bottom) window.scrollBy(0, bounds.bottom - bottom);
    else if (bounds.top < top) window.scrollBy(0, bounds.top - top);
  });
}
function syncViewport(event?: Event) {
  const viewport = window.visualViewport;
  viewportBottom.value = viewport ? Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop) : 0;
  if (event?.type === "resize") keepInputVisible();
}
watch(bottomBar, (element) => {
  barObserver?.disconnect();
  if (element) {
    barObserver = new ResizeObserver(() => { barHeight.value = element.getBoundingClientRect().height; });
    barObserver.observe(element);
    barHeight.value = element.getBoundingClientRect().height;
  }
}, { flush: "post" });
const route = useRoute(),
  router = useRouter();
const product = ref<Product | null>(null),
  configuration = ref<ProductConfiguration>({
    fields: [],
    schema_hash: "",
    defaults: {},
  }),
  selected = ref<Record<string, string>>({});
const confirmed = ref<Quote | null>(null),
  cycle = ref("monthly"),
  hostname = ref(""),
  password = ref(""),
  useBalance = ref(false);
const error = ref(""),
  quoteError = ref(""),
  loading = ref(true),
  quoting = ref(false),
  busy = ref(false),
  now = ref(Date.now()),
  quoteNotice = ref("");
const passwordPolicy = computed(
  () => confirmed.value?.password_policy ?? { min_length: 12, max_length: 72 },
);
const expires = computed(() =>
  Date.parse((confirmed.value?.quote_expires_at || "").replace(" ", "T") + "Z"),
);
const quoteExpired = computed(
  () =>
    !!confirmed.value &&
    (!Number.isFinite(expires.value) || now.value >= expires.value),
);
const canSubmit = computed(
  () =>
    !!confirmed.value &&
    !quoteExpired.value &&
    !loading.value &&
    !quoting.value &&
    !busy.value,
);
const cycleLabel = computed(() =>
  cycle.value === "monthly"
    ? "月付"
    : cycle.value === "annually"
      ? "年付"
      : cycle.value,
);
const selectionSummary = computed(() =>
  configuration.value.fields.map((field) => ({
    label: field.label,
    value:
      configurationLabel(field, selected.value[field.id]),
  })),
);
const summaryProps = computed(() => ({
  product: product.value!, confirmed: confirmed.value,
  summary: confirmed.value?.configuration ?? selectionSummary.value,
  cycleLabel: cycleLabel.value, quoting: quoting.value, busy: busy.value,
  quoteError: quoteError.value, quoteExpired: quoteExpired.value,
  quoteNotice: quoteNotice.value, canSubmit: canSubmit.value,
}));
const quoteStatus = computed(() => quoting.value ? "正在重新报价…" : quoteError.value ? "报价失败，请重试" : quoteExpired.value ? "报价已失效，请重新计算" : confirmed.value ? `报价有效 · 剩余 ${Math.max(0, Math.ceil((expires.value - now.value) / 1000))} 秒` : "等待有效报价");
const passwordSets: Record<string, string> = {
  upper: "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
  lower: "abcdefghijklmnopqrstuvwxyz",
  digit: "0123456789",
  special: "~@#$&*(){}[]|",
};
const passwordLabels: Record<string, string> = {
  upper: "大写字母",
  lower: "小写字母",
  digit: "数字",
  special: "特殊符号",
};
function randomIndex(length: number): number {
  const value = new Uint32Array(1),
    limit = Math.floor(0x100000000 / length) * length;
  do {
    crypto.getRandomValues(value);
  } while (value[0]! >= limit);
  return value[0]! % length;
}
function generatePassword() {
  const policy = passwordPolicy.value,
    sets = policy.character_sets?.map((name) => passwordSets[name]!);
  if (!sets?.length || !policy.generation_length) return;
  const characters = sets.join(""),
    result = sets.map((set) => set[randomIndex(set.length)]!);
  while (result.length < policy.generation_length)
    result.push(characters[randomIndex(characters.length)]!);
  for (let i = result.length - 1; i > 0; i--) {
    const j = randomIndex(i + 1);
    [result[i], result[j]] = [result[j]!, result[i]!];
  }
  password.value = result.join("");
}

// Keep the original key after an unknown result, including a quote refresh. Only input changes create a new intent.
let checkoutKey = crypto.randomUUID(),
  quoteVersion = 0,
  disposed = false,
  timer: ReturnType<typeof setInterval> | undefined;
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
let quoteActive = false;
let quoteController: AbortController | undefined;
let pendingQuote: { version: number; manual: boolean; ready: boolean; id: string; cycle: string; options: Record<string, string> } | undefined;
watch(
  [cycle, hostname, password, useBalance, selected],
  () => {
    checkoutKey = crypto.randomUUID();
  },
  { flush: "sync" },
);
function quote(manual = false, delay = 0) {
  if (quoteTimer) clearTimeout(quoteTimer);
  pendingQuote = undefined;
  const version = ++quoteVersion;
  confirmed.value = null;
  quoteError.value = "";
  quoteNotice.value = "";
  quoting.value = true;
  if (!configurationComplete(configuration.value.fields, selected.value)) {
    quoting.value = false;
    quoteError.value = "请填写有效的数量和选配，再获取并确认报价。";
    return;
  }
  pendingQuote = { version, manual, ready: delay === 0, id: String(route.params.id), cycle: cycle.value, options: { ...selected.value } };
  if (delay) quoteTimer = setTimeout(() => { if (pendingQuote?.version === version) { pendingQuote.ready = true; void runQuote(); } }, delay);
  else void runQuote();
}
async function runQuote() {
  if (disposed || quoteActive || !pendingQuote?.ready) return;
  const request = pendingQuote;
  pendingQuote = undefined;
  quoteActive = true;
  quoteController = new AbortController();
  const { version, manual } = request;
  try {
    const result = await api<Quote>(
      "/products/" + request.id + "/calculate",
      {
        method: "POST",
        signal: quoteController.signal,
        body: { billing_cycle: request.cycle, config_options: request.options },
      },
    );
    if (version === quoteVersion && !disposed) {
      confirmed.value = result;
      if (product.value && result.upstream_stock) product.value.upstream_stock = result.upstream_stock;
      now.value = Date.now();
      if (manual)
        quoteNotice.value =
          "报价已更新，请重新核对首期价、续费价和配置后确认提交。";
    }
  } catch (e) {
    if (version === quoteVersion && !disposed) {
      quoteError.value = message(e);
      // Cheap local observation only; never retry the failed upstream quote automatically.
      try {
        const found = (await api<Product[]>("/products")).find(p => p.id === Number(request.id));
        if (version === quoteVersion && !disposed && product.value && found) {
          product.value.stock = found.stock;
          product.value.upstream_stock = found.upstream_stock;
        }
      } catch { /* Keep the quote error and mark display evidence unknown. */
        if (version === quoteVersion && !disposed && product.value) product.value.upstream_stock = { state: "unknown", quantity: null, checked_at: null };
      }
    }
  } finally {
    quoteActive = false;
    if (version === quoteVersion && !disposed) quoting.value = false;
    void runQuote();
  }
}
async function load() {
  loading.value = true;
  error.value = "";
  quoteError.value = "";
  product.value = null;
  confirmed.value = null;
  ++quoteVersion;
  try {
    const [products, form] = await Promise.all([
      api<Product[]>("/products"),
      api<ProductConfiguration>("/products/" + route.params.id + "/config"),
    ]);
    const found = products.find(
      (p) => p.id === Number(route.params.id),
    );
    if (!found) throw new Error("产品不存在或已下架");
    if (disposed) return;
    product.value = found;
    configuration.value = form;
    selected.value = Object.fromEntries(
      form.fields.map((field) => [
        field.id,
        form.defaults[field.id] || field.default,
      ]),
    );
    cycle.value = Object.keys(found.prices)[0] || "monthly";
    loading.value = false;
    quote();
  } catch (e) {
    if (!disposed) {
      product.value = null;
      error.value = message(e);
    }
  } finally {
    if (!disposed) loading.value = false;
  }
}
watch(
  [cycle, selected],
  () => {
    if (!loading.value) quote(false, 400);
  },
  { flush: "sync" },
);
async function submit() {
  now.value = Date.now();
  if (!canSubmit.value || !confirmed.value) return;
  const passwordBytes = new TextEncoder().encode(password.value).length;
  if (
    passwordBytes < passwordPolicy.value.min_length ||
    passwordBytes > passwordPolicy.value.max_length
  ) {
    error.value = `初始密码长度须为 ${passwordPolicy.value.min_length}–${passwordPolicy.value.max_length} 字节`;
    return;
  }
  const sets = passwordPolicy.value.character_sets?.map(
    (name) => passwordSets[name]!,
  );
  if (
    sets &&
    (!sets.every((set) =>
      [...password.value].some((character) => set.includes(character)),
    ) ||
      [...password.value].some(
        (character) => !sets.join("").includes(character),
      ))
  ) {
    error.value = "初始密码不符合上游字符规则，请按上游规则生成";
    return;
  }
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
    if (!disposed) await router.push("/invoices/" + result.invoice_id);
  } catch (e) {
    if (!disposed) {
      error.value =
        message(e) +
        "；如请求结果不明，请保持原配置重试或先到我的账单查找原单，勿重新购买。";
      if (e instanceof ApiError && e.status === 409) {
        confirmed.value = null;
        quoteError.value =
          "当前报价或购买条件需要重新确认，请重新获取报价后核对；刷新报价不会自动提交订单。";
      }
    }
  } finally {
    if (!disposed) busy.value = false;
  }
}
onMounted(() => {
  void load();
  compactQuery.addEventListener("change", syncCompact);
  window.visualViewport?.addEventListener("resize", syncViewport);
  window.visualViewport?.addEventListener("scroll", syncViewport);
  syncViewport();
  timer = setInterval(() => {
    now.value = Date.now();
  }, 1000);
});
onBeforeUnmount(() => {
  compactQuery.removeEventListener("change", syncCompact);
  window.visualViewport?.removeEventListener("resize", syncViewport);
  window.visualViewport?.removeEventListener("scroll", syncViewport);
  barObserver?.disconnect();
  disposed = true;
  ++quoteVersion;
  pendingQuote = undefined;
  quoteController?.abort();
  if (quoteTimer) clearTimeout(quoteTimer);
  if (timer) clearInterval(timer);
  password.value = "";
});
</script>
<template>
  <section class="checkout-page" :class="{ 'checkout-with-bar': compact && product && !loading }" :style="{ '--checkout-bar-height': `${barHeight}px`, '--checkout-viewport-bottom': `${viewportBottom}px` }">
    <RouterLink class="back-link" to="/">← 返回产品目录</RouterLink>
    <div class="page-heading">
      <div>
        <p class="eyebrow">选购产品</p>
        <h1>确认配置与价格</h1>
        <p class="muted">核对本次配置和付款方式，付款后可在原账单跟踪交付。</p>
      </div>
      <RouterLink to="/invoices">查找已有账单 ↗</RouterLink>
    </div>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-card v-if="loading" shadow="never">
      <el-skeleton :rows="8" animated />
    </el-card>
    <el-form v-else-if="product" id="checkout-form" label-position="top" @submit.prevent="submit" @invalid.capture="revealInvalidInput" @focusin="keepInputVisible">
      <div class="checkout-columns">
        <div class="panel-stack">
          <el-card shadow="never">
            <template #header>
              <h2>{{ product.name }}</h2>
            </template>
            <ProductStock :quota="product.stock" :observation="product.upstream_stock" />
            <el-form-item label="付款周期">
              <el-select v-model="cycle" aria-label="付款周期" :disabled="busy">
                <el-option
                  v-for="(_, name) in product.prices"
                  :key="name"
                  :label="
                    name === 'monthly'
                      ? '月付'
                      : name === 'annually'
                        ? '年付'
                        : name
                  "
                  :value="name"
                />
              </el-select>
            </el-form-item>
            <ConfigOptionRenderer
              v-model="selected"
              :fields="configuration.fields"
              :disabled="busy"
            />
            <p v-if="!configuration.fields.length" class="muted">
              固定套餐，按商品默认规格交付。
            </p>
          </el-card>
          <el-card shadow="never">
            <template #header><h2>实例信息</h2></template>
            <el-form-item label="实例名称">
              <el-input
                v-model="hostname"
                aria-label="实例名称"
                required
                maxlength="63"
                :disabled="busy"
                placeholder="为实例设置便于识别的名称"
              />
            </el-form-item>
            <el-form-item label="初始密码">
              <el-input
                v-model="password"
                aria-label="初始密码"
                type="password"
                show-password
                autocomplete="new-password"
                required
                :minlength="passwordPolicy.min_length"
                :maxlength="passwordPolicy.max_length"
                :disabled="busy"
              />
              <p class="muted">
                {{ passwordPolicy.min_length }}–{{
                  passwordPolicy.max_length
                }}
                字节
                <span v-if="passwordPolicy.character_sets">
                  ；须组合且仅使用{{
                    passwordPolicy.character_sets
                      .map((name) => passwordLabels[name])
                      .join("、")
                  }}
                </span>
                。密码仅保留在当前页面内存。
              </p>
              <el-button
                v-if="passwordPolicy.generation_length"
                :disabled="busy"
                @click="generatePassword"
              >
                按上游规则生成密码
              </el-button>
            </el-form-item>
          </el-card>
        </div>
        <CheckoutSummary v-if="!compact" v-bind="summaryProps" v-model:use-balance="useBalance" @requote="quote(true)" />
      </div>
      <div v-if="compact" ref="bottomBar" class="checkout-bottom-bar" aria-label="常驻结算入口">
        <div class="checkout-bar-prices">
          <strong>首期 ¥{{ confirmed?.total || "—" }}</strong>
          <span>续费 ¥{{ confirmed?.recurring_price || "—" }} / {{ cycleLabel }}</span>
        </div>
        <p class="checkout-quote-state" :class="{ 'quote-invalid': quoteExpired || quoteError }" role="status">
          {{ quoteStatus }}
          <el-button v-if="quoteExpired || quoteError" link type="primary" :disabled="busy" :loading="quoting" @click="quote(true)">重新报价</el-button>
        </p>
        <p v-if="error" class="checkout-bar-error" role="alert">{{ error }}</p>
        <div class="checkout-bar-actions">
          <el-button :aria-expanded="summaryOpen" @click="summaryOpen = true">摘要与付款方式</el-button>
          <el-button type="primary" native-type="submit" :disabled="!canSubmit" :loading="busy">{{ useBalance ? "余额付款" : "创建账单" }}</el-button>
        </div>
      </div>
    </el-form>
    <el-button v-else @click="load">重新加载商品</el-button>
    <el-drawer v-model="summaryOpen" title="配置、价格与付款方式" direction="btt" size="85%" class="checkout-summary-drawer" @close-auto-focus="focusInvalidInput">
      <CheckoutSummary v-if="product && compact" v-bind="summaryProps" v-model:use-balance="useBalance" @requote="quote(true)" />
      <p class="muted">核对后可关闭摘要，继续填写实例信息。</p>
    </el-drawer>
  </section>
</template>
