<script setup lang="ts">
import { ApiError, type Product, type ProductStatus, type QuotaDetail } from '@kavo/api-client'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { useMoney } from '../composables/useMoney'

const route = useRoute()
const router = useRouter()
const { toCents, toInput } = useMoney()

const productId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isNew = computed(() => productId.value === null)

/** An axis as the form holds it. Values are typed as one comma-separated list. */
interface Axis {
  name: string
  valuesText: string
}

/**
 * A variant row. Price is kept as the string the merchant typed and converted
 * once on save — converting on every keystroke means "8.9" becomes 890 while
 * they are still typing the 9.
 */
interface Row {
  id: number | null
  sku: string
  options: Record<string, string>
  signature: string
  price: string
  compareAt: string
  trackInventory: boolean
  stockOnHand: number
  stockReserved: number
  isActive: boolean
}

const form = reactive({
  name: '',
  slug: '',
  description: '',
  status: 'draft' as ProductStatus,
  currency: 'EGP',
})

const axes = ref<Axis[]>([])
const rows = ref<Row[]>([])
const saving = ref(false)
const loading = ref(true)
const message = ref<string | null>(null)
const blocked = ref<QuotaDetail | null>(null)
const errors = ref<Record<string, string[]>>({})

/**
 * The same rule the server uses to decide whether two variants mean the same
 * thing: sorted, case-folded, `axis:value` joined.
 *
 * Duplicated deliberately rather than fetched, because it is only used here to
 * decide which existing row a regenerated combination carries forward. The
 * server recomputes it from the options it is sent and remains the authority —
 * if this drifts, the worst case is a 422 naming the duplicate, not silent
 * stock loss.
 */
function signatureFor(options: Record<string, string>): string {
  return Object.entries(options)
    .map(([axis, value]) => `${axis.trim().toLowerCase()}:${value.trim().toLowerCase()}`)
    .sort()
    .join('|')
}

function axisValues(axis: Axis): string[] {
  return axis.valuesText
    .split(',')
    .map((value) => value.trim())
    .filter((value) => value !== '')
}

/** Every combination the declared axes imply, in display order. */
function combinations(): Record<string, string>[] {
  const declared = axes.value.filter((axis) => axis.name.trim() !== '' && axisValues(axis).length > 0)

  if (declared.length === 0) return []

  return declared.reduce<Record<string, string>[]>(
    (acc, axis) => acc.flatMap((combo) => axisValues(axis).map((value) => ({ ...combo, [axis.name.trim()]: value }))),
    [{}],
  )
}

/**
 * Rebuild the variant table from the axes, carrying forward everything the
 * merchant already set for combinations that still exist.
 *
 * Matching on signature rather than position is what makes adding a size in
 * the middle of the list safe: without it, every row below would inherit the
 * price and SKU of its neighbour.
 */
function generate() {
  const existing = new Map(rows.value.map((row) => [row.signature, row]))

  rows.value = combinations().map((options, index) => {
    const signature = signatureFor(options)
    const carried = existing.get(signature)

    return carried
      ? { ...carried, options }
      : {
          id: null,
          sku: suggestSku(options, index),
          options,
          signature,
          price: rows.value[0]?.price ?? '',
          compareAt: '',
          trackInventory: true,
          stockOnHand: 0,
          stockReserved: 0,
          isActive: true,
        }
  })
}

/** A starting point the merchant will mostly keep, from the name and options. */
function suggestSku(options: Record<string, string>, index: number): string {
  const base = form.name.trim().slice(0, 6).toUpperCase().replace(/[^A-Z0-9]/g, '') || 'SKU'
  const suffix = Object.values(options).map((v) => v.slice(0, 3).toUpperCase()).join('-')

  return suffix ? `${base}-${suffix}` : `${base}-${index + 1}`
}

/** Rows dropped by a regeneration that were still holding stock. */
const willLoseStock = computed(() => {
  const wanted = new Set(combinations().map(signatureFor))

  return rows.value.filter((row) => row.id !== null && !wanted.has(row.signature) && row.stockOnHand > 0)
})

function hydrate(product: Product) {
  form.name = product.name
  form.slug = product.slug
  form.description = product.description ?? ''
  form.status = product.status
  form.currency = product.currency

  axes.value = product.options.map((axis) => ({ name: axis.name, valuesText: axis.values.join(', ') }))

  rows.value = product.variants.map((variant) => ({
    id: variant.id,
    sku: variant.sku,
    options: variant.options,
    signature: variant.option_signature,
    price: toInput(variant.price_cents),
    compareAt: toInput(variant.compare_at_price_cents),
    trackInventory: variant.track_inventory,
    stockOnHand: variant.stock_on_hand,
    stockReserved: variant.stock_reserved,
    isActive: variant.is_active,
  }))
}

async function load() {
  if (isNew.value) {
    // A starting axis, but no rows yet. Generating here would build them
    // before the product has a name, and the SKUs suggested from an empty
    // name are then carried forward by every later rebuild — which is how
    // "Cotton Tee" ended up with SKU-S and SKU-M.
    axes.value = [{ name: 'Size', valuesText: 'S, M, L' }]
    loading.value = false

    return
  }

  const { product } = await api.get<{ product: Product }>(`/api/products/${productId.value}`)
  hydrate(product)
  loading.value = false
}

function payload() {
  return {
    name: form.name,
    ...(form.slug ? { slug: form.slug } : {}),
    description: form.description || null,
    status: form.status,
    currency: form.currency,
    options: axes.value
      .filter((axis) => axis.name.trim() !== '' && axisValues(axis).length > 0)
      .map((axis) => ({ name: axis.name.trim(), values: axisValues(axis) })),
    variants: rows.value.map((row, index) => ({
      sku: row.sku,
      options: row.options,
      price_cents: toCents(row.price) ?? 0,
      compare_at_price_cents: toCents(row.compareAt),
      track_inventory: row.trackInventory,
      is_active: row.isActive,
      position: index,
      // Only on create. Afterwards stock moves through receiving and through
      // orders, and a figure typed into this form twenty minutes ago would
      // overwrite every sale made since — which is why the API refuses it.
      ...(isNew.value ? { stock_on_hand: row.stockOnHand } : {}),
    })),
  }
}

async function save() {
  saving.value = true
  message.value = null
  blocked.value = null
  errors.value = {}

  try {
    if (isNew.value) {
      const { product } = await api.post<{ product: Product }>('/api/products', payload())
      await router.replace({ name: 'product-edit', params: { id: product.id } })
      hydrate(product)
      message.value = 'Product created.'
    } else {
      const { product } = await api.patch<{ product: Product }>(`/api/products/${productId.value}`, payload())
      hydrate(product)
      message.value = 'Saved.'
    }
  } catch (error) {
    if (error instanceof ApiError && error.isQuotaExceeded) {
      // 402, not a mistake the merchant made — they may do this on a larger
      // plan, so it is an upgrade prompt rather than a red error.
      blocked.value = error.quota ?? null
    } else if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors
      message.value = error.message
    } else {
      throw error
    }
  } finally {
    saving.value = false
  }
}

/**
 * Stock is changed on its own endpoint, immediately, never as part of saving
 * the form. The form does not own stock and should not look as though it does.
 */
async function adjust(row: Row, delta: number) {
  if (row.id === null) return

  try {
    const { variant } = await api.patch<{ variant: { stock_on_hand: number; stock_reserved: number } }>(
      `/api/products/${productId.value}/variants/${row.id}/stock`,
      { adjust: delta, reason: delta > 0 ? 'Received' : 'Adjusted down' },
    )

    row.stockOnHand = variant.stock_on_hand
    row.stockReserved = variant.stock_reserved
    message.value = null
  } catch (error) {
    message.value = error instanceof ApiError ? error.message : 'Could not change the stock.'
  }
}

async function destroy() {
  if (!confirm(`Remove ${form.name}? Orders already placed keep their own record of it.`)) return

  await api.delete(`/api/products/${productId.value}`)
  await router.push({ name: 'products' })
}

function addAxis() {
  axes.value.push({ name: '', valuesText: '' })
}

function removeAxis(index: number) {
  axes.value.splice(index, 1)
}

function errorFor(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

onMounted(load)
</script>

<template>
  <section v-if="!loading">
    <header class="head">
      <h1>{{ isNew ? 'New product' : form.name || 'Product' }}</h1>
      <RouterLink class="back" :to="{ name: 'products' }">Back to products</RouterLink>
    </header>

    <div v-if="blocked" class="upgrade">
      <strong>You have reached your product limit.</strong>
      <p>
        {{ blocked.used }} of {{ blocked.limit }} products used. Remove one, or move to a plan with
        room for more.
      </p>
    </div>

    <p v-if="message" class="notice">{{ message }}</p>

    <form @submit.prevent="save">
      <div class="grid">
        <div class="field">
          <label for="name">Name</label>
          <input id="name" v-model="form.name" required />
          <p v-if="errorFor('name')" class="error">{{ errorFor('name') }}</p>
        </div>

        <div class="field">
          <label for="status">Status</label>
          <select id="status" v-model="form.status">
            <option value="draft">Draft — not on the storefront</option>
            <option value="active">Active — on sale</option>
            <option value="archived">Archived — kept, not sold</option>
          </select>
        </div>

        <div class="field">
          <label for="slug">URL</label>
          <input id="slug" v-model="form.slug" :placeholder="isNew ? 'From the name' : ''" />
          <p v-if="errorFor('slug')" class="error">{{ errorFor('slug') }}</p>
        </div>

        <div class="field">
          <label for="currency">Currency</label>
          <input id="currency" v-model="form.currency" maxlength="3" />
        </div>
      </div>

      <div class="field">
        <label for="description">Description</label>
        <textarea id="description" v-model="form.description" rows="4"></textarea>
      </div>

      <h2>Options</h2>
      <p class="hint">
        The ways this product varies. One shirt in three sizes and two colours is six variants, and
        each one carries its own price and stock.
      </p>

      <div v-for="(axis, index) in axes" :key="index" class="axis">
        <div class="field">
          <label :for="`axis-${index}`">Name</label>
          <input :id="`axis-${index}`" v-model="axis.name" placeholder="Size" />
        </div>
        <div class="field axis__values">
          <label :for="`values-${index}`">Values, separated by commas</label>
          <input :id="`values-${index}`" v-model="axis.valuesText" placeholder="S, M, L" />
        </div>
        <button type="button" class="link" @click="removeAxis(index)">Remove</button>
      </div>

      <div class="axis-actions">
        <button v-if="axes.length < 3" type="button" @click="addAxis">Add an option</button>
        <button type="button" @click="generate">Rebuild variants from options</button>
      </div>

      <p v-if="willLoseStock.length" class="warn">
        Rebuilding drops
        {{ willLoseStock.map((r) => `${r.sku} (${r.stockOnHand} in stock)`).join(', ') }}.
        Those units stop being sellable.
      </p>

      <h2>Variants</h2>
      <p v-if="errorFor('variants')" class="error">{{ errorFor('variants') }}</p>

      <table>
        <thead>
          <tr>
            <th>Options</th>
            <th>SKU</th>
            <th>Price</th>
            <th>Compare at</th>
            <th>Stock</th>
            <th>On sale</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in rows" :key="row.signature">
            <td>
              <span class="combo">{{ Object.entries(row.options).map(([a, v]) => `${a}: ${v}`).join(' · ') || '—' }}</span>
              <p v-if="errorFor(`variants.${index}.options`)" class="error">
                {{ errorFor(`variants.${index}.options`) }}
              </p>
            </td>
            <td>
              <input v-model="row.sku" class="cell" :aria-label="`SKU for row ${index + 1}`" />
              <!-- A SKU is unique per shop, and reusing one is an ordinary
                   mistake. The server names the row; so does this. -->
              <p v-if="errorFor(`variants.${index}.sku`)" class="error">
                {{ errorFor(`variants.${index}.sku`) }}
              </p>
            </td>
            <td><input v-model="row.price" class="cell cell--num" inputmode="decimal" :aria-label="`Price for row ${index + 1}`" /></td>
            <td><input v-model="row.compareAt" class="cell cell--num" inputmode="decimal" :aria-label="`Compare-at price for row ${index + 1}`" /></td>

            <td>
              <!-- On create the form carries the opening figure; afterwards
                   stock belongs to receiving and to orders, so it is shown
                   rather than edited. -->
              <input
                v-if="isNew"
                v-model.number="row.stockOnHand"
                type="number"
                min="0"
                class="cell cell--num"
                :aria-label="`Opening stock for row ${index + 1}`"
              />
              <template v-else-if="row.trackInventory">
                <div class="stock">
                  <button type="button" @click="adjust(row, -1)" aria-label="Remove one">−</button>
                  <span>
                    {{ row.stockOnHand }}
                    <small v-if="row.stockReserved">{{ row.stockReserved }} held</small>
                  </span>
                  <button type="button" @click="adjust(row, 1)" aria-label="Add one">+</button>
                  <button type="button" class="link" @click="adjust(row, 10)">+10</button>
                </div>
              </template>
              <span v-else class="combo">Not tracked</span>
            </td>

            <td><input v-model="row.isActive" type="checkbox" :aria-label="`On sale, row ${index + 1}`" /></td>
          </tr>

          <tr v-if="!rows.length">
            <td colspan="6">Declare an option above, then rebuild — or a product with one variant needs one row.</td>
          </tr>
        </tbody>
      </table>

      <div class="actions">
        <button class="primary" type="submit" :disabled="saving || !rows.length">
          {{ saving ? 'Saving…' : isNew ? 'Create product' : 'Save changes' }}
        </button>
        <button v-if="!isNew" type="button" class="danger" @click="destroy">Remove product</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; }
.back { color: var(--muted); font-size: 0.875rem; }

h2 { font-size: 1rem; margin: 1.75rem 0 0.35rem; }
.hint { color: var(--muted); font-size: 0.85rem; margin-top: 0; }

.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
textarea { width: 100%; padding: 0.5rem; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); font: inherit; }

.axis { display: grid; grid-template-columns: 10rem 1fr auto; gap: 0.75rem; align-items: end; margin-bottom: 0.5rem; }
.axis__values { min-width: 0; }
.axis-actions { display: flex; gap: 0.5rem; margin: 0.75rem 0 1rem; }
.axis-actions button { background: none; border: 1px solid var(--line); color: inherit; padding: 0.4rem 0.8rem; border-radius: 6px; cursor: pointer; font: inherit; }

.cell { padding: 0.35rem 0.4rem; font-size: 0.875rem; }
.cell--num { max-width: 7rem; text-align: right; }
.combo { font-size: 0.85rem; color: var(--muted); }

.stock { display: flex; align-items: center; gap: 0.4rem; }
.stock button { width: 1.6rem; height: 1.6rem; border: 1px solid var(--line); background: none; color: inherit; border-radius: 4px; cursor: pointer; line-height: 1; }
.stock small { display: block; color: var(--muted); font-size: 0.72rem; }

.link { border: 0; background: none; color: var(--muted); cursor: pointer; font: inherit; text-decoration: underline; padding: 0; width: auto; }

.actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }
.danger { background: none; border: 1px solid #dc2626; color: #dc2626; padding: 0.55rem 1rem; border-radius: 6px; cursor: pointer; }

.notice { color: #16a34a; font-size: 0.875rem; }
.warn { color: #b45309; font-size: 0.875rem; }

.upgrade {
  border: 1px solid #f59e0b;
  background: color-mix(in srgb, #f59e0b 10%, transparent);
  border-radius: 8px;
  padding: 0.85rem;
  margin-bottom: 1rem;
}

.upgrade p { margin: 0.35rem 0 0; font-size: 0.875rem; }

@media (max-width: 720px) {
  .grid { grid-template-columns: 1fr; }
  .axis { grid-template-columns: 1fr; }
}
</style>
