/**
 * Adds `days` to a `YYYY-MM-DD` date string, returning a `YYYY-MM-DD` string.
 * Built from local Date getters/setters, never `toISOString()` — this app's
 * data is Rupiah/Jakarta (UTC+7), and `toISOString()` silently shifts the
 * calendar date backward by a day in any timezone ahead of UTC (the exact
 * bug already hit and fixed in Trial Balance's period-preset resolver).
 */
export function addDays(dateStr: string, days: number): string {
  const [year, month, day] = dateStr.split('-').map(Number)
  const date = new Date(year, month - 1, day)
  date.setDate(date.getDate() + days)

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
