import { createRouter, createWebHistory } from "vue-router";
const router = createRouter({
  history: createWebHistory(),
  routes: [
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
router.beforeEach((to) => {
  if (to.meta.client && !sessionStorage.getItem("jianyun_token"))
    return { path: "/login", query: { redirect: to.fullPath } };
});
export default router;
