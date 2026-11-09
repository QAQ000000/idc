<script setup lang="ts">
import type { ConfigField } from "../api/request";
import { ref } from "vue";
import { availableChoices, configurationValueValid } from "../utils/configOptions";
const props = defineProps<{
  fields: ConfigField[];
  modelValue: Record<string, string>;
  disabled?: boolean;
}>();
const emit = defineEmits<{
  "update:modelValue": [value: Record<string, string>];
}>();
const notice = ref("");
function change(id: string, value: string) {
  const next = { ...props.modelValue, [id]: value }, cleared: string[] = [];
  // The server supplies a parent-first form. Clear descendants in one emission before any quote.
  for (const field of props.fields) {
    if (field.type === "select" && next[field.id] && !configurationValueValid(field, next)) {
      next[field.id] = "";
      cleared.push(field.label);
    }
  }
  notice.value = cleared.length ? `选项变化使 ${cleared.join("、")} 的原选择失效，已清除；请重新选择后核对报价。` : "";
  emit("update:modelValue", next);
}
</script>
<template>
  <el-alert v-if="notice" :title="notice" type="warning" :closable="false" role="status" />
  <el-form-item v-for="field in fields" :key="field.id" :label="field.label">
    <template v-if="field.type === 'integer'">
      <el-input
        :model-value="modelValue[field.id]" :aria-label="field.label" :disabled="disabled"
        inputmode="numeric" :aria-invalid="!configurationValueValid(field, modelValue)"
        @update:model-value="change(field.id, $event)" />
      <p class="muted">整数 {{ field.minimum }}–{{ field.maximum }}；步长 {{ field.step }}（填写步长的倍数，上下限均可选）。数量不代表购买台数。</p>
      <p v-if="!configurationValueValid(field, modelValue)" class="quantity-error" role="alert">请输入范围内且符合步长的整数，补全后重新报价。</p>
    </template>
    <el-select
      v-else
      :model-value="modelValue[field.id]"
      :aria-label="field.label"
      :disabled="disabled"
      :fit-input-width="true"
      popper-class="config-option-popper"
      placeholder="请选择有效配置"
      @update:model-value="change(field.id, $event)"
    >
      <el-option
        v-for="choice in availableChoices(field, modelValue)"
        :key="choice.value"
        :label="choice.label"
        :value="choice.value"
      />
    </el-select>
    <p v-if="field.dependencies?.length && !availableChoices(field, modelValue).length" class="muted" role="status">当前父选项没有可用配置，请先调整上级选项。</p>
  </el-form-item>
</template>
<style>
.quantity-error { color: var(--el-color-danger); }
.config-option-popper { max-width: calc(100vw - 24px); }
.config-option-popper .el-select-dropdown__item {
  height: auto;
  min-height: 34px;
  padding-top: 7px;
  padding-bottom: 7px;
  line-height: 1.5;
  white-space: normal;
  overflow-wrap: anywhere;
}
</style>
