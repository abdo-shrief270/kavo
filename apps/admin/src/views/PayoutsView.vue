<script setup lang="ts">
import { ApiError, type LedgerEntry, type Payout, type TenantBalance } from '@kavo/api-client'
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api'
import { useMoney } from '../composables/useMoney'

const { format, toCents } = useMoney()

interface LedgerPayload {
  tenant: { id: number; name: string; slug: string }
  balance_cents: number
  currency: string
  entries: LedgerEntry[]
  payouts: Payout[]
}

const balances = ref<TenantBalance[]>([])
const currency = ref('EGP')
const selected = ref<LedgerPayload | null>(null)
const busy = ref(false)
const message = ref<string | null>(null)
const error = ref<string | null>(null)

const form = reactive({ amount: '', method: 'instapay', handle: '', notes: '' })

/** Only shops actually owed money — a zero balance is not a to-do. */
const owed = computed(() => balances.value.filter((row) => row.balance_cents > 0))
const owing = computed(() => balances.value.filter((row) => row.balance_cents < 0))

const total = computed(() => owed.value.reduce((sum, row) => sum + row.balance_cents, 0))

async function loadBalances() {
  const payload = await api.get<{ balances: TenantBalance[]; currency: string }>('/api/admin/balances')

  balances.value = payload.balances
  currency.value = payload.currency
}

async function open(tenantId: number) {
  error.value = null
  message.value = null
  selected.value = await api.get<LedgerPayload>(`/api/admin/tenants/${tenantId}/ledger`)
  form.amount = selected.value.balance_cents > 0 ? String(selected.value.balance_cents / 100) : ''
}

async function pay() {
  if (!selected.value) return

  const amountCents = toCents(form.amount)

  if (amountCents === null || amountCents <= 0) {
    error.value = 'Enter an amount to send.'

    return
  }

  busy.value = true
  error.value = null

  try {
    await api.post(`/api/admin/tenants/${selected.value.tenant.id}/payouts`, {
      amount_cents: amountCents,
      method: form.method,
      destination: form.handle ? { handle: form.handle } : {},
      notes: form.notes || null,
    })

    // Refresh first: open() clears the banner as part of resetting the
    // panel, so a message set before it would be wiped on the way out and
    // the administrator would see nothing happen.
    await Promise.all([loadBalances(), open(selected.value.tenant.id)])
    message.value = 'Payout recorded. The balance is already reduced — mark it paid once the transfer lands.'
  } catch (e: unknown) {
    // The server's refusal is the useful one: "That is more than is owed."
    error.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Could not record the payout.'
  } finally {
    busy.value = false
  }
}

async function settle(payout: Payout, status: 'paid' | 'failed') {
  if (!selected.value) return

  const reason = status === 'failed' ? prompt('What went wrong?') : null

  if (status === 'failed' && !reason) return

  busy.value = true
  error.value = null

  try {
    await api.post(`/api/admin/tenants/${selected.value.tenant.id}/payouts/${payout.id}/settle`, { status, reason })
    await Promise.all([loadBalances(), open(selected.value.tenant.id)])
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Could not settle the payout.'
  } finally {
    busy.value = false
  }
}

function when(iso: string): string {
  return new Date(iso).toLocaleDateString()
}

onMounted(loadBalances)
</script>

<template>
  <section>
    <h1>Payouts</h1>
    <p class="lede">
      The platform is the merchant of record, so every shop's takings land in its accounts. This is
      what it owes them.
    </p>

    <div class="summary">
      <span>Holding <strong>{{ format(total, currency) }}</strong> for {{ owed.length }} shop{{ owed.length === 1 ? '' : 's' }}</span>
      <!-- A refund after a payout genuinely leaves a shop owing the platform. -->
      <span v-if="owing.length" class="warn">{{ owing.length }} in debit</span>
    </div>

    <table>
      <thead>
        <tr><th>Shop</th><th>Balance</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="row in balances" :key="row.tenant_id">
          <td>{{ row.name }} <small class="muted">{{ row.slug }}</small></td>
          <td :class="row.balance_cents < 0 ? 'debit' : ''">{{ format(Math.abs(row.balance_cents), currency) }}{{ row.balance_cents < 0 ? ' owing' : '' }}</td>
          <td><button @click="open(row.tenant_id)">Open</button></td>
        </tr>
        <tr v-if="!balances.length"><td colspan="3">No shop has taken money yet.</td></tr>
      </tbody>
    </table>

    <template v-if="selected">
      <h2>{{ selected.tenant.name }}</h2>
      <p class="balance">
        Owed: <strong>{{ format(Math.abs(selected.balance_cents), selected.currency) }}</strong>
        <span v-if="selected.balance_cents < 0"> (in debit)</span>
      </p>

      <p v-if="message" class="notice">{{ message }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <form class="payout" @submit.prevent="pay">
        <div class="field">
          <label for="amount">Amount</label>
          <input id="amount" v-model="form.amount" inputmode="decimal" />
        </div>
        <div class="field">
          <label for="method">Method</label>
          <select id="method" v-model="form.method">
            <option value="instapay">InstaPay</option>
            <option value="bank_transfer">Bank transfer</option>
            <option value="wallet">Wallet</option>
            <option value="cash">Cash</option>
          </select>
        </div>
        <div class="field">
          <label for="handle">Destination</label>
          <input id="handle" v-model="form.handle" placeholder="Account or handle" />
        </div>
        <div class="field">
          <label for="notes">Note</label>
          <input id="notes" v-model="form.notes" />
        </div>
        <button class="primary" type="submit" :disabled="busy">Record payout</button>
      </form>

      <h3>Payouts</h3>
      <table>
        <thead>
          <tr><th>Reference</th><th>Requested</th><th>Amount</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="payout in selected.payouts" :key="payout.id">
            <td>{{ payout.reference }}</td>
            <td>{{ when(payout.requested_at) }}</td>
            <td>{{ format(payout.amount_cents, payout.currency) }}</td>
            <td>
              {{ payout.status_label }}
              <small v-if="payout.failure_reason" class="muted">{{ payout.failure_reason }}</small>
            </td>
            <td>
              <template v-if="payout.status === 'pending'">
                <button :disabled="busy" @click="settle(payout, 'paid')">Arrived</button>
                <button :disabled="busy" @click="settle(payout, 'failed')">Bounced</button>
              </template>
            </td>
          </tr>
          <tr v-if="!selected.payouts.length"><td colspan="5">Nothing sent yet.</td></tr>
        </tbody>
      </table>

      <h3>Ledger</h3>
      <table>
        <thead>
          <tr><th>Date</th><th>What</th><th>Amount</th></tr>
        </thead>
        <tbody>
          <tr v-for="entry in selected.entries" :key="entry.id">
            <td>{{ when(entry.occurred_at) }}</td>
            <td>{{ entry.label }} <small class="muted">{{ entry.description }}</small></td>
            <td :class="entry.amount_cents < 0 ? 'debit' : 'credit'">
              {{ entry.amount_cents < 0 ? '−' : '+' }}{{ format(Math.abs(entry.amount_cents), entry.currency) }}
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </section>
</template>

<style scoped>
.lede { color: var(--muted); font-size: 0.9rem; margin-top: 0; }
.summary { display: flex; gap: 1.5rem; margin-bottom: 1rem; font-size: 0.925rem; }
.warn { color: #b45309; }

h2 { font-size: 1.05rem; margin-top: 2rem; }
h3 { font-size: 0.925rem; margin: 1.5rem 0 0.4rem; color: var(--muted); }
.balance { margin: 0.25rem 0 1rem; }

.muted { display: block; color: var(--muted); font-size: 0.8rem; }
.credit { color: #16a34a; }
.debit { color: #b45309; }

.payout { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) auto; gap: 0.75rem; align-items: end; margin-bottom: 1rem; }

.notice { color: #16a34a; font-size: 0.875rem; }

@media (max-width: 860px) {
  .payout { grid-template-columns: 1fr; }
}
</style>
