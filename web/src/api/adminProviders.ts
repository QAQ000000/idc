import type { Page } from "./admin";
export interface ConnectionField { key: string; label: string; type: "url" | "secret"; required: boolean; max_length: number }
export interface ConnectionDriver { id: string; fields: ConnectionField[]; snapshot_supported: boolean }
export interface DriverCatalog { items: ConnectionDriver[]; version: string }
export interface ProviderConnection {
  id: number; name: string; driver: string; status: number; created_at: string; updated_at: string;
  product_count: number; instance_count: number; driver_installed: boolean; configuration_complete: boolean; snapshot_supported: boolean; catalog_supported: boolean;
  configuration_fields: { key: string; label: string; configured: boolean }[];
}
export interface ProviderDetail extends ProviderConnection {
  version: string;
  audit: Page<{ id: number; admin_id: number | null; username: string | null; action: string; context: Record<string, string | number>; created_at: string }>;
}
export interface ConnectionVerification { verified: boolean; remote_pid: number; billing_cycle: string; field_count: number; currency: string; first_price: string; recurring_price: string; verified_at: string }
export const providerActions: Record<string, string> = { provider_create: "新增连接", provider_update: "修改名称", provider_verify: "只读验证" };

export interface UpstreamCatalogItem {
  remote_pid: number; name: string; category: string; type: string;
  stock_state: "in_stock" | "sold_out" | "unlimited" | "unknown"; stock_qty: number | null;
  hidden: boolean; description: string | null; observed_at: number;
  imported: boolean; local_product_id: number | null; local_status: string | null;
}
export interface UpstreamCatalog {
  provider_id: number; provider_name: string; cached_at: string; cache_hit: boolean;
  categories: { id: string; name: string; item_count: number }[]; items: UpstreamCatalogItem[];
}
