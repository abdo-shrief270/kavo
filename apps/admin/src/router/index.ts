import { createRouter, createWebHistory } from 'vue-router'
import { useAdminStore } from '../stores/admin'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { guest: true } },
    { path: '/', name: 'metrics', component: () => import('../views/MetricsView.vue') },
    { path: '/tenants', name: 'tenants', component: () => import('../views/TenantsView.vue') },
    { path: '/tenants/:id', name: 'tenant', component: () => import('../views/TenantDetailView.vue'), props: true },
    { path: '/plans', name: 'plans', component: () => import('../views/PlansView.vue') },
    { path: '/audit', name: 'audit', component: () => import('../views/AuditView.vue') },
  ],
})

router.beforeEach(async (to) => {
  const admin = useAdminStore()

  if (!admin.ready) await admin.refresh()

  // Guarding on isStaff rather than merely being signed in. This is a
  // convenience, not the control — routes/admin.php enforces it server side.
  if (!to.meta.guest && !admin.isStaff) return { name: 'login' }
  if (to.meta.guest && admin.isStaff) return { name: 'metrics' }

  return true
})
