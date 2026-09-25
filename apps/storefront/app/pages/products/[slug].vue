<script setup lang="ts">
import type { ProductDetail } from '~~/server/utils/catalogue'

const route = useRoute()
const { tenant } = useStorefront()
const { format } = useMoney()

const slug = route.params.slug as string

const { data: product } = await useFetch<ProductDetail>(`/api/products/${slug}`, {
  key: `product:${slug}`,
  query: { host: useTenantHost() },
})

if (!product.value) {
  throw createError({ statusCode: 404, statusMessage: 'No such product.', fatal: true })
}

/**
 * The shopper's current selection, one value per option axis. Starts on the
 * first variant that can actually be bought rather than simply the first —
 * landing on a sold-out size when other sizes are in stock reads as the whole
 * product being unavailable.
 */
const selection = ref<Record<string, string>>({
  ...(product.value.variants.find(v => v.in_stock) ?? product.value.variants[0])?.options,
})

const matches = (options: Record<string, string>) =>
  Object.entries(selection.value).every(([axis, value]) => options[axis] === value)

const selected = computed(() => product.value?.variants.find(v => matches(v.options)) ?? null)

const { add, pending, error } = useCart()
const added = ref(false)

async function addToCart() {
  if (!selected.value) return

  added.value = await add(selected.value.id)
}

// Changing size after adding should not leave a stale confirmation under the
// new selection.
watch(selected, () => { added.value = false })

useSeoMeta({
  title: () => product.value?.name ?? 'Product',
  description: () => product.value?.description ?? `From ${tenant.value?.name ?? 'this shop'}.`,
  ogTitle: () => product.value?.name ?? 'Product',
  ogImage: () => product.value?.images[0]?.url,
})
</script>

<template>
  <article v-if="product" class="product">
    <div class="product__gallery">
      <img
        v-for="image in product.images"
        :key="image.url"
        :src="image.url"
        :alt="image.alt"
        class="product__image"
      >
      <div v-if="!product.images.length" class="product__image product__image--empty" aria-hidden="true" />
    </div>

    <div class="product__detail">
      <h1>{{ product.name }}</h1>

      <p class="product__price">
        <template v-if="selected">
          {{ format(selected.price_cents, product.currency) }}
          <s v-if="selected.compare_at_price_cents" class="product__was">
            {{ format(selected.compare_at_price_cents, product.currency) }}
          </s>
        </template>
        <template v-else-if="product.from_price_cents !== null">
          from {{ format(product.from_price_cents, product.currency) }}
        </template>
      </p>

      <div v-for="axis in product.options" :key="axis.name" class="product__axis">
        <span class="product__axis-name">{{ axis.name }}</span>
        <div class="product__values">
          <button
            v-for="value in axis.values"
            :key="value"
            type="button"
            class="product__value"
            :class="{ 'product__value--on': selection[axis.name] === value }"
            :aria-pressed="selection[axis.name] === value"
            @click="selection[axis.name] = value"
          >
            {{ value }}
          </button>
        </div>
      </div>

      <p v-if="selected && !selected.in_stock" class="product__status">Sold out in this combination.</p>
      <p v-else-if="!selected" class="product__status">That combination is not available.</p>

      <button
        v-else
        type="button"
        class="product__add"
        :disabled="pending"
        @click="addToCart"
      >
        {{ pending ? 'Adding…' : 'Add to basket' }}
      </button>

      <!--
        The refusal the API gives back is the useful kind — "Only 2 left" —
        and is shown verbatim rather than flattened into a generic failure.
      -->
      <p v-if="error" class="product__status product__status--bad" role="alert">{{ error }}</p>
      <p v-else-if="added" class="product__status" role="status">
        Added. <NuxtLink to="/cart">View your basket.</NuxtLink>
      </p>

      <p v-if="product.description" class="product__description">{{ product.description }}</p>
    </div>
  </article>
</template>

<style scoped>
.product {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 2.5rem;
  align-items: start;
}

@media (max-width: 48rem) {
  .product { grid-template-columns: 1fr; }
}

.product__image {
  width: 100%;
  border-radius: 0.5rem;
  background: color-mix(in srgb, var(--kavo-color-text) 6%, transparent);
}

.product__image--empty { aspect-ratio: 3 / 4; }

.product__price {
  font-size: 1.25rem;
  font-weight: 600;
}

.product__was {
  font-weight: 400;
  opacity: 0.6;
  margin-left: 0.5rem;
}

.product__axis { margin: 1.25rem 0; }

.product__axis-name {
  display: block;
  font-size: 0.875rem;
  opacity: 0.7;
  margin-bottom: 0.5rem;
}

.product__values { display: flex; gap: 0.5rem; flex-wrap: wrap; }

.product__value {
  padding: 0.5rem 0.9rem;
  border: 1px solid color-mix(in srgb, var(--kavo-color-text) 25%, transparent);
  border-radius: 0.375rem;
  background: transparent;
  color: inherit;
  cursor: pointer;
  font: inherit;
}

.product__value--on {
  border-color: var(--kavo-color-primary);
  background: var(--kavo-color-primary);
  color: var(--kavo-color-surface);
}

.product__status { opacity: 0.75; }
.product__status--bad { color: #b42318; opacity: 1; }

.product__add {
  margin-top: 0.5rem;
  padding: 0.75rem 1.5rem;
  border: 0;
  border-radius: 0.5rem;
  background: var(--kavo-color-primary);
  color: var(--kavo-color-surface);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.product__add:disabled { opacity: 0.6; cursor: default; }

.product__description { line-height: 1.6; margin-top: 1.5rem; }
</style>
