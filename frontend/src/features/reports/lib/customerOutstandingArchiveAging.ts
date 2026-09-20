import type { CustomerOutstandingArchiveCustomerGroup, CustomerOutstandingArchiveLine } from '../types'

export const AGING_BUCKETS = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'over_90'] as const
export type AgingBucketKey = (typeof AGING_BUCKETS)[number]

export const AGING_BUCKET_LABELS: Record<AgingBucketKey, string> = {
  not_due: 'Belum Jatuh Tempo',
  d1_30: '1-30 Hari',
  d31_60: '31-60 Hari',
  d61_90: '61-90 Hari',
  over_90: '> 90 Hari',
}

export interface AgingListRow extends CustomerOutstandingArchiveLine {
  customer_code: string
  customer_name: string
}

/**
 * Bucketed straight from the snapshot's own Overdue (Days) column -- never recomputed against
 * today's real date, so a snapshot dated 30 Sep 2026 produces the same buckets whenever it's
 * opened. A row with overdue_days <= 0 (or the file left it blank, normalized to 0 on import)
 * is "Belum Jatuh Tempo".
 */
export function bucketForOverdueDays(overdueDays: number): AgingBucketKey {
  if (overdueDays <= 0) return 'not_due'
  if (overdueDays <= 30) return 'd1_30'
  if (overdueDays <= 60) return 'd31_60'
  if (overdueDays <= 90) return 'd61_90'
  return 'over_90'
}

export function flattenToAgingRows(customers: CustomerOutstandingArchiveCustomerGroup[]): AgingListRow[] {
  return customers.flatMap((customer) =>
    customer.rows.map((row) => ({ ...row, customer_code: customer.customer_code, customer_name: customer.customer_name })),
  )
}

export interface AgingBucketGroup {
  key: AgingBucketKey
  label: string
  rows: AgingListRow[]
  subtotal: number
}

export function groupByAgingBucket(rows: AgingListRow[]): AgingBucketGroup[] {
  return AGING_BUCKETS.map((key) => {
    const bucketRows = rows.filter((row) => bucketForOverdueDays(row.overdue_days) === key)
    return { key, label: AGING_BUCKET_LABELS[key], rows: bucketRows, subtotal: bucketRows.reduce((sum, row) => sum + row.unpaid_amount, 0) }
  }).filter((bucket) => bucket.rows.length > 0)
}
