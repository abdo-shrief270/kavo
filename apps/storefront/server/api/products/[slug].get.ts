export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)
  const slug = getRouterParam(event, 'slug')

  if (!slug) {
    throw createError({ statusCode: 404, statusMessage: 'No such product.' })
  }

  try {
    return await getProduct(hostname, slug, event)
  } catch {
    // A draft or another tenant's product is a 404 here as well: the API
    // already refuses to say which, and neither should this.
    throw createError({ statusCode: 404, statusMessage: 'No such product.' })
  }
})
