import type { ReactNode } from 'react'

/**
 * One label/":"/value block for print layouts — a real `<table>` with `table-layout: auto` (the
 * default), so the label column auto-sizes to whichever label in THIS block is widest,
 * guaranteeing the ":" never touches any label regardless of content, with no pre-measured width
 * needed. Extracted from InvoicePortraitLayout.tsx (BUG 1 there) so Delivery print can reuse the
 * same aligned label/value pattern instead of re-implementing it with manual spacing.
 */
export function PrintMetaTable({
  rows,
  size,
}: {
  /** `size` on a row overrides the table-level default for that row only — e.g. Sales Order's own
      NO row prints at 10.08pt while every other row in the same table (Date, Reference 1 #, ...)
      stays at 9.36pt; keeping them in one `<table>` (rather than a separate mini-table just for
      NO) is what keeps every row's ":" on one shared vertical line. */
  rows: { label: string; value: ReactNode; bold?: boolean; valueBold?: boolean; size?: number }[]
  size: number
}) {
  return (
    <table style={{ borderCollapse: 'collapse' }}>
      <tbody>
        {rows.map((row) => {
          const rowSize = row.size ?? size
          return (
            <tr key={row.label}>
              <td
                style={{
                  whiteSpace: 'nowrap',
                  textAlign: 'left',
                  verticalAlign: 'top',
                  paddingRight: '1.5mm',
                  fontSize: `${rowSize}pt`,
                  fontWeight: row.bold ? 700 : 400,
                }}
              >
                {row.label}
              </td>
              <td style={{ whiteSpace: 'nowrap', textAlign: 'left', verticalAlign: 'top', fontSize: `${rowSize}pt`, fontWeight: row.bold ? 700 : 400 }}>:</td>
              <td
                style={{
                  textAlign: 'left',
                  verticalAlign: 'top',
                  paddingLeft: '1.5mm',
                  fontSize: `${rowSize}pt`,
                  fontWeight: (row.valueBold ?? row.bold) ? 700 : 400,
                }}
              >
                {row.value}
              </td>
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}
