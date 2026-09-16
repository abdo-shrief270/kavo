<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '../api'

interface PlanFeature { feature_key: string; limit_value: number | null; overage_behavior: string }
interface Plan { id: number; code: string; name: string; product: string; price_cents: number; currency: string; is_active: boolean; features: PlanFeature[] }

const plans = ref<Plan[]>([])
const loading = ref(true)

async function load() {
  plans.value = (await api.get<{ plans: Plan[] }>('/api/admin/plans')).plans
  loading.value = false
}

async function toggle(plan: Plan) {
  await api.patch(`/api/admin/plans/${plan.id}`, { is_active: !plan.is_active })
  await load()
}

function money(plan: Plan): string {
  return `${(plan.price_cents / 100).toFixed(2)} ${plan.currency}`
}

/** null is unlimited; 0 is an explicit denial. They are not the same. */
function limit(feature: PlanFeature): string {
  if (feature.limit_value === null) return 'unlimited'

  return feature.limit_value === 0 ? 'denied' : String(feature.limit_value)
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Plans</h1>

    <p v-if="loading">Loading…</p>

    <div v-for="plan in plans" v-else :key="plan.id" class="plan">
      <header>
        <strong>{{ plan.name }}</strong>
        <span class="muted">{{ plan.code }} · {{ plan.product }} · {{ money(plan) }}</span>
        <button @click="toggle(plan)">{{ plan.is_active ? 'Deactivate' : 'Activate' }}</button>
      </header>

      <table>
        <thead>
          <tr><th>Feature</th><th>Limit</th><th>Overage</th></tr>
        </thead>
        <tbody>
          <tr v-for="f in plan.features" :key="f.feature_key">
            <td>{{ f.feature_key }}</td>
            <td>{{ limit(f) }}</td>
            <td>{{ f.overage_behavior }}</td>
          </tr>
          <tr v-if="!plan.features.length"><td colspan="3">No metered features.</td></tr>
        </tbody>
      </table>
    </div>

    <p v-if="!loading && !plans.length">No plans defined yet.</p>
  </section>
</template>

<style scoped>
.plan { border: 1px solid var(--line); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
.plan header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
.muted { color: var(--muted); font-size: 0.85rem; }
.plan header button { margin-left: auto; padding: 0.35rem 0.7rem; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); cursor: pointer; }
</style>
