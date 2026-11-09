export interface CustomerAccount {
  id: number; email: string; status: 'active' | 'suspended'; version: string;
}
export interface CustomerAccountAudit {
  id: number; admin_id: number; username: string; action: string; created_at: string;
  context: { request_key: string; evidence: string; before_status?: string; after_status?: string; sessions_revoked: boolean };
}
export interface CustomerAccountDetail {
  account: CustomerAccount; audit: CustomerAccountAudit[]; operation: CustomerAccountAudit | null;
}
