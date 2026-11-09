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
  return fields.every((field) => availableChoices(field, selected).some((choice) => choice.value === selected[field.id]));
}
