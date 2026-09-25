<script setup lang="ts">
import type { Paginated, Product } from '@kavo/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api'
import { useMoney } from '../composables/useMoney'

const { format } = useMoney()

const products = ref<Product[]>([])
const total = ref(0)
const page = ref(1)
const lastPage = ref(1)
const search = ref('')
const status = ref('')
const loading = ref(false)

async function load() {
  loading.value = true

  try {
    const params = new URLSearchParams({ page: String(page.value) })
    if (search.value) params.set('q', search.value)
    if (status.value) params.set('status', status.value)

    const payload = await api.get<Paginated<Product>>(`/api/products?${params}`)

    products.value = payload.data
    total.value = payload.total
    lastPage.value = payload.last_page
  } finally {
    loading.value = false
  }
}

/**
 * Typing in a search box should not be one request per keystroke. Debounced
 * here rather than in the box, so the filter dropdown shares the behaviour.
 */
let timer: ReturnType<typeof setTimeout> | undefined

watch([search, status], () => {
  page.value = 1
  clearTimeout(timer)
  timer = setTimeout(load, 250)
})

watch(page, load)

/** The lowest active variant price — what a listing shows as "from". */
function fromPrice(product: Product): string {
  const prices = product.variants.filter((v) => v.is_active).map((v) => v.price_cents)

  return prices.length ? format(Math.min(...prices), product.currency) : '—'
}

/**
 * Available, not on hand. What a merchant needs to know is what they can still
 * sell, and unpaid orders are already holding some of it.
 */
function available(product: Product): number {
  return product.variants
    .filter((v) => v.track_inventory)
    .reduce((sum, v) => sum + Math.max(0, v.stock_on_hand - v.stock_reserved), 0)
}

function hasUntracked(product: Product): boolean {
  return product.variants.some((v) => !v.track_inventory)
}

const empty = computed(() => !loading.value && products.value.length === 0)

onMounted(load)
</script>

<template>
  <section>
    <header class="head">
      <h1>Products</h1>
      <RouterLink class="new" :to="{ name: 'product-new' }">New product</RouterLink>
    </header>

    <div class="filters">
      <input v-model="search" type="search" placeholder="Search by name" aria-label="Search products" />
      <select v-model="status" aria-label="Filter by status">
        <option value="">All statuses</option>
        <option value="active">Active</option>
        <option value="draft">Draft</option>
        <option value="archived">Archived</option>
      </select>
    </div>

    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Status</th>
          <th>From</th>
          <th>Variants</th>
          <th>Available</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="product in products" :key="product.id">
          <td>
            <RouterLink :to="{ name: 'product-edit', params: { id: product.id } }">{{ product.name }}</RouterLink>
            <small class="slug">/{{ product.slug }}</small>
          </td>
          <td><span class="pill" :class="`pill--${product.status}`">{{ product.status }}</span></td>
          <td>{{ fromPrice(product) }}</td>
          <td>{{ product.variants.length }}</td>
          <td>
            {{ available(product) }}
            <small v-if="hasUntracked(product)" class="slug">+ untracked</small>
          </td>
        </tr>

        <tr v-if="empty">
          <td colspan="5">
            Nothing in the catalogue yet.
            <RouterLink :to="{ name: 'product-new' }">Add your first product.</RouterLink>
          </td>
        </tr>
      </tbody>
    </table>

    <nav v-if="lastPage > 1" class="pager">
      <button :disabled="page <= 1" @click="page--">Previous</button>
      <span>Page {{ page }} of {{ lastPage }} · {{ total }} products</span>
      <button :disabled="page >= lastPage" @click="page++">Next</button>
    </nav>
  </section>
</template>

<style scoped>
.head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; }

.new {
  background: var(--accent);
  color: var(--bg);
  text-decoration: none;
  padding: 0.5rem 0.9rem;
  border-radius: 6px;
  font-size: 0.9rem;
}

.filters { display: flex; gap: 0.75rem; margin-bottom: 1rem; }
.filters input { max-width: 18rem; }
.filters select { max-width: 11rem; }

.slug { display: block; color: var(--muted); font-size: 0.8rem; }

.pill {
  display: inline-block;
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  font-size: 0.78rem;
  border: 1px solid var(--line);
  text-transform: capitalize;
}

.pill--active { border-color: #16a34a; color: #16a34a; }
.pill--archived { color: var(--muted); }

.pager { display: flex; align-items: center; gap: 1rem; margin-top: 1rem; font-size: 0.85rem; color: var(--muted); }
.pager button { background: none; border: 1px solid var(--line); color: inherit; padding: 0.35rem 0.75rem; border-radius: 6px; cursor: pointer; }
.pager button:disabled { opacity: 0.5; cursor: default; }
</style>
