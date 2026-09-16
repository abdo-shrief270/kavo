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
      headers: { Host: hostname },
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

export function hostnameFrom(event: H3Event): string {
  const host = getRequestHeader(event, 'x-forwarded-host') ?? getRequestHeader(event, 'host') ?? ''

  return host.split(':')[0]!.toLowerCase()
}
