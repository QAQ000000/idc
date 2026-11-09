import type { ConfigField } from "../api/request";

export function availableChoices(field: ConfigField, selected: Record<string, string>) {
  let allowed: string[] | null = null;
  const excluded: string[] = [];
  for (const rule of field.dependencies ?? []) {
    const parent = selected[rule.field];
    if (!parent) return [];
    if (rule.values.includes(parent) !== (rule.operator === "in")) continue;
    if (rule.effect === "allow") allowed = [...(allowed ?? []), ...rule.choices];
    else excluded.push(...rule.choices);
  }
  return field.choices.filter((choice) =>
    (allowed === null || allowed.includes(choice.value)) && !excluded.includes(choice.value),
  );
}

export function configurationComplete(fields: ConfigField[], selected: Record<string, string>) {
  return fields.every((field) => configurationValueValid(field, selected));
}

export function configurationValueValid(field: ConfigField, selected: Record<string, string>): boolean {
  const value = selected[field.id] ?? "";
  if (field.type !== "integer") return availableChoices(field, selected).some((choice) => choice.value === value);
  if (!/^[0-9]{1,10}$/.test(value)) return false;
  const n = Number(value), { minimum, maximum, step, step_base: base } = field;
  return minimum !== undefined && maximum !== undefined && step !== undefined && base !== undefined && step > 0 &&
    n >= minimum && n <= maximum &&
    ((n - base) % step === 0 || !!(field.allow_endpoints && (n === minimum || n === maximum))) &&
    !!field.ranges?.some((range) => n >= range.minimum && n <= range.maximum);
}

export function configurationLabel(field: ConfigField, value: string | undefined): string {
  return field.type === "integer" ? (value || "待填写") :
    (field.choices.find((choice) => choice.value === value)?.label || "待选择");
}
