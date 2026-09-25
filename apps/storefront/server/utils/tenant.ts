import type { H3Event } from 'h3'

export interface StorefrontConfig {
  tenant: { name: string; slug: string; product: string }
  theme: { code: string; version: string; sections: unknown[] } | null
  design_tokens: Record<string, Record<string, string>>
  settings: Record<string, unknown>
}

/**
 * Resolves the tenant for the incoming request from its Host header, which is
 * how the API identifies a storefront too.
 *
 * The cache key MUST include the hostname. A cached handler keyed without it
 * serves one tenant's storefront to another — the frontend twin of a missing
 * tenant scope, and just as unrecoverable once it has happened.
 */
export const getStorefrontConfig = defineCachedFunction(
  async (hostname: string, event: H3Event): Promise<StorefrontConfig> => {
    const { apiBase } = useRuntimeConfig(event)

    return await $fetch<StorefrontConfig>('/api/storefront/config', {
      baseURL: apiBase,
      headers: apiHeaders(event, hostname),
    })
  },
  {
    name: 'storefront-config',
    maxAge: 60,
    staleMaxAge: 300,
    // The hostname is the whole key. Do not "simplify" this.
    getKey: (hostname: string) => hostname,
  },
)

const LOOPBACK = ['', 'localhost', '127.0.0.1', '::1']

/**
 * Which tenant's shop this request is for.
 *
 * Normally the Host header, which is the tenant's public identity. But an SSR
 * render calls these same routes in-process, and the visitor's Host does not
 * survive that hop — the request arrives as localhost, having tried headers
 * and Nuxt's own forwarding helpers, neither of which carried it reliably.
 *
 * So the caller may declare the host, and is believed only when the request
 * looks internal. A browser cannot produce a request to this server with a
 * loopback Host, so the override is unreachable from outside the box.
 */
export function hostnameFrom(event: H3Event, declared?: unknown): string {
  const header = (getRequestHeader(event, 'x-forwarded-host') ?? getRequestHeader(event, 'host') ?? '')
    .split(':')[0]!
    .toLowerCase()

  if (LOOPBACK.includes(header) && typeof declared === 'string' && declared) {
    return declared.split(':')[0]!.toLowerCase()
  }

  return header
}
