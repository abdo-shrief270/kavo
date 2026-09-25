<script setup lang="ts">
import { ApiError, type CustomerAction, type Order, type PaymentSummary } from '@kavo/api-client'
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api'
import { useMoney } from '../composables/useMoney'

const route = useRoute()
const { format } = useMoney()

interface Detail {
  order: Order
  customer: {
    name: string
    email: string
    phone: string
    shipping_address: Record<string, string>
  }
  payment: PaymentSummary | null
  customer_action: CustomerAction
}

const detail = ref<Detail | null>(null)
const busy = ref(false)
const message = ref<string | null>(null)

const orderId = computed(() => Number(route.params.id))
const order = computed(() => detail.value?.order ?? null)

/**
 * Cancelling is only offered while the order is still open. A paid order is a
 * refund — that moves money, and unlike a cancellation it must not put the
 * stock back, because by then the goods have usually shipped.
 */
const canCancel = computed(() => order.value?.status === 'pending' || order.value?.status === 'awaiting_payment')

const address = computed(() =>
  Object.entries(detail.value?.customer.shipping_address ?? {}).filter(([, value]) => value),
)

async function load() {
  detail.value = await api.get<Detail>(`/api/orders/${orderId.value}`)
}

async function cancel() {
  if (!confirm('Cancel this order and return its stock to the catalogue?')) return

  busy.value = true
  message.value = null

  try {
    await api.post(`/api/orders/${orderId.value}/cancel`)
    await load()
  } catch (error) {
    message.value = error instanceof ApiError ? error.message : 'Could not cancel the order.'
  } finally {
    busy.value = false
  }
}

function when(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString() : '—'
}

onMounted(load)
</script>

<template>
  <section v-if="detail && order">
    <header class="head">
      <h1>Order #{{ order.number }}</h1>
      <RouterLink class="back" :to="{ name: 'orders' }">Back to orders</RouterLink>
    </header>

    <p v-if="message" class="error">{{ message }}</p>

    <!--
      The rail most of this market uses. A merchant fielding "have you got my
      payment?" needs the code and its deadline in front of them, not buried
      in a payments screen.
    -->
    <div v-if="detail.customer_action?.type === 'reference'" class="awaiting">
      <strong>Waiting for payment at an outlet.</strong>
      <p>
        Reference <code>{{ detail.customer_action.reference }}</code>
        <template v-if="detail.customer_action.expires_at">
          · held until {{ when(detail.customer_action.expires_at) }}
        </template>
      </p>
      <p class="small">Stock stays reserved until they pay or the window closes.</p>
    </div>

    <dl class="facts">
      <div><dt>Status</dt><dd>{{ order.status.replace('_', ' ') }}</dd></div>
      <div><dt>Placed</dt><dd>{{ when(order.placed_at) }}</dd></div>
      <div><dt>Paid</dt><dd>{{ when(order.paid_at) }}</dd></div>
      <div><dt>Stock</dt><dd>{{ order.inventory_state }}</dd></div>
    </dl>

    <h2>Items</h2>
    <table>
      <thead>
        <tr><th>Item</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Total</th></tr>
      </thead>
      <tbody>
        <!--
          Every column here is the snapshot taken when the order was placed,
          not a lookup. Renaming or re-pricing the product does not change what
          this customer agreed to pay.
        -->
        <tr v-for="item in order.items" :key="item.id">
          <td>
            {{ item.product_name }}
            <small class="muted">{{ Object.entries(item.options).map(([a, v]) => `${a}: ${v}`).join(' · ') }}</small>
          </td>
          <td>{{ item.variant_sku }}</td>
          <td>{{ item.quantity }}</td>
          <td>{{ format(item.unit_price_cents, order.currency) }}</td>
          <td>{{ format(item.total_cents, order.currency) }}</td>
        </tr>
      </tbody>
      <tfoot>
        <tr><td colspan="4">Subtotal</td><td>{{ format(order.subtotal_cents, order.currency) }}</td></tr>
        <tr v-if="order.shipping_cents"><td colspan="4">Shipping</td><td>{{ format(order.shipping_cents, order.currency) }}</td></tr>
        <tr class="total"><td colspan="4">Total</td><td>{{ format(order.total_cents, order.currency) }}</td></tr>
      </tfoot>
    </table>

    <div class="columns">
      <div>
        <h2>Customer</h2>
        <p>
          {{ detail.customer.name }}<br />
          <a :href="`mailto:${detail.customer.email}`">{{ detail.customer.email }}</a><br />
          <a :href="`tel:${detail.customer.phone}`">{{ detail.customer.phone }}</a>
        </p>

        <h2>Ship to</h2>
        <p v-if="address.length">
          <template v-for="[key, value] in address" :key="key">{{ value }}<br /></template>
        </p>
        <p v-else class="muted">No address given.</p>
      </div>

      <div v-if="detail.payment">
        <h2>Payment</h2>
        <dl class="facts facts--stacked">
          <div><dt>Rail</dt><dd>{{ detail.payment.rail }}</dd></div>
          <div><dt>Gateway</dt><dd>{{ detail.payment.gateway }}</dd></div>
          <div><dt>State</dt><dd>{{ detail.payment.status.replace(/_/g, ' ') }}</dd></div>
          <div><dt>Reference</dt><dd>{{ detail.payment.reference }}</dd></div>
        </dl>
        <p v-if="detail.payment.last_error" class="error">{{ detail.payment.last_error }}</p>
      </div>
    </div>

    <div class="actions">
      <button v-if="canCancel" class="danger" :disabled="busy" @click="cancel">
        Cancel and return the stock
      </button>
      <p v-else class="muted">
        This order is closed. A paid order is refunded through payments, which does not restock it.
      </p>
    </div>
  </section>
</template>

<style scoped>
.head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; }
.back { color: var(--muted); font-size: 0.875rem; }

h2 { font-size: 1rem; margin: 1.5rem 0 0.5rem; }
.muted { color: var(--muted); font-size: 0.85rem; }
td .muted { display: block; }

.awaiting {
  border: 1px solid #f59e0b;
  background: color-mix(in srgb, #f59e0b 10%, transparent);
  border-radius: 8px;
  padding: 0.85rem;
  margin: 1rem 0;
}

.awaiting p { margin: 0.35rem 0 0; font-size: 0.9rem; }
.awaiting .small { font-size: 0.8rem; color: var(--muted); }
.awaiting code { font-size: 1.05rem; letter-spacing: 0.06em; }

.facts { display: flex; gap: 2rem; margin: 1rem 0; padding: 0; }
.facts--stacked { flex-direction: column; gap: 0.4rem; }
.facts dt { color: var(--muted); font-size: 0.78rem; }
.facts dd { margin: 0; font-size: 0.925rem; text-transform: capitalize; }

tfoot td { color: var(--muted); }
tfoot .total td { color: var(--fg); font-weight: 600; }

.columns { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }

.actions { margin-top: 1.5rem; }
.danger { background: none; border: 1px solid #dc2626; color: #dc2626; padding: 0.55rem 1rem; border-radius: 6px; cursor: pointer; }
.danger:disabled { opacity: 0.6; cursor: default; }

@media (max-width: 720px) {
  .columns { grid-template-columns: 1fr; }
  .facts { flex-wrap: wrap; gap: 1rem; }
}
</style>
