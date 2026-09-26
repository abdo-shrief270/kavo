/**
 * Money is integer minor units everywhere and is only ever divided at the
 * edge, here. A float that has been through arithmetic is not a sum anyone
 * should be paid.
 */
export function useMoney() {
  const format = (cents: number | null | undefined, currency = 'EGP'): string => {
    if (cents === null || cents === undefined) return ''

    return new Intl.NumberFormat('en-EG', { style: 'currency', currency }).format(cents / 100)
  }

  /** What an administrator types into a payout field: "990.00", not "99000". */
  const toCents = (input: string): number | null => {
    const trimmed = input.trim()
    if (trimmed === '') return null

    const value = Number(trimmed)

    // Rounded, because 8.99 * 100 is 898.9999999999999 in IEEE 754 and
    // nobody meant 8.98.
    return Number.isFinite(value) && value >= 0 ? Math.round(value * 100) : null
  }

  return { format, toCents }
}
