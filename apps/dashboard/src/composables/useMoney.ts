/**
 * Prices are integer minor units everywhere — piastres, cents — and are only
 * ever divided at the edge, here. A float that has been through arithmetic is
 * not a price anyone should be asked to pay, and a merchant's catalogue is
 * exactly where a half-piastre drift compounds.
 */
export function useMoney() {
  const format = (cents: number | null | undefined, currency = 'EGP'): string => {
    if (cents === null || cents === undefined) return ''

    return new Intl.NumberFormat('en-EG', { style: 'currency', currency }).format(cents / 100)
  }

  /** What goes in a price field: "899.00", not "89900". */
  const toInput = (cents: number | null | undefined): string =>
    cents === null || cents === undefined ? '' : (cents / 100).toFixed(2)

  /**
   * And back. Rounded rather than truncated, because 8.99 * 100 is
   * 898.9999999999999 in IEEE 754 and a merchant who typed 8.99 did not mean
   * 8.98.
   */
  const toCents = (input: string): number | null => {
    const trimmed = input.trim()
    if (trimmed === '') return null

    const value = Number(trimmed)

    return Number.isFinite(value) && value >= 0 ? Math.round(value * 100) : null
  }

  return { format, toInput, toCents }
}
