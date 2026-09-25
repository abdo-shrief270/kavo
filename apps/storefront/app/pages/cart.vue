<script setup lang="ts">
const { cart, pending, error, refresh, setQuantity, remove } = useCart()
const { format } = useMoney()

// Client-rendered (see routeRules): the basket is personal, and a cached or
// prerendered one is another shopper's.
await refresh()

useSeoMeta({ title: 'Your basket' })
</script>

<template>
  <section class="cart">
    <h1>Your basket</h1>

    <p v-if="error" class="cart__error" role="alert">{{ error }}</p>

    <p v-if="!cart || !cart.items.length" class="cart__empty">
      Nothing here yet. <NuxtLink to="/products">Have a look around.</NuxtLink>
    </p>

    <template v-else>
      <ul class="cart__lines">
        <li v-for="line in cart.items" :key="line.id" class="cart__line">
          <div class="cart__what">
            <NuxtLink :to="`/products/${line.product_slug}`" class="cart__name">{{ line.product_name }}</NuxtLink>
            <small class="cart__options">
              {{ Object.entries(line.options).map(([axis, value]) => `${axis}: ${value}`).join(' · ') }}
            </small>
            <!-- Availability is published per line so a shopper is told what
                 changed here, rather than being declined at checkout with no
                 explanation of which item was the problem. -->
            <small v-if="!line.available" class="cart__gone">No longer available in this quantity.</small>
          </div>

          <div class="cart__quantity">
            <button type="button" :disabled="pending" @click="setQuantity(line.id, line.quantity - 1)">−</button>
            <span>{{ line.quantity }}</span>
            <button type="button" :disabled="pending" @click="setQuantity(line.id, line.quantity + 1)">+</button>
          </div>

          <div class="cart__money">
            <strong>{{ format(line.total_cents, cart.currency) }}</strong>
            <button type="button" class="cart__remove" :disabled="pending" @click="remove(line.id)">Remove</button>
          </div>
        </li>
      </ul>

      <div class="cart__foot">
        <p class="cart__subtotal">
          <span>Subtotal</span>
          <strong>{{ format(cart.subtotal_cents, cart.currency) }}</strong>
        </p>
        <NuxtLink v-if="cart.checkout_ready" to="/checkout" class="cart__go">Checkout</NuxtLink>
        <p v-else class="cart__blocked">Adjust the lines above before checking out.</p>
      </div>
    </template>
  </section>
</template>

<style scoped>
.cart { max-width: 44rem; }
.cart__error { color: #b42318; }
.cart__lines { list-style: none; padding: 0; margin: 1.5rem 0; }

.cart__line {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 1rem;
  align-items: center;
  padding: 1rem 0;
  border-bottom: 1px solid color-mix(in srgb, var(--kavo-color-text) 12%, transparent);
}

.cart__name { color: inherit; font-weight: 600; text-decoration: none; }
.cart__options { display: block; opacity: 0.65; }
.cart__gone { display: block; color: #b42318; }

.cart__quantity { display: flex; align-items: center; gap: 0.6rem; }

.cart__quantity button {
  width: 2rem;
  height: 2rem;
  border: 1px solid color-mix(in srgb, var(--kavo-color-text) 25%, transparent);
  border-radius: 0.375rem;
  background: transparent;
  color: inherit;
  cursor: pointer;
  font: inherit;
}

.cart__money { text-align: right; }

.cart__remove {
  display: block;
  margin-top: 0.35rem;
  border: 0;
  background: none;
  color: inherit;
  opacity: 0.6;
  cursor: pointer;
  font: inherit;
  padding: 0;
}

.cart__subtotal { display: flex; justify-content: space-between; font-size: 1.125rem; }

.cart__go {
  display: inline-block;
  padding: 0.75rem 1.5rem;
  border-radius: 0.5rem;
  background: var(--kavo-color-primary);
  color: var(--kavo-color-surface);
  text-decoration: none;
  font-weight: 600;
}

.cart__blocked { opacity: 0.75; }
</style>
