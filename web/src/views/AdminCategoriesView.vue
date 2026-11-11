<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import {
  fetchAdminCategories,
  createCategory,
  updateCategory,
  deleteCategory,
  type CategoryItem,
  type CategoryPayload,
} from "../api/adminCategories";
import { ApiError, message } from "../api/request";
import { useAdmin } from "../stores/admin";

const account = useAdmin();
const editable = computed(() => account.admin?.role === "super_admin");

const tree = ref<CategoryItem[]>([]);
const items = ref<CategoryItem[]>([]);
const busy = ref(false);
const error = ref("");

const types: Record<string, string> = {
  cloud: "云服务器",
  dedicated: "独立服务器",
  hosting: "虚拟主机",
  cdn: "CDN",
  software: "软件服务",
};

const dialogVisible = ref(false);
const dialogMode = ref<"create_group" | "create_sub" | "edit">("create_group");
const saving = ref(false);
const formError = ref("");
const editingId = ref<number | null>(null);

const form = reactive<{
  parent_id: number | null;
  name: string;
  slug: string;
  type: string;
  order_num: number;
  status: number;
  headline: string;
  description: string;
  seo_title: string;
  seo_keywords: string;
  seo_description: string;
  expected_version: string;
}>({
  parent_id: null,
  name: "",
  slug: "",
  type: "cloud",
  order_num: 0,
  status: 1,
  headline: "",
  description: "",
  seo_title: "",
  seo_keywords: "",
  seo_description: "",
  expected_version: "",
});

const parentGroupName = computed(() => {
  if (form.parent_id === null) return "";
  const found = items.value.find((c) => c.id === form.parent_id);
  return found ? found.name : `分组 #${form.parent_id}`;
});

async function load() {
  busy.value = true;
  error.value = "";
  try {
    const res = await fetchAdminCategories();
    tree.value = res.tree;
    items.value = res.items;
  } catch (e) {
    error.value = message(e);
  } finally {
    busy.value = false;
  }
}

function openCreateGroup() {
  dialogMode.value = "create_group";
  editingId.value = null;
  Object.assign(form, {
    parent_id: null,
    name: "",
    slug: "",
    type: "cloud",
    order_num: 0,
    status: 1,
    headline: "",
    description: "",
    seo_title: "",
    seo_keywords: "",
    seo_description: "",
    expected_version: "",
  });
  formError.value = "";
  dialogVisible.value = true;
}

function openCreateSub(group: CategoryItem) {
  dialogMode.value = "create_sub";
  editingId.value = null;
  Object.assign(form, {
    parent_id: group.id,
    name: "",
    slug: "",
    type: group.type || "cloud",
    order_num: 0,
    status: 1,
    headline: "",
    description: "",
    seo_title: "",
    seo_keywords: "",
    seo_description: "",
    expected_version: "",
  });
  formError.value = "";
  dialogVisible.value = true;
}

function openEdit(cat: CategoryItem) {
  dialogMode.value = "edit";
  editingId.value = cat.id;
  Object.assign(form, {
    parent_id: cat.parent_id,
    name: cat.name,
    slug: cat.slug,
    type: cat.type || "cloud",
    order_num: cat.order_num,
    status: cat.status,
    headline: cat.headline || "",
    description: cat.description || "",
    seo_title: cat.seo_title || "",
    seo_keywords: cat.seo_keywords || "",
    seo_description: cat.seo_description || "",
    expected_version: cat.version,
  });
  formError.value = "";
  dialogVisible.value = true;
}

async function save() {
  if (saving.value) return;
  formError.value = "";

  if (!form.name.trim()) {
    formError.value = "请输入分类名称";
    return;
  }
  if (!form.slug.trim()) {
    formError.value = "请输入分类别名 (Slug)";
    return;
  }
  if (!/^[a-z0-9_-]+$/i.test(form.slug.trim())) {
    formError.value = "别名仅支持英文、数字、中划线和下划线";
    return;
  }

  saving.value = true;
  try {
    const payload: CategoryPayload = {
      parent_id: form.parent_id,
      name: form.name.trim(),
      slug: form.slug.trim(),
      type: form.type,
      order_num: Number(form.order_num) || 0,
      status: Number(form.status),
      headline: form.headline.trim(),
      description: form.description.trim(),
      seo_title: form.seo_title.trim(),
      seo_keywords: form.seo_keywords.trim(),
      seo_description: form.seo_description.trim(),
    };

    if (dialogMode.value === "edit" && editingId.value !== null) {
      payload.expected_version = form.expected_version;
      await updateCategory(editingId.value, payload);
      ElMessage.success("分类已更新");
    } else {
      await createCategory(payload);
      ElMessage.success(dialogMode.value === "create_group" ? "一级分组已创建" : "二级分类已创建");
    }
    dialogVisible.value = false;
    await load();
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) {
      formError.value = message(e) + "；请刷新后重试。";
    } else {
      formError.value = message(e);
    }
  } finally {
    saving.value = false;
  }
}

async function handleDelete(cat: CategoryItem) {
  if (cat.subcategories_count > 0) {
    ElMessage.warning(`该分组下还有 ${cat.subcategories_count} 个子分类，请先移除或迁移子分类`);
    return;
  }
  if (cat.products_count > 0) {
    ElMessage.warning(`该分类下关联了 ${cat.products_count} 款商品，请先迁移商品归属`);
    return;
  }

  try {
    await ElMessageBox.confirm(
      `确定要删除分类“${cat.name}”(#${cat.id}) 吗？此操作不可撤销。`,
      "确认删除分类",
      {
        confirmButtonText: "确定删除",
        cancelButtonText: "取消",
        type: "warning",
      }
    );
  } catch {
    return;
  }

  busy.value = true;
  try {
    await deleteCategory(cat.id, cat.version);
    ElMessage.success("分类已删除");
    await load();
  } catch (e) {
    ElMessage.error(message(e));
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="admin-categories-view">
    <div class="header-actions">
      <div>
        <h1>商城分类管理</h1>
        <p class="muted">两级分类架构（一级分组 → 二级分类）。商品仅可绑定至二级分类叶子节点；隐藏分类仅不出现在前台导航，直链与全部列表仍可售。</p>
      </div>
      <div class="actions">
        <el-button v-if="editable" type="primary" @click="openCreateGroup">
          + 新建一级分组
        </el-button>
        <el-button :loading="busy" @click="load">刷新</el-button>
      </div>
    </div>

    <el-alert v-if="error" :title="error" type="error" :closable="false" role="alert" />

    <div v-loading="busy" class="category-groups">
      <el-empty v-if="!tree.length && !busy" description="暂无分类数据，请先创建一级分组" />

      <el-card v-for="group in tree" :key="group.id" class="group-card" shadow="never">
        <template #header>
          <div class="group-header">
            <div class="group-title">
              <el-tag effect="dark" type="primary">一级分组</el-tag>
              <h3>{{ group.name }}</h3>
              <span class="muted">#{{ group.id }} · slug: {{ group.slug }}</span>
              <el-tag :type="group.status === 1 ? 'success' : 'info'" size="small">
                {{ group.status === 1 ? "启用" : "隐藏" }}
              </el-tag>
              <span class="muted">权重: {{ group.order_num }}</span>
            </div>
            <div class="group-actions">
              <el-button v-if="editable" size="small" type="primary" plain @click="openCreateSub(group)">
                + 添加子分类
              </el-button>
              <el-button v-if="editable" size="small" @click="openEdit(group)">编辑分组</el-button>
              <el-button
                v-if="editable"
                size="small"
                type="danger"
                plain
                :disabled="(group.children?.length ?? 0) > 0"
                @click="handleDelete(group)"
              >
                删除
              </el-button>
            </div>
          </div>
        </template>

        <p v-if="group.headline" class="group-headline">标语：{{ group.headline }}</p>

        <!-- Subcategories table -->
        <el-table
          :data="group.children || []"
          empty-text="该分组下暂无二级分类，请点击上方“+ 添加子分类”"
          row-key="id"
          class="subcategories-table"
        >
          <el-table-column label="ID" prop="id" width="70" />
          <el-table-column label="二级分类名称" min-width="160">
            <template #default="{ row }">
              <strong>{{ row.name }}</strong>
              <div v-if="row.headline" class="muted text-xs">{{ row.headline }}</div>
            </template>
          </el-table-column>
          <el-table-column label="别名 (Slug)" prop="slug" min-width="130" />
          <el-table-column label="业务类型" width="110">
            <template #default="{ row }">
              {{ types[row.type] || row.type }}
            </template>
          </el-table-column>
          <el-table-column label="关联商品数" width="100">
            <template #default="{ row }">
              <el-badge :value="row.products_count" :type="row.products_count > 0 ? 'primary' : 'info'" />
            </template>
          </el-table-column>
          <el-table-column label="排序权重" prop="order_num" width="90" />
          <el-table-column label="前台导航状态" width="110">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                {{ row.status === 1 ? "正常显示" : "隐藏" }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column v-if="editable" label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button
                link
                type="danger"
                size="small"
                :disabled="row.products_count > 0"
                @click="handleDelete(row)"
              >
                删除
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-card>
    </div>

    <!-- Create / Edit Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="
        dialogMode === 'create_group'
          ? '新建一级分组'
          : dialogMode === 'create_sub'
          ? `在【${parentGroupName}】下新建二级分类`
          : `编辑分类 #${editingId}`
      "
      width="min(560px, calc(100vw - 24px))"
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
    >
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" role="alert" class="mb-4" />

      <el-form label-position="top" :disabled="saving" @submit.prevent="save">
        <el-form-item v-if="form.parent_id !== null" label="归属一级分组">
          <el-input :model-value="parentGroupName" disabled />
        </el-form-item>

        <el-row :gutter="16">
          <el-col :xs="24" :sm="14">
            <el-form-item label="分类名称" required>
              <el-input v-model="form.name" maxlength="100" placeholder="如：中国香港精品 / 洛杉矶大带宽" />
            </el-form-item>
          </el-col>
          <el-col :xs="24" :sm="10">
            <el-form-item label="别名 (Slug)" required>
              <el-input v-model="form.slug" maxlength="100" placeholder="如：hk-premium" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="16">
          <el-col :xs="24" :sm="12">
            <el-form-item label="业务类型">
              <el-select v-model="form.type">
                <el-option v-for="(lbl, val) in types" :key="val" :label="lbl" :value="val" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :xs="12" :sm="6">
            <el-form-item label="排序权重">
              <el-input-number v-model="form.order_num" :min="0" :max="99999" controls-position="right" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :xs="12" :sm="6">
            <el-form-item label="状态">
              <el-select v-model="form.status">
                <el-option label="启用" :value="1" />
                <el-option label="隐藏" :value="0" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="卖点标语 (可选)">
          <el-input v-model="form.headline" maxlength="150" placeholder="简短吸引力标语" />
        </el-form-item>

        <el-form-item label="详情介绍与线路特点 (可选)">
          <el-input v-model="form.description" type="textarea" :rows="3" placeholder="分类特点与说明" />
        </el-form-item>

        <el-collapse>
          <el-collapse-item title="SEO 高级配置 (可选)" name="seo">
            <el-form-item label="SEO 标题">
              <el-input v-model="form.seo_title" maxlength="200" />
            </el-form-item>
            <el-form-item label="SEO 关键词">
              <el-input v-model="form.seo_keywords" maxlength="255" />
            </el-form-item>
            <el-form-item label="SEO 描述">
              <el-input v-model="form.seo_description" type="textarea" :rows="2" maxlength="500" />
            </el-form-item>
          </el-collapse-item>
        </el-collapse>

        <div class="dialog-actions">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" native-type="submit" :loading="saving">保存</el-button>
        </div>
      </el-form>
    </el-dialog>
  </div>
</template>

<style scoped>
.admin-categories-view {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 1rem;
}
.header-actions h1 {
  margin: 0 0 0.25rem 0;
}
.actions {
  display: flex;
  gap: 0.5rem;
}
.category-groups {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}
.group-card {
  border-radius: 8px;
}
.group-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem;
}
.group-title {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.group-title h3 {
  margin: 0;
  font-size: 1.15rem;
}
.group-actions {
  display: flex;
  gap: 0.5rem;
}
.group-headline {
  font-size: 0.9rem;
  color: var(--el-text-color-secondary);
  margin: 0 0 1rem 0;
}
.subcategories-table {
  width: 100%;
}
.text-xs {
  font-size: 0.75rem;
}
.dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 1.5rem;
}
.mb-4 {
  margin-bottom: 1rem;
}
</style>
