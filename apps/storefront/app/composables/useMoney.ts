/**
 * Prices are integer minor units everywhere — cents, piastres — and are only
 * ever divided at the edge, here. A float that has been through arithmetic is
 * not a price anyone should be asked to pay.
 */
export function useMoney() {
  const format = (cents: number | null | undefined, currency = 'EGP') => {
    if (cents === null || cents === undefined) return ''

    return new Intl.NumberFormat('en-EG', {
      style: 'currency',
      currency,
      maximumFractionDigits: 2,
    }).format(cents / 100)
  }

  return { format }
}
