import type { ServiceObservation } from "./serviceStatus";
export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public businessResponse = false,
  ) {
    super(message);
  }
}
export async function api<T>(
  path: string,
  options: { method?: string; body?: unknown; scope?: "admin"; signal?: AbortSignal } = {},
): Promise<T> {
  const key = options.scope === "admin" ? "jianyun_admin_token" : "jianyun_token";
  const token = sessionStorage.getItem(key);
  let response: Response;
  try {
    response = await fetch("/api/v1" + path, {
      method: options.method || "GET",
      signal: options.signal,
      headers: {
        "Content-Type": "application/json",
        ...(token ? { Authorization: "Bearer " + token } : {}),
      },
      ...(options.body !== undefined
        ? { body: JSON.stringify(options.body) }
        : {}),
    });
  } catch {
    throw new ApiError("网络连接失败，请重试", 0);
  }
  const result = (await response.json().catch(() => {
    throw new ApiError("服务暂时不可用，请稍后重试", response.status);
  })) as { code: number; msg: string; data: T };
  if (!response.ok || result.code !== 0) {
    if (response.status === 401) {
      sessionStorage.removeItem(key);
      window.dispatchEvent(new Event(options.scope === "admin" ? "jianyun:admin-unauthorized" : "jianyun:unauthorized"));
    }
    throw new ApiError(result.msg || "请求失败", response.status, Number.isInteger(result.code) && result.code > 0 && typeof result.msg === "string");
  }
  return result.data;
}
export function message(error: unknown): string {
  return error instanceof Error ? error.message : "操作失败，请重试";
}
export interface User {
  id: number;
  email: string;
  balance: string;
  role: "client";
}
export interface Product {
  id: number;
  name: string;
  type: string;
  stock: number;
  prices: Record<string, string>;
  configurable: boolean;
  upstream_stock?: StockObservation;
}
export interface StockObservation {
  state: "in_stock" | "sold_out" | "unlimited" | "unknown";
  quantity: number | null;
  checked_at: string | null;
}
export interface Invoice {
  invoice_id: number;
  instance_id: number | null;
  instance_name: string | null;
  fulfillment_status: string | null;
  wallet_credit_total: string;
  late_credit_total: string;
  type: string;
  total: string;
  status: string;
  payment_method: string | null;
  quote_expired_at: string | null;
  recurring_price: string | null;
  config_options: Record<string, string>;
  configuration: { label: string; value: string }[];
  created_at: string;
  paid_at: string | null;
  quote_expired: number | null;
}
export interface Captcha {
  enabled: boolean;
  driver?: string;
  type?: string;
  challenge_id?: string;
  img_base64?: string;
}

export interface Instance {
  service_status_supported: boolean;
  entitlement_state: string;
  service_observation: ServiceObservation;
  id: number;
  name: string;
  product_name: string;
  type: string;
  status: string;
  delivery_status: string;
  reinstalled_image?: string | null;
  remote_instance_id: string | null;
  ip_address: string | null;
  billing_cycle: string;
  recurring_price: string;
  start_date: string | null;
  due_date: string | null;
  invoice_id: number | null;
  suspend_type: "billing" | "compliance" | "abuse" | "manual";
  auto_renew: number;
  renewal_invoice_id: number | null;
  renewal_status: string;
  renewal_eligible: boolean;
  configuration: { label: string; value: string }[];
}

export interface ConfigField {
  id: string;
  type: "select" | "integer";
  label: string;
  choices: { value: string; label: string }[];
  default: string;
  minimum?: number;
  maximum?: number;
  step?: number;
  step_base?: number;
  allow_endpoints?: boolean;
  ranges?: { id: string; minimum: number; maximum: number }[];
  dependencies?: {
    field: string;
    operator: "in" | "not_in";
    values: string[];
    effect: "allow" | "exclude";
    choices: string[];
  }[];
}
export interface ProductConfiguration {
  fields: ConfigField[];
  schema_hash: string;
  defaults: Record<string, string>;
}
export interface Quote {
  upstream_stock?: StockObservation;
  total: string;
  recurring_price: string;
  quote_token: string;
  quote_expires_at: string;
  billing_cycle: string;
  configuration: { label: string; value: string }[];
  password_policy: { min_length: number; max_length: number; character_sets?: string[]; generation_length?: number };
}
