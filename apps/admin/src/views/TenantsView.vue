<script setup lang="ts">
import type { Paginated } from '@kavo/api-client'
import { onMounted, ref, watch } from 'vue'
import { api } from '../api'

interface AdminTenant {
  id: number
  name: string
  slug: string
  status: string
  product: string
  users_count: number
  created_at: string
}

const tenants = ref<AdminTenant[]>([])
const search = ref('')
const status = ref('')
const loading = ref(true)
let debounce: ReturnType<typeof setTimeout> | undefined

async function load() {
  loading.value = true

  const params = new URLSearchParams()
  if (search.value) params.set('search', search.value)
  if (status.value) params.set('status', status.value)

  try {
    tenants.value = (await api.get<Paginated<AdminTenant>>(`/api/admin/tenants?${params}`)).data
  } finally {
    loading.value = false
  }
}

// Debounced so typing does not issue a cross-tenant query — and an audit log
// entry — on every keystroke.
watch([search, status], () => {
  clearTimeout(debounce)
  debounce = setTimeout(load, 300)
})

onMounted(load)
</script>

<template>
  <section>
    <h1>Tenants</h1>

    <div class="filters">
      <input v-model="search" placeholder="Search name or slug" />
      <select v-model="status">
        <option value="">All statuses</option>
        <option value="active">Active</option>
        <option value="pending">Pending</option>
        <option value="suspended">Suspended</option>
        <option value="cancelled">Cancelled</option>
      </select>
    </div>

    <p v-if="loading">Loading…</p>

    <table v-else>
      <thead>
        <tr><th>Name</th><th>Product</th><th>Status</th><th>Users</th></tr>
      </thead>
      <tbody>
        <tr v-for="t in tenants" :key="t.id">
          <td><RouterLink :to="`/tenants/${t.id}`">{{ t.name }}</RouterLink></td>
          <td>{{ t.product }}</td>
          <td>{{ t.status }}</td>
          <td>{{ t.users_count }}</td>
        </tr>
        <tr v-if="!tenants.length"><td colspan="4">No tenants match.</td></tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.filters { display: flex; gap: 0.75rem; margin-bottom: 1rem; max-width: 30rem; }
</style>
