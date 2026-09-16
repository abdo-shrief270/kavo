<script setup lang="ts">
import { ApiError } from '@kavo/api-client'
import { onMounted, ref } from 'vue'
import { api } from '../api'

interface Domain {
  id: number
  hostname: string
  status: string
  verified_at: string | null
  last_error: string | null
}

interface DnsRecord { type: string; name: string; value: string }

const domains = ref<Domain[]>([])
const hostname = ref('')
const record = ref<DnsRecord | null>(null)
const error = ref('')

async function load() {
  domains.value = (await api.get<{ domains: Domain[] }>('/api/domains')).domains
}

async function add() {
  error.value = ''

  try {
    const result = await api.post<{ domain: Domain; dns_record: DnsRecord }>('/api/domains', { hostname: hostname.value })
    record.value = result.dns_record
    hostname.value = ''
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? (e.errors.hostname?.[0] ?? e.message) : 'Could not add the domain.'
  }
}

/**
 * Verification is what gates certificate issuance: Caddy only asks for a
 * certificate once the hostname reaches verifying or active, so an
 * unverified domain can never trigger one in our name.
 */
async function verify(domain: Domain) {
  try {
    await api.post(`/api/domains/${domain.id}/verify`)
  } catch {
    // A failed check is an expected outcome (DNS propagation), not an error
    // worth interrupting the page for — the row shows why.
  } finally {
    await load()
  }
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Domains</h1>

    <form class="add" @submit.prevent="add">
      <div class="field">
        <label for="hostname">Custom domain</label>
        <input id="hostname" v-model="hostname" placeholder="shop.example.com" required />
      </div>
      <button class="primary" type="submit">Add domain</button>
      <p v-if="error" class="error">{{ error }}</p>
    </form>

    <div v-if="record" class="record">
      <p>Add this DNS record, then verify:</p>
      <code>{{ record.type }} {{ record.name }} → {{ record.value }}</code>
    </div>

    <table>
      <thead>
        <tr><th>Hostname</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="d in domains" :key="d.id">
          <td>{{ d.hostname }}</td>
          <td>
            {{ d.status }}
            <div v-if="d.last_error" class="error">{{ d.last_error }}</div>
          </td>
          <td>
            <button v-if="d.status !== 'active'" @click="verify(d)">Verify</button>
          </td>
        </tr>
        <tr v-if="!domains.length"><td colspan="3">No custom domains yet.</td></tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.add { max-width: 24rem; margin-bottom: 1.5rem; }
.record { margin-bottom: 1.5rem; font-size: 0.875rem; }
code { display: block; padding: 0.5rem; background: var(--line); border-radius: 6px; overflow-x: auto; }
</style>
