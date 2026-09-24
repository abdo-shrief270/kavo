/**
 * Records which tenant's hostname this request is for, once, at the edge.
 *
 * This is the only place the visitor's Host is reliably available. The Vue
 * render's own event carries no host header at all, and Nitro's internal
 * fetches — the calls a page makes to /api/* during SSR — arrive as localhost.
 * So the host is captured here and carried on the request context, which the
 * render can read.
 */
export default defineEventHandler((event) => {
  const host = getRequestHeader(event, 'x-forwarded-host') ?? getRequestHeader(event, 'host') ?? ''

  event.context.tenantHost = host.split(':')[0]!.toLowerCase()

  // One id for the whole render, carried into every API call it makes, so a
  // page and the requests behind it share a trace instead of producing
  // several unrelated ones. Minted here rather than accepted from the
  // visitor, who has no business choosing it.
  event.context.requestId ??= `render-${crypto.randomUUID()}`
})
