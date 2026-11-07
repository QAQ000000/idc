<script setup lang="ts">
import type { ConfigField } from "../api/request";
const props = defineProps<{
  fields: ConfigField[];
  modelValue: Record<string, string>;
  disabled?: boolean;
}>();
const emit = defineEmits<{
  "update:modelValue": [value: Record<string, string>];
}>();
function change(id: string, value: string) {
  emit("update:modelValue", { ...props.modelValue, [id]: value });
}
</script>
<template>
  <el-form-item v-for="field in fields" :key="field.id" :label="field.label">
    <el-select
      :model-value="modelValue[field.id]"
      :aria-label="field.label"
      :disabled="disabled"
      @update:model-value="change(field.id, $event)"
    >
      <el-option
        v-for="choice in field.choices"
        :key="choice.value"
        :label="choice.label"
        :value="choice.value"
      />
    </el-select>
  </el-form-item>
</template>
