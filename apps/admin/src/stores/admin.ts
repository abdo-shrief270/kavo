import type { AuthUser } from '@kavo/api-client'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../api'

export const useAdminStore = defineStore('admin', () => {
  const user = ref<AuthUser | null>(null)
  const ready = ref(false)

  // Being signed in is not enough for this console. A merchant with a valid
  // session is still not staff, and the UI should say so rather than render
  // an empty dashboard full of 403s.
  const isStaff = computed(() => user.value?.is_platform_admin === true)

  async function refresh() {
    try {
      user.value = (await api.get<{ user: AuthUser }>('/api/me')).user
    } catch {
      user.value = null
    } finally {
      ready.value = true
    }
  }

  async function login(email: string, password: string) {
    user.value = (await api.post<{ user: AuthUser }>('/api/login', { email, password })).user
  }

  async function logout() {
    await api.post('/api/logout').catch(() => undefined)
    clear()
  }

  function clear() {
    user.value = null
  }

  return { user, ready, isStaff, refresh, login, logout, clear }
})
