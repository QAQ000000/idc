import { api } from "./request";
export const adminApi = <T>(path: string, options: { method?: string; body?: unknown } = {}) =>
  api<T>("/admin" + path, { ...options, scope: "admin" });
export interface Admin {
  id: number; username: string; email: string;
  role: "super_admin" | "finance" | "support" | "operator";
}
export interface Page<T> { items: T[]; total: number; page: number; per_page: number }
export type Resolution = "fulfill" | "retry" | "investigate" | "cancel_compensate" | "close_unexecuted";
export const actionLabels: Record<Resolution, string> = {
  close_unexecuted: "确认未执行并结束操作",
  fulfill: "确认已履约并回填", retry: "确认未执行后重试",
  investigate: "继续只读调查", cancel_compensate: "永久取消并退到钱包",
};
export interface Task {
  id: number; instance_id: number; invoice_id: number | null; user_id: number;
  reinstall_result: string | null;
  action: string; status: string; execution_token: number;
  upstream_task_id: string | null; remote_instance_id: string | null;
  last_error: string | null; retry_count: number; max_retries: number;
  lease_until: string | null; next_retry_at: string | null; created_at: string; updated_at: string;
  instance_name: string; instance_status: string; invoice_status: string | null;
  total: string | null; product_name: string; provider_id: number; provider_name: string;
}
export interface Audit {
  id: number; admin_id: number; username: string; action: string; created_at: string;
  context: { evidence?: string; previous_execution_token?: number; remote_instance_id?: string; amount?: string; payment_record_id?: number };
}
export interface TaskDetail {
  task: Task; user: { id: number; email: string };
  invoice: { id: number; user_id: number; instance_id: number; type: string; status: string; total: string | null; payment_method: string | null; paid_at: string | null } | null;
  instance: { id: number; status: string; remote_instance_id: string | null; ip_address: string | null; billing_cycle: string; due_date: string | null; suspend_type: string; recurring_price: string | null; override_price: string | null };
  can_view_finance: boolean;
  receipts: { id: number; amount: string; currency: string; status: string; refund_status: string; refund_amount: string; settlement_purpose: string | null }[];
  allowed_actions: Resolution[]; blocked_by_other_operation: boolean; audit: Page<Audit>;
}
