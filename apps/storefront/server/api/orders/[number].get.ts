import type { Order, CustomerAction } from '~~/server/utils/cart'

/**
 * The "where is my order" page.
 *
 * Offline rails mean a customer comes back to this days after placing the
 * order, long after any cart token has gone — so it is addressed by order
 * number plus the email it was placed with, and never cached.
 */
export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)
  const number = getRouterParam(event, 'number')
  const { email } = getQuery(event)

  const { apiBase } = useRuntimeConfig(event)

  try {
    return await $fetch<{ order: Order; customer_action: CustomerAction }>(
      `/api/storefront/orders/${encodeURIComponent(String(number))}`,
      {
        baseURL: apiBase,
        headers: apiHeaders(event, hostname),
        query: { email },
      },
    )
  } catch (e: unknown) {
    throw upstreamError(e)
  }
})
