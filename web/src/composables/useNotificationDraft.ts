import { adminApi } from "../api/admin";
import type { NotificationSettings } from "../api/notificationSettings";
import { useSettingsDraft } from "./useSettingsDraft";

export function useNotificationDraft<T extends { version: string }>(section: "mail" | "tickets", fields: (value: T) => Record<string, unknown>) {
  return useSettingsDraft<T, NotificationSettings["recipients"]>(fields, {
    read: async () => { const data = await adminApi<NotificationSettings>("/notification-settings"); return { value: data[section] as unknown as T, options: data.recipients }; },
    write: body => adminApi<{ saved: boolean }>(`/notification-settings/${section}`, { method: "PATCH", body }),
  });
}
