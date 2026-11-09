import { createRouter, createWebHistory } from "vue-router";
import { useAdmin } from "../stores/admin";
import { ApiError } from "../api/request";
const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: "/admin/payments", component: () => import("../views/AdminPaymentSettingsView.vue"), meta: { admin: true } },
    { path: "/admin/accounts", component: () => import("../views/AdminAccountsView.vue"), meta: { admin: true } },
    { path: "/admin/accounts/:id", component: () => import("../views/AdminAccountView.vue"), meta: { admin: true } },
    { path: "/admin/account/security", component: () => import("../views/AdminSecurityView.vue"), meta: { admin: true } },
    { path: "/admin/notifications", component: () => import("../views/AdminNotificationSettingsView.vue"), meta: { admin: true } },
    { path: "/admin", redirect: "/admin/tasks" },
    { path: "/admin/login", component: () => import("../views/AdminLoginView.vue") },
    { path: "/admin/customers", component: () => import("../views/AdminDirectoryView.vue"), props: { kind: "customers" }, meta: { admin: true } },
    { path: "/admin/customers/:id", component: () => import("../views/AdminCustomerView.vue"), meta: { admin: true } },
    { path: "/admin/instances", component: () => import("../views/AdminDirectoryView.vue"), props: { kind: "instances" }, meta: { admin: true } },
    { path: "/admin/instances/:id", component: () => import("../views/AdminInstanceView.vue"), meta: { admin: true } },
    { path: "/admin/finance", component: () => import("../views/AdminFinanceView.vue"), meta: { admin: true } },
    { path: "/admin/finance/invoices/:id", component: () => import("../views/AdminFinanceInvoiceView.vue"), meta: { admin: true } },
    { path: "/admin/providers", component: () => import("../views/AdminProvidersView.vue"), meta: { admin: true } },
    { path: "/admin/providers/new", component: () => import("../views/AdminProviderCreateView.vue"), meta: { admin: true } },
    { path: "/admin/providers/:id", component: () => import("../views/AdminProviderView.vue"), meta: { admin: true } },
    { path: "/admin/products", component: () => import("../views/AdminProductsView.vue"), meta: { admin: true } },
    { path: "/admin/products/import", component: () => import("../views/AdminProductImportView.vue"), meta: { admin: true } },
    { path: "/admin/products/:id", component: () => import("../views/AdminProductView.vue"), meta: { admin: true } },
    { path: "/admin/tickets", component: () => import("../views/TicketsView.vue"), props: { admin: true }, meta: { admin: true } },
    { path: "/admin/tickets/:id", component: () => import("../views/TicketView.vue"), props: { admin: true }, meta: { admin: true } },
    { path: "/admin/tasks", component: () => import("../views/AdminTasksView.vue"), meta: { admin: true } },
    { path: "/admin/tasks/:id", component: () => import("../views/AdminTaskView.vue"), meta: { admin: true } },
    { path: "/", component: () => import("../views/CatalogView.vue") },
    { path: "/tickets", component: () => import("../views/TicketsView.vue"), meta: { client: true } },
    { path: "/tickets/new", component: () => import("../views/TicketCreateView.vue"), meta: { client: true } },
    { path: "/tickets/:id", component: () => import("../views/TicketView.vue"), meta: { client: true } },
    { path: "/wallet", component: () => import("../views/WalletView.vue"), meta: { client: true } },
    { path: "/invoices", component: () => import("../views/InvoicesView.vue"), meta: { client: true } },
    { path: "/account/security", component: () => import("../views/PasswordView.vue"), meta: { client: true } },
    { path: "/forgot-password", component: () => import("../views/ForgotPasswordView.vue") },
    { path: "/reset-password", component: () => import("../views/PasswordView.vue"), props: { reset: true } },
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
    { path: "/instances/:id/console", component: () => import("../views/ConsoleView.vue"), meta: { client: true } },
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
    try { await account.load(); } catch (error) {
      if (error instanceof ApiError && [401, 403].includes(error.status)) account.logout();
      else account.admin = null; // A failed read cannot establish a role or invalidate a signed session.
    }
    if (!account.admin) return { path: "/admin/login", query: { redirect: to.fullPath } };
  }
  if (to.meta.client && !sessionStorage.getItem("jianyun_token"))
    return { path: "/login", query: { redirect: to.fullPath } };
});
export default router;
