import type { Cart } from '~~/server/utils/cart'

export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)
  const id = getRouterParam(event, 'id')

  const { cart } = await cartRequest<{ cart: Cart }>(event, hostname, `/api/storefront/cart/items/${id}`, {
    method: 'DELETE',
  })

  rememberCart(event, cart.token)

  return cart
})
