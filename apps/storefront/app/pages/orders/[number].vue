<script setup lang="ts">
import type { CustomerAction, Order } from '~~/server/utils/cart'

const route = useRoute()
const { format } = useMoney()

const number = route.params.number as string
const email = computed(() => String(route.query.email ?? ''))

const { data, error, refresh } = await useFetch<{ order: Order; customer_action: CustomerAction }>(
  `/api/orders/${number}`,
  { key: `order:${number}`, query: { email, host: useTenantHost() } },
)

const order = computed(() => data.value?.order ?? null)
const action = computed(() => data.value?.customer_action ?? null)

/**
 * A wallet or 3-D Secure step is a redirect the shopper has to take, and there
 * is nothing useful on this page until they come back from it.
 */
onMounted(() => {
  if (action.value?.type === 'redirect') {
    window.location.href = action.value.url
  }
})

useSeoMeta({ title: () => (order.value ? `Order ${order.value.reference}` : 'Order') })
</script>

<template>
  <section class="order">
    <p v-if="error || !order" class="order__missing">
      We could not find that order. Check the link you were given, or the email address it was placed with.
    </p>

    <template v-else>
      <h1>Order {{ order.reference }}</h1>
      <p class="order__status">{{ order.status_label }}</p>

      <!--
        The rail most of this market uses. The shopper leaves with a code and
        pays at an outlet hours or days later, so this is the whole point of
        the page rather than a footnote on it.
      -->
      <div v-if="action?.type === 'reference'" class="order__reference">
        <p>Pay this code at any Fawry outlet:</p>
        <strong class="order__code">{{ action.reference }}</strong>
        <p v-if="action.expires_at" class="order__expiry">
          Your items are held until {{ new Date(action.expires_at).toLocaleString() }}.
        </p>
        <button type="button" class="order__refresh" @click="refresh()">I have paid — check again</button>
      </div>

      <ul class="order__lines">
        <li v-for="(line, index) in order.items" :key="index">
          <span>{{ line.quantity }} × {{ line.product_name }}<small v-if="line.sku"> ({{ line.sku }})</small></span>
          <span>{{ format(line.total_cents, order.currency) }}</span>
        </li>
      </ul>

      <p class="order__total">
        <strong>Total</strong>
        <strong>{{ format(order.total_cents, order.currency) }}</strong>
      </p>
    </template>
  </section>
</template>

<style scoped>
.order { max-width: 34rem; }
.order__missing { opacity: 0.8; }
.order__status { font-size: 1.05rem; opacity: 0.8; }

.order__reference {
  border: 1px solid var(--kavo-color-primary);
  border-radius: 0.5rem;
  padding: 1rem 1.25rem;
  margin: 1.5rem 0;
}

.order__code {
  display: block;
  font-size: 1.75rem;
  letter-spacing: 0.08em;
  margin: 0.5rem 0;
}

.order__expiry { opacity: 0.75; margin: 0; }

.order__refresh {
  margin-top: 0.85rem;
  padding: 0.5rem 1rem;
  border: 1px solid color-mix(in srgb, var(--kavo-color-text) 25%, transparent);
  border-radius: 0.375rem;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.order__lines { list-style: none; padding: 0; margin: 1.5rem 0; }

.order__lines li {
  display: flex;
  justify-content: space-between;
  padding: 0.6rem 0;
  border-bottom: 1px solid color-mix(in srgb, var(--kavo-color-text) 12%, transparent);
}

.order__lines small { opacity: 0.6; }

.order__total { display: flex; justify-content: space-between; font-size: 1.125rem; }
</style>
