import type { AuthUser, TenantSummary } from '@kavo/api-client'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const activeSlug = ref<string | null>(localStorage.getItem('kavo:tenant'))
  const ready = ref(false)

  const currentTenant = computed<TenantSummary | null>(() => {
    const tenants = user.value?.tenants ?? []

    return tenants.find((t) => t.slug === activeSlug.value) ?? tenants[0] ?? null
  })

  const isAuthenticated = computed(() => user.value !== null)

  async function refresh() {
    try {
      const { user: me } = await api.get<{ user: AuthUser }>('/api/me')
      user.value = me
    } catch {
      user.value = null
    } finally {
      ready.value = true
    }
  }

  async function login(email: string, password: string) {
    const { user: me } = await api.post<{ user: AuthUser }>('/api/login', { email, password })
    user.value = me
  }

  async function register(payload: { name: string; email: string; password: string; workspace: string; product: string }) {
    const { user: me } = await api.post<{ user: AuthUser }>('/api/register', payload)
    user.value = me
  }

  async function logout() {
    await api.post('/api/logout').catch(() => undefined)
    clear()
  }

  function selectTenant(slug: string) {
    activeSlug.value = slug
    localStorage.setItem('kavo:tenant', slug)
  }

  function clear() {
    user.value = null
    // The tenant choice is a convenience, not a credential — but leaving it
    // behind after sign-out means the next person to use the browser sees
    // another workspace's name in the picker.
    activeSlug.value = null
    localStorage.removeItem('kavo:tenant')
  }

  return { user, ready, currentTenant, isAuthenticated, refresh, login, register, logout, selectTenant, clear }
})
