<script setup lang="ts">
import type { Order, OrderStatus, Paginated } from '@kavo/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api'
import { useMoney } from '../composables/useMoney'

const { format } = useMoney()

const orders = ref<Order[]>([])
const total = ref(0)
const page = ref(1)
const lastPage = ref(1)
const search = ref('')
const status = ref('')
const loading = ref(false)

/**
 * The label a merchant reads. "Awaiting payment" is the one that matters in
 * this market: a Fawry reference is paid at a kiosk hours or days later, so an
 * order sitting here for two days is normal rather than stuck.
 */
const LABELS: Record<OrderStatus, string> = {
  pending: 'Pending',
  awaiting_payment: 'Awaiting payment',
  paid: 'Paid',
  cancelled: 'Cancelled',
  expired: 'Expired',
  refunded: 'Refunded',
}

async function load() {
  loading.value = true

  try {
    const params = new URLSearchParams({ page: String(page.value) })
    if (search.value) params.set('q', search.value)
    if (status.value) params.set('status', status.value)

    const payload = await api.get<Paginated<Order>>(`/api/orders?${params}`)

    orders.value = payload.data
    total.value = payload.total
    lastPage.value = payload.last_page
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined

watch([search, status], () => {
  page.value = 1
  clearTimeout(timer)
  timer = setTimeout(load, 250)
})

watch(page, load)

function placed(iso: string): string {
  return new Date(iso).toLocaleString()
}

const empty = computed(() => !loading.value && orders.value.length === 0)

/** What is still owed, which is the number a merchant chases. */
const outstanding = computed(() =>
  orders.value.filter((order) => order.status === 'awaiting_payment' || order.status === 'pending'),
)

onMounted(load)
</script>

<template>
  <section>
    <h1>Orders</h1>

    <p v-if="outstanding.length" class="waiting">
      {{ outstanding.length }} order{{ outstanding.length === 1 ? '' : 's' }} placed but not yet
      paid. Their stock is held until they are.
    </p>

    <div class="filters">
      <input v-model="search" type="search" placeholder="Customer name, email or order number" aria-label="Search orders" />
      <select v-model="status" aria-label="Filter by status">
        <option value="">All statuses</option>
        <option v-for="(label, value) in LABELS" :key="value" :value="value">{{ label }}</option>
      </select>
    </div>

    <table>
      <thead>
        <tr>
          <th>Order</th>
          <th>Customer</th>
          <th>Placed</th>
          <th>Status</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="order in orders" :key="order.id">
          <td>
            <RouterLink :to="{ name: 'order', params: { id: order.id } }">#{{ order.number }}</RouterLink>
            <small class="muted">{{ order.items.length }} item{{ order.items.length === 1 ? '' : 's' }}</small>
          </td>
          <td>
            {{ order.customer_name }}
            <small class="muted">{{ order.customer_email }}</small>
          </td>
          <td>{{ placed(order.placed_at) }}</td>
          <td><span class="pill" :class="`pill--${order.status}`">{{ LABELS[order.status] }}</span></td>
          <td>{{ format(order.total_cents, order.currency) }}</td>
        </tr>

        <tr v-if="empty"><td colspan="5">No orders yet.</td></tr>
      </tbody>
    </table>

    <nav v-if="lastPage > 1" class="pager">
      <button :disabled="page <= 1" @click="page--">Previous</button>
      <span>Page {{ page }} of {{ lastPage }} · {{ total }} orders</span>
      <button :disabled="page >= lastPage" @click="page++">Next</button>
    </nav>
  </section>
</template>

<style scoped>
.filters { display: flex; gap: 0.75rem; margin-bottom: 1rem; }
.filters input { max-width: 22rem; }
.filters select { max-width: 12rem; }

.muted { display: block; color: var(--muted); font-size: 0.8rem; }

.waiting { color: var(--muted); font-size: 0.875rem; }

.pill { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.78rem; border: 1px solid var(--line); }
.pill--paid { border-color: #16a34a; color: #16a34a; }
.pill--awaiting_payment { border-color: #f59e0b; color: #b45309; }
.pill--cancelled, .pill--expired { color: var(--muted); }
.pill--refunded { border-color: #6366f1; color: #6366f1; }

.pager { display: flex; align-items: center; gap: 1rem; margin-top: 1rem; font-size: 0.85rem; color: var(--muted); }
.pager button { background: none; border: 1px solid var(--line); color: inherit; padding: 0.35rem 0.75rem; border-radius: 6px; cursor: pointer; }
.pager button:disabled { opacity: 0.5; cursor: default; }
</style>
