import type { ServiceObservation } from "./serviceStatus";
import type { Page } from "./admin";
export interface Customer { id: number; email: string; status: string; created_at: string }
export interface AdminInstance {
  service_status_supported: boolean;
  entitlement_state: string; service_observation: ServiceObservation;
  id: number; user_id: number; user_email: string; product_id: number; product_name: string;
  provider_id: number; provider_name: string; name: string; status: string;
  remote_instance_id: string | null; ip_address: string | null; billing_cycle: string;
  start_date: string | null; due_date: string | null; suspend_type: string; auto_renew: number; created_at: string;
}
export interface CustomerDetail { customer: Customer; instances: Page<AdminInstance> }
export interface InstanceDetail {
  instance: AdminInstance;
  invoices: Page<{ invoice_id: number; type: string; status: string; created_at: string; paid_at: string | null }>;
}
export const customerLabels: Record<string, string> = { active: "正常", suspended: "已停用", closed: "已关闭" };
export const serviceLabels: Record<string, string> = { pending: "待交付", active: "已交付", suspended: "已暂停", terminated: "已终止" };
