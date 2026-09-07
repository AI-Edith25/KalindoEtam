export type DateRangePreset = 'today' | 'this_week' | 'this_month' | 'last_month' | 'custom'

export const DATE_RANGE_PRESET_LABELS: Record<DateRangePreset, string> = {
  today: 'Hari ini',
  this_week: 'Minggu ini',
  this_month: 'Bulan ini',
  last_month: 'Bulan lalu',
  custom: 'Custom',
}

/**
 * Local calendar date, not `.toISOString().slice(0, 10)` — that converts to UTC first, which
 * silently shifts the date back a day for any timezone ahead of UTC (e.g. WIB, UTC+7) whenever
 * the Date was built at local midnight (as "this_month"/"last_month" below do) — "1 September"
 * becomes "31 August". Reading the local Y/M/D components directly avoids the UTC round-trip.
 */
function toIsoDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/** Monday-start week, matching Indonesian convention. */
function startOfWeek(date: Date): Date {
  const result = new Date(date)
  const day = (result.getDay() + 6) % 7 // 0 = Monday
  result.setDate(result.getDate() - day)
  return result
}

/** date_from/date_to for a quick preset — 'custom' has no fixed range, callers keep whatever the user typed. */
export function dateRangeForPreset(preset: Exclude<DateRangePreset, 'custom'>): { date_from: string; date_to: string } {
  const now = new Date()

  switch (preset) {
    case 'today':
      return { date_from: toIsoDate(now), date_to: toIsoDate(now) }
    case 'this_week':
      return { date_from: toIsoDate(startOfWeek(now)), date_to: toIsoDate(now) }
    case 'this_month':
      return { date_from: toIsoDate(new Date(now.getFullYear(), now.getMonth(), 1)), date_to: toIsoDate(now) }
    case 'last_month': {
      const lastMonthStart = new Date(now.getFullYear(), now.getMonth() - 1, 1)
      const lastMonthEnd = new Date(now.getFullYear(), now.getMonth(), 0)
      return { date_from: toIsoDate(lastMonthStart), date_to: toIsoDate(lastMonthEnd) }
    }
  }
}
