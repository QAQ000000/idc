import type { CategoryItem } from "../api/adminCategories";

/** Only real second-level choices; an old hidden binding stays visible for maintenance. */
export function categoryOptionsFor(tree: CategoryItem[], retained: number | null = null) {
  return tree.filter(group => group.parent_id === null).map(group => ({
    value: group.id,
    label: group.name + (group.status === 1 ? "" : "（隐藏）"),
    children: (group.children || []).filter(child => child.parent_id === group.id
      && ((group.status === 1 && child.status === 1) || child.id === retained)).map(child => ({
      value: child.id,
      label: child.name + (child.status === 1 ? "" : "（隐藏）"),
      disabled: group.status !== 1 || child.status !== 1,
    })),
  })).filter(group => group.children.length > 0);
}
