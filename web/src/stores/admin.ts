import { defineStore } from "pinia";
import { ref } from "vue";
import { adminApi, type Admin } from "../api/admin";
export const useAdmin = defineStore("admin", () => {
  const admin = ref<Admin | null>(null);
  async function load() {
    if (!sessionStorage.getItem("jianyun_admin_token")) { admin.value = null; return; }
    admin.value = await adminApi<Admin>("/auth/me");
  }
  function establish(token: string, value: Admin) {
    sessionStorage.setItem("jianyun_admin_token", token); admin.value = value;
  }
  function logout() { sessionStorage.removeItem("jianyun_admin_token"); admin.value = null; }
  return { admin, load, establish, logout };
});
