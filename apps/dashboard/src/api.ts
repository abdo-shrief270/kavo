import { createClient } from '@kavo/api-client'
import { useAuthStore } from './stores/auth'

/**
 * Tenant selection travels as a header on every request, and the API checks
 * it against the caller's memberships — naming a tenant is a request, not a
 * grant.
 */
export const api = createClient({
  baseUrl: import.meta.env.VITE_API_BASE ?? 'http://localhost:8000',
  tenantSlug: () => useAuthStore().currentTenant?.slug ?? null,
  onUnauthenticated: () => useAuthStore().clear(),
})
