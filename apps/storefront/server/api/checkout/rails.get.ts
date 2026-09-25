/** Which payment rails this shop can actually be paid through. */
export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)

  return await cartRequest<{ rails: { rail: string; label: string; offline: boolean }[] }>(
    event,
    hostname,
    '/api/storefront/checkout/rails',
  )
})
