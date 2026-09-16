<script setup lang="ts">
import { ApiError, type QuotaDetail } from '@kavo/api-client'
import { onMounted, ref } from 'vue'
import { api } from '../api'
import { useNotificationStore } from '../stores/notifications'

interface MediaItem {
  id: number
  filename: string
  mime: string
  size_bytes: number
  created_at: string
}

const items = ref<MediaItem[]>([])
const quota = ref<QuotaDetail | null>(null)
const blocked = ref<QuotaDetail | null>(null)
const busy = ref(false)
const notifications = useNotificationStore()

async function load() {
  items.value = (await api.get<{ data: MediaItem[] }>('/api/media')).data
}

async function upload(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  blocked.value = null
  busy.value = true

  const body = new FormData()
  body.append('file', file)

  try {
    // FormData rather than the JSON client: a file upload is multipart, and
    // the browser must set its own boundary.
    const response = await fetch(`${import.meta.env.VITE_API_BASE ?? 'http://localhost:8000'}/api/media`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
      },
      body,
    })

    const payload = await response.json()

    if (!response.ok) {
      throw new ApiError(response.status, payload.message, payload.errors ?? {}, payload.quota)
    }

    quota.value = payload.quota
    await load()
  } catch (error) {
    // 402 is not a failure the merchant caused — it is a plan ceiling, so it
    // gets an upgrade prompt rather than a red error.
    if (error instanceof ApiError && error.isQuotaExceeded) {
      blocked.value = error.quota ?? null
      // The same threshold raised an alert server-side; pull it in so the
      // bell and this page agree.
      await notifications.load()
    } else {
      blocked.value = null
      throw error
    }
  } finally {
    busy.value = false
    input.value = ''
  }
}

async function remove(item: MediaItem) {
  const payload = await api.delete<{ quota: QuotaDetail }>(`/api/media/${item.id}`)
  quota.value = payload.quota
  blocked.value = null
  await load()
}

function size(bytes: number): string {
  return bytes < 1_048_576
    ? `${Math.max(1, Math.round(bytes / 1024))} KB`
    : `${(bytes / 1_048_576).toFixed(1)} MB`
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Media</h1>

    <p v-if="quota" class="quota">
      {{ quota.used }} MB used<template v-if="quota.limit !== null"> of {{ quota.limit }} MB</template>
      <template v-else> · unlimited</template>
    </p>

    <div v-if="blocked" class="upgrade">
      <strong>You have reached your storage limit.</strong>
      <p>
        {{ blocked.used }} of {{ blocked.limit }} MB used. Delete files to free space, or move to a
        plan with more storage.
      </p>
    </div>

    <div class="field">
      <label for="file">Upload a file</label>
      <input id="file" type="file" :disabled="busy" @change="upload" />
    </div>

    <table>
      <thead>
        <tr><th>File</th><th>Type</th><th>Size</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="item.id">
          <td>{{ item.filename }}</td>
          <td>{{ item.mime }}</td>
          <td>{{ size(item.size_bytes) }}</td>
          <td><button @click="remove(item)">Delete</button></td>
        </tr>
        <tr v-if="!items.length"><td colspan="4">No files yet.</td></tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.quota { color: var(--muted); font-size: 0.875rem; }

.upgrade {
  border: 1px solid #f59e0b;
  background: color-mix(in srgb, #f59e0b 10%, transparent);
  border-radius: 8px;
  padding: 0.85rem;
  margin-bottom: 1rem;
}

.upgrade p { margin: 0.35rem 0 0; font-size: 0.875rem; }
.field { max-width: 22rem; }
</style>
