import { defineStore } from "pinia";
import { ref } from "vue";
import { api, type User } from "../api/request";
export const useUser = defineStore("user", () => {
  const user = ref<User | null>(null);
  async function load() {
    if (!sessionStorage.getItem("jianyun_token")) {
      user.value = null;
      return;
    }
    user.value = await api<User>("/auth/me");
  }
  function establish(token: string, account: User) {
    sessionStorage.setItem("jianyun_token", token);
    user.value = account;
  }
  function logout() {
    sessionStorage.removeItem("jianyun_token");
    user.value = null;
  }
  return { user, load, establish, logout };
});
