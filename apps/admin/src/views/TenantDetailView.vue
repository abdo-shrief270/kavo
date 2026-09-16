<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '../api'

const props = defineProps<{ id: string }>()

interface Detail {
  tenant: { id: number; name: string; slug: string; status: string; product: string; trial_ends_at: string | null }
  counts: { domains: number; invoices: number; media: number }
  usage: Array<{ metric_key: string; value: number }>
}

const detail = ref<Detail | null>(null)
const loading = ref(true)
const busy = ref(false)
const reason = ref('')

async function load() {
  detail.value = await api.get<Detail>(`/api/admin/tenants/${props.id}`)
  loading.value = false
}

/**
 * Suspension takes a tenant's storefront offline, so it asks for a reason and
 * confirmation. The reason lands in the audit log next to who did it.
 */
async function setStatus(status: string) {
  if (!confirm(`Set ${detail.value?.tenant.name} to "${status}"?`)) return

  busy.value = true

  try {
    await api.patch(`/api/admin/tenants/${props.id}/status`, { status, reason: reason.value || null })
    reason.value = ''
    await load()
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <section>
    <p v-if="loading">Loading…</p>

    <template v-else-if="detail">
      <h1>{{ detail.tenant.name }}</h1>
      <p>{{ detail.tenant.slug }} · {{ detail.tenant.product }} · {{ detail.tenant.status }}</p>

      <div class="cards">
        <div class="card"><div class="card__label">Domains</div><div class="card__value">{{ detail.counts.domains }}</div></div>
        <div class="card"><div class="card__label">Invoices</div><div class="card__value">{{ detail.counts.invoices }}</div></div>
        <div class="card"><div class="card__label">Media</div><div class="card__value">{{ detail.counts.media }}</div></div>
      </div>

      <h2>Usage this period</h2>
      <table>
        <tbody>
          <tr v-for="u in detail.usage" :key="u.metric_key">
            <td>{{ u.metric_key }}</td>
            <td>{{ u.value }}</td>
          </tr>
          <tr v-if="!detail.usage.length"><td colspan="2">No metered usage yet.</td></tr>
        </tbody>
      </table>

      <h2>Status</h2>
      <div class="field">
        <label for="reason">Reason (recorded in the audit log)</label>
        <input id="reason" v-model="reason" placeholder="Why this change is being made" />
      </div>
      <div class="actions">
        <button :disabled="busy" @click="setStatus('active')">Activate</button>
        <button :disabled="busy" @click="setStatus('suspended')">Suspend</button>
        <button :disabled="busy" @click="setStatus('cancelled')">Cancel</button>
      </div>
    </template>
  </section>
</template>

<style scoped>
.actions { display: flex; gap: 0.5rem; }
.actions button { padding: 0.5rem 0.9rem; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); cursor: pointer; }
</style>
