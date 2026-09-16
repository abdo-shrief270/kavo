import type { AppNotification } from '@kavo/api-client'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../api'

export const useNotificationStore = defineStore('notifications', () => {
  const items = ref<AppNotification[]>([])
  const unread = ref(0)
  const loading = ref(false)

  const quotaAlerts = computed(() => items.value.filter((n) => n.data.type === 'quota.threshold'))
  const atLimit = computed(() => quotaAlerts.value.some((n) => !n.read_at && n.data.at_limit))

  /**
   * The source of truth. The websocket only tells us to call this sooner —
   * it never becomes the record of what happened, because a dropped socket
   * would then silently lose an alert the merchant is being blocked by.
   */
  async function load() {
    loading.value = true

    try {
      const payload = await api.get<{ notifications: AppNotification[]; unread_count: number }>('/api/notifications')
      items.value = payload.notifications
      unread.value = payload.unread_count
    } finally {
      loading.value = false
    }
  }

  async function markRead(id: string) {
    const { unread_count } = await api.post<{ unread_count: number }>(`/api/notifications/${id}/read`)
    unread.value = unread_count

    const found = items.value.find((n) => n.id === id)
    if (found) found.read_at = new Date().toISOString()
  }

  async function markAllRead() {
    await api.post('/api/notifications/read-all')
    unread.value = 0
    items.value = items.value.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }))
  }

  return { items, unread, loading, quotaAlerts, atLimit, load, markRead, markAllRead }
})
