<script setup lang="ts">
import type { LedgerEntry, Paginated, Payout } from '@kaabosh/api-client'
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import { useMoney } from '../composables/useMoney'

const { format } = useMoney()

interface BalancePayload {
  balance: { amount_cents: number; currency: string; overdrawn: boolean }
  commission_basis_points: number
  in_flight_cents: number
  entries: LedgerEntry[]
}

const payload = ref<BalancePayload | null>(null)
const payouts = ref<Payout[]>([])
const loading = ref(true)

const currency = computed(() => payload.value?.balance.currency ?? 'EGP')

/** Basis points are the storage unit; nobody reads "100 bps" on a dashboard. */
const commission = computed(() => `${(payload.value?.commission_basis_points ?? 0) / 100}%`)

async function load() {
  loading.value = true

  try {
    payload.value = await api.get<BalancePayload>('/api/balance')
    payouts.value = (await api.get<Paginated<Payout>>('/api/payouts')).data
  } finally {
    loading.value = false
  }
}

function when(iso: string): string {
  return new Date(iso).toLocaleDateString()
}

onMounted(load)
</script>

<template>
  <section v-if="!loading && payload">
    <h1>Balance</h1>

    <div class="cards">
      <div class="card" :class="{ 'card--owing': payload.balance.overdrawn }">
        <span class="card__label">{{ payload.balance.overdrawn ? 'You owe' : 'Owed to you' }}</span>
        <strong class="card__value">{{ format(Math.abs(payload.balance.amount_cents), currency) }}</strong>
        <!--
          A negative balance is real — a refund after a payout leaves the
          merchant owing the platform — so it is shown rather than floored to
          zero, which would just hide it.
        -->
        <small v-if="payload.balance.overdrawn">
          A refund went out after you were paid. It comes off your next settlement.
        </small>
      </div>

      <div class="card">
        <span class="card__label">On its way</span>
        <strong class="card__value">{{ format(payload.in_flight_cents, currency) }}</strong>
        <small>Already sent, not yet confirmed as arrived.</small>
      </div>

      <div class="card">
        <span class="card__label">Platform commission</span>
        <strong class="card__value">{{ commission }}</strong>
        <small>Taken from each sale when it settles.</small>
      </div>
    </div>

    <h2>Payouts</h2>
    <table>
      <thead>
        <tr><th>Reference</th><th>Requested</th><th>Method</th><th>Status</th><th>Amount</th></tr>
      </thead>
      <tbody>
        <tr v-for="payout in payouts" :key="payout.id">
          <td>{{ payout.reference }}</td>
          <td>{{ when(payout.requested_at) }}</td>
          <td>{{ payout.method.replace('_', ' ') }}</td>
          <td>
            <span class="pill" :class="`pill--${payout.status}`">{{ payout.status_label }}</span>
            <small v-if="payout.failure_reason" class="muted">{{ payout.failure_reason }}</small>
          </td>
          <td>{{ format(payout.amount_cents, payout.currency) }}</td>
        </tr>
        <tr v-if="!payouts.length"><td colspan="5">Nothing paid out yet.</td></tr>
      </tbody>
    </table>

    <h2>Activity</h2>
    <table>
      <thead>
        <tr><th>Date</th><th>What</th><th>Amount</th></tr>
      </thead>
      <tbody>
        <tr v-for="entry in payload.entries" :key="entry.id">
          <td>{{ when(entry.occurred_at) }}</td>
          <td>
            {{ entry.label }}
            <small class="muted">{{ entry.description }}</small>
          </td>
          <td :class="entry.amount_cents < 0 ? 'debit' : 'credit'">
            {{ entry.amount_cents < 0 ? '−' : '+' }}{{ format(Math.abs(entry.amount_cents), entry.currency) }}
          </td>
        </tr>
        <tr v-if="!payload.entries.length"><td colspan="3">Nothing yet. Your first paid order lands here.</td></tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin: 1rem 0 2rem; }

.card {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.card--owing { border-color: #b45309; }
.card__label { font-size: 0.8rem; color: var(--muted); }
.card__value { font-size: 1.5rem; }
.card small { color: var(--muted); font-size: 0.78rem; }

h2 { font-size: 1rem; margin: 1.75rem 0 0.5rem; }
.muted { display: block; color: var(--muted); font-size: 0.8rem; }

.credit { color: #16a34a; }
.debit { color: var(--muted); }

.pill { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.78rem; border: 1px solid var(--line); }
.pill--paid { border-color: #16a34a; color: #16a34a; }
.pill--pending { border-color: #f59e0b; color: #b45309; }
.pill--failed { border-color: #dc2626; color: #dc2626; }

@media (max-width: 720px) {
  .cards { grid-template-columns: 1fr; }
}
</style>
