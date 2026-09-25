import type { PlacedOrder } from '~~/server/utils/cart'

/**
 * Place the order.
 *
 * The cart cookie is cleared on success because the API deletes the cart the
 * token names — the order is the durable record and the thing to retry payment
 * against. Leaving the cookie would have the next page silently open a second,
 * empty cart under a token that no longer resolves.
 */
export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)
  const body = await readBody(event)

  const placed = await cartRequest<PlacedOrder>(event, hostname, '/api/storefront/checkout', {
    method: 'POST',
    body: {
      customer_name: body?.customer_name,
      customer_email: body?.customer_email,
      customer_phone: body?.customer_phone,
      rail: body?.rail,
      shipping_address: body?.shipping_address,
    },
  })

  forgetCart(event)

  return placed
})
