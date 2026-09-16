import { createClient } from '@kavo/api-client'
import { useAdminStore } from './stores/admin'

/**
 * No tenant header, ever.
 *
 * The platform console reads across tenants by design, so it must not carry
 * a tenant identity that could narrow — or worse, appear to authorise — a
 * request. Authorisation is is_platform_admin server side, and every
 * cross-tenant read it performs is written to the audit log.
 */
export const api = createClient({
  baseUrl: import.meta.env.VITE_API_BASE ?? 'http://localhost:8000',
  onUnauthenticated: () => useAdminStore().clear(),
})
