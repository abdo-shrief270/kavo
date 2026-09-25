import type { Cart } from '~~/server/utils/cart'

/** Change a line's quantity. Zero removes it. */
export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)
  const id = getRouterParam(event, 'id')
  const body = await readBody(event)

  const { cart } = await cartRequest<{ cart: Cart }>(event, hostname, `/api/storefront/cart/items/${id}`, {
    method: 'PATCH',
    body: { quantity: Number(body?.quantity ?? 0) },
  })

  rememberCart(event, cart.token)

  return cart
})
