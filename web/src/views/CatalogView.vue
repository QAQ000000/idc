<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { api, message, type Product } from "../api/request";
import { fetchPublicCategories, type PublicCategoryGroup } from "../api/categories";
import { useSite } from "../stores/site";
import ProductStock from "../components/ProductStock.vue";

const site = useSite();
const router = useRouter();

const products = ref<Product[]>([]);
const categoryGroups = ref<PublicCategoryGroup[]>([]);
const loading = ref(true);
const error = ref("");

const search = ref("");
const selectedGroupId = ref<string>("all");
const selectedSubcategoryId = ref<string>("all");

const types: Record<string, string> = {
  cloud: "云服务器",
  dedicated: "独立服务器",
  hosting: "虚拟主机",
  cdn: "CDN",
  software: "软件服务",
};

const cycles: Record<string, string> = {
  monthly: "月付",
  annually: "年付",
  quarterly: "季付",
  semiannually: "半年付",
  biennially: "两年付",
  triennially: "三年付",
  onetime: "一次性",
};

function canOrder(product: Product) {
  return Number(product.stock) !== 0 && Object.keys(product.prices).length > 0;
}

const subCategoryMap = computed(() => {
  const map = new Map<number, { id: number; name: string; groupName: string; groupId: number }>();
  for (const group of categoryGroups.value) {
    for (const sub of group.children || []) {
      map.set(sub.id, {
        id: sub.id,
        name: sub.name,
        groupName: group.name,
        groupId: group.id,
      });
    }
  }
  return map;
});

const hasUncategorized = computed(() =>
  products.value.some((p) => p.category_id == null),
);

const activeGroup = computed(() => {
  if (selectedGroupId.value === "all" || selectedGroupId.value === "uncategorized") {
    return null;
  }
  const gid = Number(selectedGroupId.value);
  return categoryGroups.value.find((g) => g.id === gid) || null;
});

const availableSubcategories = computed(() => activeGroup.value?.children || []);

function selectGroup(groupId: string) {
  selectedGroupId.value = groupId;
  selectedSubcategoryId.value = "all";
}

function getCategoryBreadcrumb(product: Product): string {
  if (product.category_id != null) {
    const sub = subCategoryMap.value.get(product.category_id);
    if (sub) {
      return `${sub.groupName} · ${sub.name}`;
    }
  }
  return types[product.type] || product.type;
}

const filtered = computed(() => {
  const q = search.value.trim().toLocaleLowerCase();
  return products.value.filter((p) => {
    if (q && !p.name.toLocaleLowerCase().includes(q)) {
      return false;
    }
    if (selectedGroupId.value === "all") {
      return true;
    }
    if (selectedGroupId.value === "uncategorized") {
      return p.category_id == null;
    }
    if (!activeGroup.value) {
      return false;
    }
    if (selectedSubcategoryId.value === "all") {
      const childIds = new Set(activeGroup.value.children.map((c) => c.id));
      return p.category_id != null && childIds.has(p.category_id);
    }
    return p.category_id === Number(selectedSubcategoryId.value);
  });
});

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const [prods, cats] = await Promise.all([
      api<Product[]>("/products"),
      fetchPublicCategories().catch(() => [] as PublicCategoryGroup[]),
    ]);
    products.value = prods;
    categoryGroups.value = cats;
  } catch (e) {
    error.value = message(e);
  } finally {
    loading.value = false;
  }
}

function clearFilters() {
  search.value = "";
  selectedGroupId.value = "all";
  selectedSubcategoryId.value = "all";
}

onMounted(load);
</script>

<template>
  <section class="catalog-page" aria-labelledby="catalog-title">
    <div class="catalog-hero">
      <div class="hero-copy">
        <p class="eyebrow">{{ site.config.site_name }} · 云产品选购</p>
        <h1 id="catalog-title">
          你的下一份云资源，
          <br>
          <span>从这里开始。</span>
        </h1>
        <p class="hero-description">
          选购产品、管理实例、查看账单。
          <br>
          在一个控制台，轻松处理日常云服务。
        </p>
        <a class="hero-link" href="#products">
          浏览可售产品
          <span aria-hidden="true">↓</span>
        </a>
      </div>
      <div class="cloud-art" aria-hidden="true">
        <div class="art-orbit" />
        <div class="art-server">
          <div class="server-top">
            JIANYUN
            <span>●</span>
          </div>
          <div class="server-row">
            <i />
            <i />
            <b />
          </div>
          <div class="server-row">
            <i />
            <i />
            <b />
          </div>
          <div class="server-row">
            <i />
            <i />
            <b />
          </div>
        </div>
        <span class="art-caption">云端资源 · 统一管理</span>
      </div>
    </div>

    <div id="products" class="section-heading">
      <div>
        <p class="eyebrow">产品目录</p>
        <h2>选择适合你的产品</h2>
      </div>
      <span v-if="!loading && !error" class="muted">
        {{ filtered.length }} 款可见产品
      </span>
    </div>

    <div class="catalog-toolbar">
      <div class="catalog-navigation" role="navigation" aria-label="商品分类导航">
        <!-- 一级分组胶囊 -->
        <div class="level1-group-pills" role="tablist" aria-label="一级分组">
          <button
            type="button"
            class="nav-pill"
            :class="{ active: selectedGroupId === 'all' }"
            role="tab"
            :aria-selected="selectedGroupId === 'all'"
            @click="selectGroup('all')"
          >
            全部商品
          </button>
          <button
            v-for="group in categoryGroups"
            :key="group.id"
            type="button"
            class="nav-pill"
            :class="{ active: selectedGroupId === String(group.id) }"
            role="tab"
            :aria-selected="selectedGroupId === String(group.id)"
            @click="selectGroup(String(group.id))"
          >
            {{ group.name }}
          </button>
          <button
            v-if="hasUncategorized"
            type="button"
            class="nav-pill"
            :class="{ active: selectedGroupId === 'uncategorized' }"
            role="tab"
            :aria-selected="selectedGroupId === 'uncategorized'"
            @click="selectGroup('uncategorized')"
          >
            未分类
          </button>
        </div>

        <!-- 二级分类标签（选中一级分组且有子分类时呈现） -->
        <div
          v-if="availableSubcategories.length"
          class="level2-subcategory-tabs"
          role="tablist"
          aria-label="二级分类"
        >
          <button
            type="button"
            class="nav-tab"
            :class="{ active: selectedSubcategoryId === 'all' }"
            role="tab"
            :aria-selected="selectedSubcategoryId === 'all'"
            @click="selectedSubcategoryId = 'all'"
          >
            全部
          </button>
          <button
            v-for="sub in availableSubcategories"
            :key="sub.id"
            type="button"
            class="nav-tab"
            :class="{ active: selectedSubcategoryId === String(sub.id) }"
            role="tab"
            :aria-selected="selectedSubcategoryId === String(sub.id)"
            @click="selectedSubcategoryId = String(sub.id)"
          >
            {{ sub.name }}
          </button>
        </div>
      </div>

      <el-input
        v-model="search"
        class="catalog-search"
        aria-label="搜索商品名称"
        placeholder="搜索商品名称"
        clearable
        :disabled="loading || Boolean(error)"
      />
    </div>

    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    >
      <el-button @click="load">重新加载</el-button>
    </el-alert>

    <div
      v-if="loading"
      class="product-grid"
      aria-label="商品加载中"
      aria-busy="true"
    >
      <el-card v-for="n in 3" :key="n" shadow="never">
        <el-skeleton :rows="5" animated />
      </el-card>
    </div>

    <div v-else-if="!error && filtered.length" class="product-grid">
      <el-card
        v-for="product in filtered"
        :key="product.id"
        class="product-card"
        shadow="never"
      >
        <div class="product-card-top">
          <span class="product-icon" aria-hidden="true">▦</span>
          <el-tag :type="canOrder(product) ? 'success' : 'info'" effect="plain">
            {{
              Number(product.stock) === 0
                ? "本地配额已用完"
                : canOrder(product)
                  ? "可核价"
                  : "暂不可售"
            }}
          </el-tag>
        </div>
        <p class="eyebrow">{{ getCategoryBreadcrumb(product) }}</p>
        <h3>{{ product.name }}</h3>
        <p class="muted">
          {{
            product.configurable
              ? "支持选配，按需选择产品配置"
              : "固定配置，前往订单页查看详情"
          }}
        </p>
        <div class="product-prices">
          <div
            v-for="(price, cycle) in product.prices"
            :key="cycle"
            class="price-line"
          >
            <span>{{ cycles[cycle] || cycle }}</span>
            <strong>
              <small>¥</small>
              {{ price }}
            </strong>
          </div>
          <p v-if="product.configurable" class="muted price-note">
            默认配置参考价，实际以确认报价为准
          </p>
          <p v-if="!Object.keys(product.prices).length" class="muted">
            暂无可售账期
          </p>
        </div>
        <div class="product-card-footer">
          <ProductStock :quota="product.stock" :observation="product.upstream_stock" />
          <el-button
            type="primary"
            :disabled="!canOrder(product)"
            :aria-label="'选购配置 ' + product.name"
            @click="router.push('/checkout/' + product.id)"
          >
            选购配置
            <span aria-hidden="true">↗</span>
          </el-button>
        </div>
      </el-card>
    </div>

    <el-empty
      v-else-if="!error"
      :description="
        products.length ? '没有符合筛选条件的产品' : '暂时没有可购买的产品'
      "
    >
      <el-button v-if="products.length" @click="clearFilters">
        清除筛选
      </el-button>
      <el-button v-else @click="load">刷新目录</el-button>
    </el-empty>

    <p class="catalog-note muted">
      价格与可售配置以订单确认页为准，已有订单可在「我的账单」中继续处理。
    </p>
  </section>
</template>
