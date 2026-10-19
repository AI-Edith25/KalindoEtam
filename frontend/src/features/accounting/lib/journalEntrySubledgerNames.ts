import type { JournalEntry } from '../types'

/** De-duplicated Customer/Vendor names across an entry's lines, for a listing column. */
export function subledgerNames(entry: JournalEntry): string {
  const names = new Set<string>()
  for (const line of entry.lines ?? []) {
    if (line.customer?.name) names.add(line.customer.name)
    if (line.supplier?.name) names.add(line.supplier.name)
  }
  return names.size > 0 ? [...names].join(', ') : '—'
}
