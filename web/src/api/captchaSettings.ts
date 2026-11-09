export type CaptchaScene = "login" | "register" | "password_reset";
export interface CaptchaPolicy { enabled: boolean; driver: "builtin_image"; mode: "always"; configured: boolean; version: string }
export interface CaptchaSettings { scenes: Record<CaptchaScene, CaptchaPolicy> }
export const captchaFields = (value: CaptchaPolicy) => ({ enabled: value.enabled, driver: value.driver, mode: value.mode });
export const captchaScenes: { id: CaptchaScene; label: string; impact: string; limit: string }[] = [
  { id: "login", label: "登录", impact: "同时影响客户登录和管理员登录。", limit: "客户与管理员各自按 IP 限制 10 次／分钟。" },
  { id: "register", label: "注册", impact: "影响客户新账户注册。", limit: "每个 IP 最多 5 次／分钟。" },
  { id: "password_reset", label: "找回密码", impact: "影响客户申请密码重置邮件。", limit: "每个 IP 10 次／小时；同邮箱另有冷却与频次限制。" },
];
