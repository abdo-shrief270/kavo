/**
 * The hostname this render is for.
 *
 * Nitro's routes identify the tenant by hostname, and during SSR they cannot
 * discover it themselves: a page's internal call to /api/* arrives as
 * localhost, and the render event carries no host header at all — not through
 * a forwarded header, not through useRequestFetch, not through
 * useRequestHeaders or useRequestURL. All of those were tried.
 *
 * A Nitro middleware captures it at the edge, where it is available, and the
 * render reads it back off the request context. In the browser the address
 * bar is the answer.
 */
export function useTenantHost(): string {
  if (import.meta.server) {
    return (useRequestEvent()?.context.tenantHost as string | undefined) ?? ''
  }

  return window.location.hostname
}
