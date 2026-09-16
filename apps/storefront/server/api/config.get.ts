/**
 * Server route the client uses for the tenant's config, so the browser never
 * needs to know the API's address or reach it directly.
 */
export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event)

  return await getStorefrontConfig(hostname, event)
})
