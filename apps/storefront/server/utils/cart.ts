import type { H3Event } from 'h3'

export interface CartLine {
  id: number
  variant_id: number
  product_name: string
  product_slug: string
  sku: string
  options: Record<string, string>
  unit_price_cents: number
  quantity: number
  total_cents: number
  available: boolean
}

export interface Cart {
  token: string
  currency: string
  items: CartLine[]
  item_count: number
  subtotal_cents: number
  checkout_ready: boolean
}

export interface OrderLine {
  product_name: string
  sku: string
  options: Record<string, string>
  unit_price_cents: number
  quantity: number
  total_cents: number
}

export interface Order {
  number: number
  reference: string
  status: string
  status_label: string
  currency: string
  subtotal_cents: number
  shipping_cents: number
  total_cents: number
  placed_at: string
  paid_at: string | null
  items: OrderLine[]
}

export type CustomerAction =
  | { type: 'redirect'; url: string }
  | { type: 'reference'; reference: string; expires_at: string | null }
  | null

export interface PlacedOrder {
  order: Order
  customer_action: CustomerAction
}

/**
 * A cart call, never cached.
 *
 * Deliberately not a defineCachedFunction like the catalogue: a cached cart is
 * one shopper's basket served to another, which is the same class of mistake
 * as a cache key missing the hostname — and unlike a stale price it cannot be
 * corrected by waiting.
 */
export async function cartRequest<T>(
  event: H3Event,
  hostname: string,
  path: string,
  options: { method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'; body?: Record<string, unknown> } = {},
): Promise<T> {
  const { apiBase } = useRuntimeConfig(event)

  try {
    // Cast because $fetch types its own return from Nitro's internal route
    // map, which knows nothing about the API on the other side of this call.
    return (await $fetch(path, {
      baseURL: apiBase,
      method: options.method ?? 'GET',
      body: options.body,
      headers: cartHeaders(event, hostname),
    })) as T
  } catch (e: unknown) {
    // Without this the API's refusal is replaced by a bare "Server Error" and
    // the shopper is told nothing they can act on.
    throw upstreamError(e)
  }
}
