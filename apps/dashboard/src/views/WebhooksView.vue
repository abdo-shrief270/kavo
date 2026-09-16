<script setup lang="ts">
import type { Paginated } from '@kavo/api-client'
import { onMounted, ref } from 'vue'
import { api } from '../api'

interface Delivery {
  id: number
  event_type: string
  status: string
  status_code: number | null
  attempt: number
  next_attempt_at: string | null
}

const deliveries = ref<Delivery[]>([])
const loading = ref(true)

async function load() {
  const page = await api.get<Paginated<Delivery>>('/api/webhooks/deliveries')
  deliveries.value = page.data
  loading.value = false
}

/**
 * Manual replay matters more than it looks: silently dropping a delivery is
 * what destroys trust in an integration platform, so a dead-lettered event
 * must never be a dead end for the merchant.
 */
async function retry(delivery: Delivery) {
  await api.post(`/api/webhooks/deliveries/${delivery.id}/retry`)
  await load()
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Webhook deliveries</h1>

    <p v-if="loading">Loading…</p>

    <table v-else>
      <thead>
        <tr><th>Event</th><th>Status</th><th>Code</th><th>Attempt</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="d in deliveries" :key="d.id">
          <td>{{ d.event_type }}</td>
          <td>{{ d.status }}</td>
          <td>{{ d.status_code ?? '—' }}</td>
          <td>{{ d.attempt }}</td>
          <td>
            <button v-if="d.status === 'failed' || d.status === 'dead_lettered'" @click="retry(d)">Retry</button>
          </td>
        </tr>
        <tr v-if="!deliveries.length"><td colspan="5">No deliveries yet.</td></tr>
      </tbody>
    </table>
  </section>
</template>
