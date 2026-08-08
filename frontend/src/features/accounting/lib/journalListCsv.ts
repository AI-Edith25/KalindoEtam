import type { JournalListData } from '../types'

const HEADER = ['Group', 'Transaction', 'Date', 'Ref #', 'Particulars', 'Debit', 'Credit']

function escapeCsvField(value: string | number): string {
  const text = String(value)
  return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

function toRow(fields: (string | number)[]): string {
  return fields.map(escapeCsvField).join(',')
}

/** Mirrors exactly what's rendered on screen — built from the same already-fetched JournalListData, never a second fetch, so the CSV can't diverge from the report. */
export function buildJournalListCsv(data: JournalListData): string {
  const lines = [toRow(HEADER)]

  for (const group of data.groups) {
    lines.push(toRow([group.label, '', '', '', '', '', '']))
    for (const row of group.rows) {
      lines.push(toRow(['', row.transaction ?? '', row.date ?? '', row.ref_no ?? '', row.particulars, Number(row.debit), Number(row.credit)]))
    }
    lines.push(toRow([`Total For: ${group.label}`, '', '', '', '', Number(group.subtotal.debit), Number(group.subtotal.credit)]))
  }

  lines.push(toRow(['Grand Total', '', '', '', '', Number(data.grand_total.debit), Number(data.grand_total.credit)]))

  return lines.join('\r\n')
}

/** Native Blob + <a download> — no library, no server round-trip. */
export function downloadCsv(filename: string, content: string): void {
  const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
