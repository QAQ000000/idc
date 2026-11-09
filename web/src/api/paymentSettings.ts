export interface PaymentSettings {
  enabled: boolean; endpoint: string; pid: string; type: string; notify_url: string; return_url: string;
  configured: boolean; key_configured: boolean; identity_locked: boolean; version: string;
}
export const paymentFields = (p: PaymentSettings) => ({ enabled: p.enabled, endpoint: p.endpoint, pid: p.pid, type: p.type, notify_url: p.notify_url, return_url: p.return_url });
