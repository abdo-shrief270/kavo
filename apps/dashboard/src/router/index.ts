import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { guest: true } },
    { path: '/register', name: 'register', component: () => import('../views/RegisterView.vue'), meta: { guest: true } },
    { path: '/', name: 'overview', component: () => import('../views/OverviewView.vue') },
    { path: '/media', name: 'media', component: () => import('../views/MediaView.vue') },
    { path: '/domains', name: 'domains', component: () => import('../views/DomainsView.vue') },
    { path: '/webhooks', name: 'webhooks', component: () => import('../views/WebhooksView.vue') },
  ],
})

/**
 * A client-side guard is for routing, not for security — every endpoint it
 * protects is independently authorised server side. Its job is to avoid
 * rendering a page that is about to 401.
 */
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.ready) await auth.refresh()

  if (!to.meta.guest && !auth.isAuthenticated) return { name: 'login' }
  if (to.meta.guest && auth.isAuthenticated) return { name: 'overview' }

  return true
})
