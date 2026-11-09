export const siteDefaults = { site_name: "简云", logo_path: "", company_name: "", copyright_text: "让云资源管理更简单", icp_number: "" };
export type SiteConfig = typeof siteDefaults;
export interface SiteSettings extends SiteConfig { version: string; configured: boolean }
export const siteFields = (value: SiteConfig) => ({ site_name: value.site_name, logo_path: value.logo_path, company_name: value.company_name, copyright_text: value.copyright_text, icp_number: value.icp_number });
export const validLogoPath = (path: string) => path === "" || /^\/(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_-]+\.(?:png|jpe?g|gif|webp|svg|ico)$/.test(path);
export function normalizeSite(value: unknown): SiteConfig {
  const raw = value && typeof value === "object" ? value as Record<string, unknown> : {};
  const safe = { ...siteDefaults };
  const limits: Record<keyof SiteConfig, number> = { site_name: 80, logo_path: 255, company_name: 120, copyright_text: 200, icp_number: 80 };
  for (const key of Object.keys(safe) as (keyof SiteConfig)[]) {
    const field = raw[key];
    if (typeof field === "string" && [...field].length <= limits[key] && ![...field].some(c => c.charCodeAt(0) < 32 || c.charCodeAt(0) === 127) && (key !== "logo_path" || validLogoPath(field))) safe[key] = field.trim() || siteDefaults[key];
  }
  return safe;
}
