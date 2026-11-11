import type { ConfigField } from "./request";
import type { Page } from "./admin";
export const salesLabels: Record<string, string> = { active: "上架", hidden: "下架", retired: "停售" };
export const cycleLabels: Record<string, string> = { monthly: "月付", annually: "年付" };
export interface Provider { id: number; name: string; driver: string; status: number; snapshot_supported: boolean }
export interface AdminProduct {
  id: number; category_id: number | null; provider_id: number; remote_pid: number; name: string; type: string;
  status: string; markup_type: string; markup_value: string; stock: number;
  provider_name: string; driver: string; provider_status: number;
  prices: Record<string, string>; recurring_prices: Record<string, string>;
  billing_cycles: string[]; configurable: boolean; snapshot_supported: boolean; updated_at: string;
}
export interface ProductDetail extends AdminProduct {
  version: string; fields: ConfigField[]; defaults: Record<string, string>; configuration_error: string | null;
  audit: Page<{ id: number; admin_id: number; username: string; action: string; created_at: string; context: { evidence?: string; before?: object; after?: object; prices?: object } }>;
}
export interface SnapshotPreview {
  fields: ConfigField[]; defaults: Record<string, string>; prices: Record<string, string>;
  recurring_prices: Record<string, string>; billing_cycles: string[]; preview_token: string; expires_at: string;
  category_id?: number | null; category_path?: string | null;
}
