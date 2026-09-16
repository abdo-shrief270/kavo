<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '../api'

interface Metrics {
  tenants: {
    total: number
    by_status: Record<string, number>
    by_product: Record<string, number>
    on_trial: number
    new_this_week: number
  }
  subscriptions: Record<string, number>
  deliverability: {
    notifications_24h: Record<string, number>
    webhooks_failing: number
  }
  events_24h: number
}

const metrics = ref<Metrics | null>(null)
const loading = ref(true)

onMounted(async () => {
  try {
    metrics.value = await api.get<Metrics>('/api/admin/metrics')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section>
    <h1>Platform metrics</h1>

    <p v-if="loading">Loading…</p>

    <template v-else-if="metrics">
      <div class="cards">
        <div class="card">
          <div class="card__label">Tenants</div>
          <div class="card__value">{{ metrics.tenants.total }}</div>
        </div>
        <div class="card">
          <div class="card__label">On trial</div>
          <div class="card__value">{{ metrics.tenants.on_trial }}</div>
        </div>
        <div class="card">
          <div class="card__label">New this week</div>
          <div class="card__value">{{ metrics.tenants.new_this_week }}</div>
        </div>
        <div class="card">
          <div class="card__label">Events (24h)</div>
          <div class="card__value">{{ metrics.events_24h }}</div>
        </div>
        <div class="card">
          <div class="card__label">Webhooks failing</div>
          <div class="card__value">{{ metrics.deliverability.webhooks_failing }}</div>
        </div>
      </div>

      <h2>Tenants by product</h2>
      <table>
        <tbody>
          <tr v-for="(count, product) in metrics.tenants.by_product" :key="product">
            <td>{{ product }}</td>
            <td>{{ count }}</td>
          </tr>
        </tbody>
      </table>

      <h2>Notification deliveries (24h)</h2>
      <table>
        <tbody>
          <tr v-for="(count, status) in metrics.deliverability.notifications_24h" :key="status">
            <td>{{ status }}</td>
            <td>{{ count }}</td>
          </tr>
          <tr v-if="!Object.keys(metrics.deliverability.notifications_24h).length">
            <td colspan="2">No deliveries in the last 24 hours.</td>
          </tr>
        </tbody>
      </table>
    </template>
  </section>
</template>
