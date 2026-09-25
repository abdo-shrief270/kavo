import type { Cart } from '~~/server/utils/cart'

/** Add a variant to the basket, creating one if this visitor has none. */
export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)
  const body = await readBody(event)

  const { cart } = await cartRequest<{ cart: Cart }>(event, hostname, '/api/storefront/cart/items', {
    method: 'POST',
    body: { variant_id: body?.variant_id, quantity: body?.quantity ?? 1 },
  })

  rememberCart(event, cart.token)

  return cart
})
