<script setup lang="ts">
import type { PlacedOrder } from '~~/server/utils/cart'

const { cart, refresh } = useCart()
const { format } = useMoney()
const router = useRouter()

await refresh()

const rails = ref<{ rail: string; label: string; offline: boolean }[]>([])

const form = reactive({
  customer_name: '',
  customer_email: '',
  customer_phone: '',
  rail: '',
  shipping_address: { line1: '', city: '', governorate: '' },
})

const placing = ref(false)
const error = ref<string | null>(null)

onMounted(async () => {
  const { rails: available } = await $fetch<{ rails: typeof rails.value }>('/api/checkout/rails', {
    query: { host: useTenantHost() },
  })

  rails.value = available
  form.rail = available[0]?.rail ?? ''
})

async function place() {
  placing.value = true
  error.value = null

  try {
    const placed = await $fetch<PlacedOrder>('/api/checkout', {
      method: 'POST',
      query: { host: useTenantHost() },
      body: form,
    })

    // The cart is gone — the API deleted it — so the order page is now the
    // only thing that knows what was bought, and the email is how it is
    // addressed.
    cart.value = null

    await router.push({
      path: `/orders/${placed.order.number}`,
      query: { email: form.customer_email },
    })
  } catch (e: unknown) {
    error.value = messageFor(e)
  } finally {
    placing.value = false
  }
}

useSeoMeta({ title: 'Checkout' })
</script>

<template>
  <section class="checkout">
    <h1>Checkout</h1>

    <p v-if="!cart || !cart.items.length" class="checkout__empty">
      Your basket is empty. <NuxtLink to="/products">Have a look around.</NuxtLink>
    </p>

    <form v-else class="checkout__form" @submit.prevent="place">
      <p v-if="error" class="checkout__error" role="alert">{{ error }}</p>

      <fieldset class="checkout__group">
        <legend>Who is this for?</legend>

        <label>Full name<input v-model="form.customer_name" required autocomplete="name"></label>
        <label>Email<input v-model="form.customer_email" type="email" required autocomplete="email"></label>
        <!--
          Required on every rail, not only the offline ones: a reference
          payment has no other way to reach the payer with their code.
        -->
        <label>Phone<input v-model="form.customer_phone" required autocomplete="tel" placeholder="+20 10 0000 0000"></label>
      </fieldset>

      <fieldset class="checkout__group">
        <legend>Where should it go?</legend>

        <label>Address<input v-model="form.shipping_address.line1" required autocomplete="address-line1"></label>
        <label>City<input v-model="form.shipping_address.city" required autocomplete="address-level2"></label>
        <label>Governorate<input v-model="form.shipping_address.governorate" autocomplete="address-level1"></label>
      </fieldset>

      <fieldset class="checkout__group">
        <legend>How would you like to pay?</legend>

        <label v-for="rail in rails" :key="rail.rail" class="checkout__rail">
          <input v-model="form.rail" type="radio" :value="rail.rail" name="rail">
          <span>
            {{ rail.label }}
            <!-- Saying so up front is the difference between a shopper
                 expecting a receipt and one expecting a code to take to an
                 outlet. -->
            <small v-if="rail.offline">You will get a code to pay with. Your order is held until you do.</small>
          </span>
        </label>
      </fieldset>

      <aside class="checkout__summary">
        <p v-for="line in cart.items" :key="line.id">
          <span>{{ line.quantity }} × {{ line.product_name }}</span>
          <span>{{ format(line.total_cents, cart.currency) }}</span>
        </p>
        <p class="checkout__total">
          <strong>Total</strong>
          <strong>{{ format(cart.subtotal_cents, cart.currency) }}</strong>
        </p>
      </aside>

      <button type="submit" class="checkout__submit" :disabled="placing || !form.rail">
        {{ placing ? 'Placing your order…' : 'Place order' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.checkout { max-width: 34rem; }
.checkout__error { color: #b42318; }

.checkout__group {
  border: 1px solid color-mix(in srgb, var(--kavo-color-text) 15%, transparent);
  border-radius: 0.5rem;
  padding: 1rem 1.25rem 1.25rem;
  margin: 0 0 1.25rem;
}

.checkout__group legend { padding: 0 0.4rem; font-weight: 600; }

.checkout__group label {
  display: block;
  margin-top: 0.85rem;
  font-size: 0.9rem;
}

.checkout__group input[type='text'],
.checkout__group input[type='email'],
.checkout__group input:not([type]) {
  display: block;
  width: 100%;
  margin-top: 0.3rem;
  padding: 0.55rem 0.7rem;
  border: 1px solid color-mix(in srgb, var(--kavo-color-text) 25%, transparent);
  border-radius: 0.375rem;
  font: inherit;
  box-sizing: border-box;
}

.checkout__rail { display: flex; gap: 0.6rem; align-items: flex-start; }
.checkout__rail small { display: block; opacity: 0.7; }

.checkout__summary {
  border-top: 1px solid color-mix(in srgb, var(--kavo-color-text) 15%, transparent);
  padding-top: 1rem;
  margin-bottom: 1.25rem;
}

.checkout__summary p { display: flex; justify-content: space-between; margin: 0.4rem 0; }
.checkout__total { font-size: 1.125rem; }

.checkout__submit {
  width: 100%;
  padding: 0.85rem 1.5rem;
  border: 0;
  border-radius: 0.5rem;
  background: var(--kavo-color-primary);
  color: var(--kavo-color-surface);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.checkout__submit:disabled { opacity: 0.6; cursor: default; }
</style>
