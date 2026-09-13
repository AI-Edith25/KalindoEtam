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
  rows: { label: string; value: ReactNode; bold?: boolean; valueBold?: boolean }[]
  size: number
}) {
  return (
    <table style={{ borderCollapse: 'collapse' }}>
      <tbody>
        {rows.map((row) => (
          <tr key={row.label}>
            <td
              style={{
                whiteSpace: 'nowrap',
                textAlign: 'left',
                verticalAlign: 'top',
                paddingRight: '1.5mm',
                fontSize: `${size}pt`,
                fontWeight: row.bold ? 700 : 400,
              }}
            >
              {row.label}
            </td>
            <td style={{ whiteSpace: 'nowrap', textAlign: 'left', verticalAlign: 'top', fontSize: `${size}pt`, fontWeight: row.bold ? 700 : 400 }}>:</td>
            <td
              style={{
                textAlign: 'left',
                verticalAlign: 'top',
                paddingLeft: '1.5mm',
                fontSize: `${size}pt`,
                fontWeight: (row.valueBold ?? row.bold) ? 700 : 400,
              }}
            >
              {row.value}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
