import type { Cart } from '~~/server/utils/cart'

/**
 * The shopper's basket, shared across the app.
 *
 * Loaded on the client only. The cart token is an httpOnly cookie on this
 * origin, and a browser attaches it automatically; an SSR render does not
 * forward it unless every call site remembers to, and a cart that silently
 * renders empty during SSR and fills in after hydration is worse than one that
 * is honestly absent until loaded.
 *
 * Every mutation returns the whole cart rather than a patch, so the displayed
 * totals are always the ones the server computed — the cart is priced live
 * from the catalogue, and a client-side subtotal would drift from it the
 * moment a merchant changed a price.
 */
export function useCart() {
  const cart = useState<Cart | null>('cart', () => null)
  const pending = useState<boolean>('cart-pending', () => false)
  const error = useState<string | null>('cart-error', () => null)

  const count = computed(() => cart.value?.item_count ?? 0)

  async function call(request: () => Promise<Cart>): Promise<boolean> {
    pending.value = true
    error.value = null

    try {
      cart.value = await request()

      return true
    } catch (e: unknown) {
      error.value = messageFor(e)

      return false
    } finally {
      pending.value = false
    }
  }

  const query = () => ({ host: useTenantHost() })

  const refresh = () => call(() => $fetch<Cart>('/api/cart', { query: query() }))

  const add = (variantId: number, quantity = 1) =>
    call(() => $fetch<Cart>('/api/cart/items', { method: 'POST', query: query(), body: { variant_id: variantId, quantity } }))

  const setQuantity = (lineId: number, quantity: number) =>
    call(() => $fetch<Cart>(`/api/cart/items/${lineId}`, { method: 'PATCH', query: query(), body: { quantity } }))

  const remove = (lineId: number) =>
    call(() => $fetch<Cart>(`/api/cart/items/${lineId}`, { method: 'DELETE', query: query() }))

  return { cart, count, pending, error, refresh, add, setQuantity, remove }
}

/**
 * Surface what the API actually said.
 *
 * Validation failures here are the useful kind — "Only 2 left", "That item is
 * no longer for sale" — and collapsing them into "Something went wrong" tells
 * a shopper nothing they can act on.
 *
 * Two shapes, because there are two hops. The API answers with
 * `{ message, errors }`; Nitro then re-reports that from the server route with
 * the upstream body nested under `data`. Reading only one of the two means the
 * message survives locally and disappears in production, or the reverse.
 */
export function messageFor(e: unknown): string {
  interface Failure { message?: string; errors?: Record<string, string[]>; data?: Failure }

  const body = (e as { data?: Failure })?.data
  const upstream = body?.data ?? body

  const named = Object.values(upstream?.errors ?? {})[0]?.[0]

  return named ?? upstream?.message ?? body?.message ?? 'Something went wrong. Please try again.'
}
