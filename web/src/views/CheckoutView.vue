<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from "vue";
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
import ConfigOptionRenderer from "../components/ConfigOptionRenderer.vue";
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
      field.choices.find((choice) => choice.value === selected.value[field.id])
        ?.label || "待选择",
  })),
);
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
watch(
  [cycle, hostname, password, useBalance, selected],
  () => {
    checkoutKey = crypto.randomUUID();
  },
  { flush: "sync" },
);
async function quote(manual = false) {
  const version = ++quoteVersion;
  confirmed.value = null;
  quoteError.value = "";
  quoteNotice.value = "";
  quoting.value = true;
  try {
    const result = await api<Quote>(
      "/products/" + route.params.id + "/calculate",
      {
        method: "POST",
        body: { billing_cycle: cycle.value, config_options: selected.value },
      },
    );
    if (version === quoteVersion && !disposed) {
      confirmed.value = result;
      now.value = Date.now();
      if (manual)
        quoteNotice.value =
          "报价已更新，请重新核对首期价、续费价和配置后确认提交。";
    }
  } catch (e) {
    if (version === quoteVersion && !disposed) quoteError.value = message(e);
  } finally {
    if (version === quoteVersion && !disposed) quoting.value = false;
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
    const found = (await api<Product[]>("/products")).find(
      (p) => p.id === Number(route.params.id),
    );
    if (!found) throw new Error("产品不存在或已下架");
    const form = await api<ProductConfiguration>(
      "/products/" + route.params.id + "/config",
    );
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
    await quote();
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
    if (!loading.value) void quote();
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
  timer = setInterval(() => {
    now.value = Date.now();
  }, 1000);
});
onBeforeUnmount(() => {
  disposed = true;
  ++quoteVersion;
  if (timer) clearInterval(timer);
  password.value = "";
});
</script>
<template>
  <section class="checkout-page">
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
    <el-form v-else-if="product" label-position="top" @submit.prevent="submit">
      <div class="checkout-columns">
        <div class="panel-stack">
          <el-card shadow="never">
            <template #header>
              <h2>{{ product.name }}</h2>
            </template>
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
        <el-card class="checkout-summary" shadow="never">
          <template #header><h2>本次订单摘要</h2></template>
          <p class="field-label">商品</p>
          <h3>{{ product.name }}</h3>
          <p class="muted">{{ cycleLabel }} · CNY</p>
          <el-descriptions v-if="selectionSummary.length" :column="1" border>
            <el-descriptions-item
              v-for="item in selectionSummary"
              :key="item.label"
              :label="item.label"
            >
              {{ item.value }}
            </el-descriptions-item>
          </el-descriptions>
          <p v-else class="muted">固定套餐，无额外选配。</p>
          <div v-loading="quoting" class="quote-prices">
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
          <el-button :disabled="busy" :loading="quoting" @click="quote(true)">
            重新获取报价
          </el-button>
          <el-divider />
          <el-checkbox v-model="useBalance" :disabled="busy">
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
            native-type="submit"
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
      </div>
    </el-form>
    <el-button v-else @click="load">重新加载商品</el-button>
  </section>
</template>
