export interface MailSettings {
  enabled: boolean; task_alerts: boolean; due_reminders: boolean;
  site_url: string; subject_prefix: string; admin_ids: number[]; version: string;
  password_configured: boolean;
  config: { host?: string; port?: number; security?: string; username?: string; from?: string; from_name?: string };
}
export interface TicketSubscription {
  enabled: boolean; admin_ids: number[]; activated_at: string | null; version: string;
}
export interface NotificationSettings {
  mail: MailSettings; tickets: TicketSubscription;
  recipients: { id: number; username: string; email: string }[];
}
