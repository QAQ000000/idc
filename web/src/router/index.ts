import { createRouter, createWebHistory } from "vue-router";
import { useAdmin } from "../stores/admin";
const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: "/admin", redirect: "/admin/tasks" },
    { path: "/admin/login", component: () => import("../views/AdminLoginView.vue") },
    { path: "/admin/tasks", component: () => import("../views/AdminTasksView.vue"), meta: { admin: true } },
    { path: "/admin/tasks/:id", component: () => import("../views/AdminTaskView.vue"), meta: { admin: true } },
    { path: "/", component: () => import("../views/CatalogView.vue") },
    {
      path: "/login",
      component: () => import("../views/AuthView.vue"),
      props: { scene: "login" },
    },
    {
      path: "/register",
      component: () => import("../views/AuthView.vue"),
      props: { scene: "register" },
    },
    {
      path: "/checkout/:id",
      component: () => import("../views/CheckoutView.vue"),
      meta: { client: true },
    },
    {
      path: "/invoices/:id",
      component: () => import("../views/InvoiceView.vue"),
      meta: { client: true },
    },
    {
      path: "/instances",
      component: () => import("../views/InstancesView.vue"),
      meta: { client: true },
    },
    {
      path: "/instances/:id",
      component: () => import("../views/InstanceView.vue"),
      meta: { client: true },
    },
    {
      path: "/:pathMatch(.*)*",
      component: () => import("../views/NotFoundView.vue"),
    },
  ],
});
router.beforeEach(async (to) => {
  if (to.meta.admin) {
    const account = useAdmin();
    try { await account.load(); } catch { account.logout(); }
    if (!account.admin) return { path: "/admin/login", query: { redirect: to.fullPath } };
  }
  if (to.meta.client && !sessionStorage.getItem("jianyun_token"))
    return { path: "/login", query: { redirect: to.fullPath } };
});
export default router;
