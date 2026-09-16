import { ApiError } from './types'

export interface ClientOptions {
  baseUrl: string
  /** Sent as X-Tenant so the API resolves the workspace the user picked. */
  tenantSlug?: () => string | null
  onUnauthenticated?: () => void
}

/**
 * Thin fetch wrapper for Sanctum's cookie session.
 *
 * Cookies rather than bearer tokens: both SPAs are first-party origins, so
 * there is nothing to store in localStorage for an XSS to steal, and CSRF
 * protection comes with the session. The cost is the CSRF handshake below.
 */
export function createClient(options: ClientOptions) {
  let csrfReady = false

  const url = (path: string) => `${options.baseUrl.replace(/\/$/, '')}/${path.replace(/^\//, '')}`

  /**
   * Sanctum needs the XSRF-TOKEN cookie before any state-changing request.
   * Fetched once per session, and again after a 419 — which is what a rotated
   * or expired token looks like.
   */
  const ensureCsrf = async () => {
    if (csrfReady) return

    await fetch(url('/sanctum/csrf-cookie'), { credentials: 'include' })
    csrfReady = true
  }

  const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

    return match ? decodeURIComponent(match[1]!) : ''
  }

  const request = async <T>(method: string, path: string, body?: unknown, retry = true): Promise<T> => {
    const mutating = method !== 'GET'

    if (mutating) await ensureCsrf()

    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    }

    if (body !== undefined) headers['Content-Type'] = 'application/json'
    if (mutating) headers['X-XSRF-TOKEN'] = xsrfToken()

    const tenant = options.tenantSlug?.()
    if (tenant) headers['X-Tenant'] = tenant

    const response = await fetch(url(path), {
      method,
      credentials: 'include',
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })

    if (response.ok) {
      return response.status === 204 ? (undefined as T) : ((await response.json()) as T)
    }

    // A stale CSRF token is recoverable and shouldn't surface to the user as
    // a failure — refresh it once and replay the request.
    if (response.status === 419 && retry) {
      csrfReady = false
      await ensureCsrf()

      return request<T>(method, path, body, false)
    }

    const payload = await response.json().catch(() => ({}))

    if (response.status === 401) options.onUnauthenticated?.()

    throw new ApiError(response.status, payload.message ?? response.statusText, payload.errors ?? {})
  }

  return {
    get: <T>(path: string) => request<T>('GET', path),
    post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
    put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
    patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
    delete: <T>(path: string) => request<T>('DELETE', path),
  }
}

export type ApiClient = ReturnType<typeof createClient>
