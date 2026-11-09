import type { Admin } from './admin';
export const roles = { super_admin: '超级管理员', finance: '财务', support: '客服', operator: '运维' };
export interface AdminAccount extends Admin {
  status: 'active' | 'disabled'; version: string;
  second_factor_configured: boolean; login_supported: boolean;
  last_login_at: string | null; last_login_ip: string | null; created_at: string;
}
export interface AccountDetail {
  account: AdminAccount;
  audit: { id: number; action: string; username: string; created_at: string; context: {
    evidence?: string; before?: { role: keyof typeof roles; status: string }; after?: { role: keyof typeof roles; status: string };
  } }[];
}
export const auditLabels: Record<string,string> = { admin_provision: '本地初始化', admin_account_create: '新增账号', admin_account_update: '调整角色／状态', admin_password_change: '本人改密', admin_password_reset: '重置密码' };
export function passwordError(password: string, confirm: string): string {
  const bytes = new TextEncoder().encode(password).length;
  if (bytes < 12 || bytes > 72 || password.includes('\0')) return '新密码须为 12–72 字节，不能包含空字符';
  return password === confirm ? '' : '两次输入的新密码不一致';
}
