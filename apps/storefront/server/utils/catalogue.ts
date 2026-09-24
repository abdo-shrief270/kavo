import type { H3Event } from 'h3'

export interface ProductSummary {
  slug: string
  name: string
  currency: string
  from_price_cents: number | null
  in_stock: boolean
  images: { url: string; alt: string }[]
}

export interface ProductVariant {
  id: number
  sku: string
  options: Record<string, string>
  price_cents: number
  compare_at_price_cents: number | null
  in_stock: boolean
}

export interface ProductDetail extends ProductSummary {
  description: string | null
  options: { name: string; values: string[] }[]
  variants: ProductVariant[]
}

export interface ProductPage {
  products: ProductSummary[]
  meta: { total: number; per_page: number; current_page: number; last_page: number }
}

/**
 * The catalogue listing.
 *
 * Every argument that changes the answer is in the cache key — hostname first,
 * because serving one tenant's catalogue to another is the frontend twin of a
 * missing tenant scope, but the search term and page matter too: a key without
 * them hands a shopper browsing page one the results of someone else's search.
 */
export const getCatalogue = defineCachedFunction(
  async (hostname: string, query: string, page: number, event: H3Event): Promise<ProductPage> => {
    const { apiBase, internalToken } = useRuntimeConfig(event)
    const requestId = String(event.context.requestId ?? '')

    return await $fetch<ProductPage>('/api/storefront/products', {
      baseURL: apiBase,
      // X-Forwarded-Host, not Host: Node's fetch treats Host as a forbidden
      // header and drops it silently, so the API would see the loopback
      // address and resolve no tenant at all. The API trusts this header only
      // from its configured proxies.
      headers: {
        'X-Forwarded-Host': hostname,
      // Identifies this call as the storefront's own and carries the render's
      // correlation id, so the API's logs join up with this one's.
        'X-Internal-Token': internalToken,
        'X-Request-Id': requestId,
      },
      query: { q: query || undefined, page },
    })
  },
  {
    name: 'storefront-catalogue',
    maxAge: 60,
    staleMaxAge: 300,
    getKey: (hostname: string, query: string, page: number) => `${hostname}:${query}:${page}`,
  },
)

/** One product. Keyed on hostname and slug, for the same reason. */
export const getProduct = defineCachedFunction(
  async (hostname: string, slug: string, event: H3Event): Promise<ProductDetail> => {
    const { apiBase, internalToken } = useRuntimeConfig(event)
    const requestId = String(event.context.requestId ?? '')

    // The API wraps a single resource in an envelope; the listing is not
    // wrapped the same way. Unwrapped here so pages never have to know which
    // endpoint returns which shape.
    const { product } = await $fetch<{ product: ProductDetail }>(`/api/storefront/products/${encodeURIComponent(slug)}`, {
      baseURL: apiBase,
      // X-Forwarded-Host, not Host: Node's fetch treats Host as a forbidden
      // header and drops it silently, so the API would see the loopback
      // address and resolve no tenant at all. The API trusts this header only
      // from its configured proxies.
      headers: {
        'X-Forwarded-Host': hostname,
      // Identifies this call as the storefront's own and carries the render's
      // correlation id, so the API's logs join up with this one's.
        'X-Internal-Token': internalToken,
        'X-Request-Id': requestId,
      },
    })

    return product
  },
  {
    name: 'storefront-product',
    maxAge: 60,
    staleMaxAge: 300,
    getKey: (hostname: string, slug: string) => `${hostname}:${slug}`,
  },
)
