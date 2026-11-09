import { api } from "./request";
import type { Page } from "./admin";
export const ticketLabels: Record<string, string> = { open: "待回复", in_progress: "处理中", answered: "管理员已回复", customer_replied: "客户已追加", closed: "已关闭" };
export interface TicketRow { id: number; title: string; status: string; instance_id: number | null; user_id?: number; email?: string; created_at: string; updated_at: string; closed_at: string | null }
export interface TicketDetail {
  ticket: TicketRow & { customer?: { id: number; email: string; status: string } };
  instance: { id: number; name: string; status: string; ip_address: string | null } | null;
  version: string; replies: Page<{ id: number; content: string; created_at: string; author_type: "admin" | "customer"; admin_id?: number | null; user_id?: number | null }>;
}
export interface TicketResult { ticket_id: number; reply_id: number; replayed: boolean }
export function ticketApi<T>(admin: boolean, path: string, options: { method?: string; body?: unknown } = {}) {
  return api<T>((admin ? "/admin" : "") + "/tickets" + path, { ...options, ...(admin ? { scope: "admin" as const } : {}) });
}
// Drafts are scoped to the independent login session, never shared between customer/admin accounts.
export async function ticketDraftKey(admin: boolean, kind: string) {
  const token = sessionStorage.getItem(admin ? "jianyun_admin_token" : "jianyun_token") || "";
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(token));
  return `jianyun_ticket:${admin ? "admin" : "client"}:${Array.from(new Uint8Array(digest)).map(v => v.toString(16).padStart(2, "0")).join("")}:${kind}`;
}
export function readTicketDraft<T>(key: string): T | undefined {
  try { const value = sessionStorage.getItem(key); return value ? JSON.parse(value) as T : undefined; } catch { return undefined; }
}
