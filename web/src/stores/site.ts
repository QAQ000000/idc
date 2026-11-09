import { defineStore } from "pinia";
import { ref } from "vue";
import { normalizeSite, siteDefaults } from "../api/siteSettings";

// Display-only. Never use customer/admin API helpers: public reads send no login credentials.
export const useSite = defineStore("site", () => {
  const config = ref({ ...siteDefaults });
  let generation = 0, pending: AbortController | undefined;
  function apply(value: unknown) {
    generation++; pending?.abort(); pending = undefined;
    config.value = normalizeSite(value);
  }
  async function load() {
    const current = ++generation;
    pending?.abort(); const controller = new AbortController(); pending = controller;
    const timeout = window.setTimeout(() => controller.abort(), 4000);
    try {
      const response = await fetch("/api/v1/site", { credentials: "omit", cache: "no-store", signal: controller.signal });
      const body = await response.json();
      if (!response.ok || body?.code !== 0 || !body.data || typeof body.data !== "object" || Array.isArray(body.data)) throw new Error("Site config unavailable");
      if (current === generation) config.value = normalizeSite(body.data);
    } catch { if (current === generation) config.value = { ...siteDefaults }; }
    finally { window.clearTimeout(timeout); if (current === generation) pending = undefined; }
  }
  return { config, apply, load };
});
