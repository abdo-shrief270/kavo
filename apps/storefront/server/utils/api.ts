import type { H3Event } from 'h3'

/**
 * The cart token lives in a cookie on *this* origin, not the API's.
 *
 * The browser only ever talks to the storefront, which is same-origin, so the
 * cookie needs no cross-site relaxation and can stay httpOnly — script on the
 * page never reads it. This server translates it into the X-Cart-Token header
 * the API expects, which keeps the only credential a shopper has out of
 * JavaScript's reach.
 */
export const CART_COOKIE = 'kavo_cart'

const CART_COOKIE_DAYS = 30

/**
 * Headers every call to the API carries.
 *
 * Gathered in one place because there are now six call sites and each one
 * getting them subtly wrong is how the storefront spent a slice rendering
 * nobody's shop.
 */
export function apiHeaders(event: H3Event, hostname: string, extra: Record<string, string> = {}) {
  const { internalToken } = useRuntimeConfig(event)

  return {
    // X-Forwarded-Host, not Host: Node's fetch treats Host as a forbidden
    // header and drops it silently, so the API would see the loopback address
    // and resolve no tenant at all. The API trusts this header only from its
    // configured proxies.
    'X-Forwarded-Host': hostname,
    // Identifies this call as the storefront's own and carries the render's
    // correlation id, so the API's logs join up with this one's.
    'X-Internal-Token': internalToken,
    'X-Request-Id': String(event.context.requestId ?? ''),
    ...extra,
  }
}

export function cartToken(event: H3Event): string {
  return getCookie(event, CART_COOKIE) ?? ''
}

/** Headers for a cart-bearing call. Omits the token header when there is none. */
export function cartHeaders(event: H3Event, hostname: string) {
  const token = cartToken(event)

  return apiHeaders(event, hostname, token ? { 'X-Cart-Token': token } : {})
}

export function rememberCart(event: H3Event, token: unknown): void {
  if (typeof token !== 'string' || !token) return

  setCookie(event, CART_COOKIE, token, {
    httpOnly: true,
    sameSite: 'lax',
    /*
     | Secure exactly when the connection is, read from X-Forwarded-Proto
     | behind the proxy that terminates TLS.
     |
     | Not `!import.meta.dev`: that is the *build* mode, not the connection.
     | A production build served over plain http then sets Secure, the browser
     | never sends the cookie back, and every request quietly starts a new
     | empty basket — which is how this was found.
     */
    secure: getRequestProtocol(event) === 'https',
    path: '/',
    maxAge: CART_COOKIE_DAYS * 24 * 60 * 60,
  })
}

/** The basket has become an order; the token no longer refers to anything. */
export function forgetCart(event: H3Event): void {
  deleteCookie(event, CART_COOKIE, { path: '/' })
}

/**
 * Hand the API's refusal on, instead of replacing it with "Server Error".
 *
 * An error thrown out of an event handler is reported by Nitro as a generic
 * 500/4xx with no body — so "Only 2 left", "That item is out of stock" and
 * "Your basket mixes currencies" all reach the shopper as nothing at all.
 * Those are the useful refusals: they name the one line to fix.
 *
 * Only the status and the API's own JSON body travel. Anything else about the
 * upstream call — its URL, its headers, the internal token it carried — stays
 * on this side of the boundary.
 */
export function upstreamError(e: unknown): Error {
  const failure = e as {
    statusCode?: number
    status?: number
    data?: { message?: string; errors?: Record<string, string[]>; out_of_stock?: unknown }
  }

  const statusCode = failure.statusCode ?? failure.status ?? 502

  // A 5xx from the API is ours, not the shopper's, and its text is for logs.
  const upstream = statusCode < 500 ? failure.data : undefined

  return createError({
    statusCode,
    statusMessage: upstream?.message ?? 'The shop could not complete that request.',
    data: upstream ?? { message: 'The shop could not complete that request.' },
  })
}
