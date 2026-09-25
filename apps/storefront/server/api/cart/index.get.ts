import type { Cart } from '~~/server/utils/cart'

/**
 * The shopper's basket.
 *
 * The API issues a fresh token whenever the one presented is missing or has
 * expired, so the cookie is re-stamped on every read — otherwise a shopper
 * whose cart aged out would keep presenting a token that names nothing and
 * would silently start a new cart on every request.
 */
export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)
  const { cart } = await cartRequest<{ cart: Cart }>(event, hostname, '/api/storefront/cart')

  rememberCart(event, cart.token)

  return cart
})
