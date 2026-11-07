export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}
export async function api<T>(
  path: string,
  options: { method?: string; body?: unknown } = {},
): Promise<T> {
  const token = sessionStorage.getItem("jianyun_token");
  let response: Response;
  try {
    response = await fetch("/api/v1" + path, {
      method: options.method || "GET",
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
      sessionStorage.removeItem("jianyun_token");
      window.dispatchEvent(new Event("jianyun:unauthorized"));
    }
    throw new ApiError(result.msg || "请求失败", response.status);
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
}
export interface Invoice {
  invoice_id: number;
  instance_id: number;
  type: string;
  total: string;
  status: string;
  payment_method: string | null;
  quote_expired_at: string | null;
  recurring_price: string | null;
  config_options: Record<string, string>;
  configuration: { label: string; value: string }[];
}
export interface Captcha {
  enabled: boolean;
  driver?: string;
  type?: string;
  challenge_id?: string;
  img_base64?: string;
}

export interface Instance {
  id: number;
  name: string;
  product_name: string;
  type: string;
  status: string;
  delivery_status: string;
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
  type: "select";
  label: string;
  choices: { value: string; label: string }[];
  default: string;
}
export interface ProductConfiguration {
  fields: ConfigField[];
  schema_hash: string;
  defaults: Record<string, string>;
}
export interface Quote {
  total: string;
  recurring_price: string;
  quote_token: string;
  password_policy: { min_length: number; max_length: number; character_sets?: string[]; generation_length?: number };
}
