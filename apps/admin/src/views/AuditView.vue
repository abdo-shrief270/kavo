<script setup lang="ts">
import type { Paginated } from '@kavo/api-client'
import { onMounted, ref, watch } from 'vue'
import { api } from '../api'

interface AuditEntry {
  id: number
  tenant_id: number | null
  user_id: number | null
  action: string
  ip: string | null
  created_at: string
}

const entries = ref<AuditEntry[]>([])
const action = ref('')
const loading = ref(true)
let debounce: ReturnType<typeof setTimeout> | undefined

async function load() {
  loading.value = true

  const params = new URLSearchParams()
  if (action.value) params.set('action', action.value)

  try {
    entries.value = (await api.get<Paginated<AuditEntry>>(`/api/admin/audit-logs?${params}`)).data
  } finally {
    loading.value = false
  }
}

watch(action, () => {
  clearTimeout(debounce)
  debounce = setTimeout(load, 300)
})

onMounted(load)
</script>

<template>
  <section>
    <h1>Audit log</h1>
    <p class="muted">
      Entries prefixed <code>platform.</code> are cross-tenant reads by staff. Read-only by
      design — an audit log an administrator can edit is not an audit log.
    </p>

    <div class="field">
      <label for="action">Filter by action prefix</label>
      <input id="action" v-model="action" placeholder="platform." />
    </div>

    <p v-if="loading">Loading…</p>

    <table v-else>
      <thead>
        <tr><th>When</th><th>Action</th><th>Tenant</th><th>User</th><th>IP</th></tr>
      </thead>
      <tbody>
        <tr v-for="e in entries" :key="e.id">
          <td>{{ e.created_at }}</td>
          <td>{{ e.action }}</td>
          <td>{{ e.tenant_id ?? '—' }}</td>
          <td>{{ e.user_id ?? '—' }}</td>
          <td>{{ e.ip ?? '—' }}</td>
        </tr>
        <tr v-if="!entries.length"><td colspan="5">No matching entries.</td></tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.muted { color: var(--muted); font-size: 0.875rem; max-width: 42rem; }
.field { max-width: 20rem; }
</style>
