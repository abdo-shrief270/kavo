/**
 * The catalogue, proxied so the browser never needs the API's address.
 */
export default defineEventHandler(async (event) => {
  const hostname = hostnameFrom(event, getQuery(event).host)
  const { q, page } = getQuery(event)

  return await getCatalogue(
    hostname,
    typeof q === 'string' ? q : '',
    Number.parseInt(String(page ?? '1'), 10) || 1,
    event,
  )
})
