import { createApp } from "vue";
import { createPinia } from "pinia";
import ElementPlus from "element-plus";
import zhCn from "element-plus/es/locale/lang/zh-cn";
import "element-plus/dist/index.css";
import App from "./App.vue";
import router from "./router";
import "./style.css";
import { useUser } from "./stores/user";
const pinia = createPinia();
createApp(App).use(pinia).use(router).use(ElementPlus, { locale: zhCn }).mount("#app");
window.addEventListener("jianyun:unauthorized", () => {
  useUser(pinia).logout();
  if (router.currentRoute.value.meta.client) {
    void router.replace({ path: "/login", query: { redirect: router.currentRoute.value.fullPath } });
  }
});
