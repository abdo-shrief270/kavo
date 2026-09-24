<script setup lang="ts">
import type { ProductPage } from '~~/server/utils/catalogue'

const route = useRoute()
const { tenant } = useStorefront()
const { format } = useMoney()

const query = computed(() => (route.query.q as string) ?? '')
const page = computed(() => Number.parseInt(String(route.query.page ?? '1'), 10) || 1)

const { data, status } = await useFetch<ProductPage>('/api/products', {
  // Refetches when the search or page changes, and keys the payload so two
  // different searches are not served from one cached render.
  query: { q: query, page, host: useTenantHost() },
  key: () => `catalogue:${query.value}:${page.value}`,
})

useSeoMeta({
  title: 'Shop',
  description: () => `Browse everything from ${tenant.value?.name ?? 'this shop'}.`,
})
</script>

<template>
  <section>
    <h1>Shop</h1>

    <p v-if="status === 'pending'">Loading…</p>

    <p v-else-if="!data?.products.length" class="catalogue__empty">
      <template v-if="query">Nothing matched “{{ query }}”.</template>
      <template v-else>This shop has not published anything yet.</template>
    </p>

    <ul v-else class="catalogue">
      <li v-for="product in data.products" :key="product.slug" class="catalogue__item">
        <NuxtLink :to="`/products/${product.slug}`" class="catalogue__link">
          <img
            v-if="product.images[0]"
            :src="product.images[0].url"
            :alt="product.images[0].alt"
            class="catalogue__image"
            loading="lazy"
          >
          <div v-else class="catalogue__image catalogue__image--empty" aria-hidden="true" />

          <h2 class="catalogue__name">{{ product.name }}</h2>

          <p class="catalogue__price">
            <template v-if="product.from_price_cents !== null">
              from {{ format(product.from_price_cents, product.currency) }}
            </template>
          </p>

          <p v-if="!product.in_stock" class="catalogue__sold-out">Sold out</p>
        </NuxtLink>
      </li>
    </ul>

    <nav v-if="data && data.meta.last_page > 1" class="catalogue__pages">
      <NuxtLink v-if="page > 1" :to="{ query: { q: query || undefined, page: page - 1 } }">Previous</NuxtLink>
      <span>Page {{ data.meta.current_page }} of {{ data.meta.last_page }}</span>
      <NuxtLink v-if="page < data.meta.last_page" :to="{ query: { q: query || undefined, page: page + 1 } }">Next</NuxtLink>
    </nav>
  </section>
</template>

<style scoped>
.catalogue {
  list-style: none;
  padding: 0;
  margin: 1.5rem 0 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
  gap: 1.5rem;
}

.catalogue__link {
  color: inherit;
  text-decoration: none;
  display: block;
}

.catalogue__image {
  width: 100%;
  aspect-ratio: 3 / 4;
  object-fit: cover;
  border-radius: 0.5rem;
  background: color-mix(in srgb, var(--kavo-color-text) 6%, transparent);
}

.catalogue__name {
  font-size: 1rem;
  font-weight: 600;
  margin: 0.75rem 0 0.25rem;
}

.catalogue__price,
.catalogue__sold-out {
  margin: 0;
  font-size: 0.9375rem;
  opacity: 0.75;
}

.catalogue__pages {
  display: flex;
  gap: 1rem;
  align-items: center;
  margin-top: 2rem;
}
</style>
