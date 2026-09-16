<script setup lang="ts">
import type { UsageMetric } from '@kavo/api-client'
import { onMounted, ref } from 'vue'
import { api } from '../api'

interface TenantPayload {
  tenant: { name: string; slug: string; status: string; product: string; on_trial: boolean; trial_ends_at: string | null }
  usage: UsageMetric[]
}

const data = ref<TenantPayload | null>(null)
const loading = ref(true)

onMounted(async () => {
  try {
    data.value = await api.get<TenantPayload>('/api/tenant')
  } finally {
    loading.value = false
  }
})

/** null remaining means the plan grants this metric without a limit. */
function describe(metric: UsageMetric): string {
  return metric.remaining === null ? 'unlimited' : `${metric.remaining} left`
}

function percent(metric: UsageMetric): number | null {
  if (metric.remaining === null) return null
  const total = metric.used + metric.remaining

  return total === 0 ? 0 : Math.round((metric.used / total) * 100)
}
</script>

<template>
  <section>
    <h1>Overview</h1>

    <p v-if="loading">Loading…</p>

    <template v-else-if="data">
      <p>
        <strong>{{ data.tenant.name }}</strong> · {{ data.tenant.product }} · {{ data.tenant.status }}
        <span v-if="data.tenant.on_trial"> · trial ends {{ data.tenant.trial_ends_at }}</span>
      </p>

      <h2>Usage this period</h2>
      <table>
        <thead>
          <tr><th>Metric</th><th>Used</th><th>Remaining</th><th>At</th></tr>
        </thead>
        <tbody>
          <tr v-for="m in data.usage" :key="m.metric">
            <td>{{ m.metric }}</td>
            <td>{{ m.used }}</td>
            <td>{{ describe(m) }}</td>
            <td>{{ percent(m) === null ? '—' : `${percent(m)}%` }}</td>
          </tr>
        </tbody>
      </table>
    </template>
  </section>
</template>
