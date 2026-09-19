import { formatNumber } from '@/lib/utils'
import type { SalesOrderEditorValues } from './salesOrderFormSchema'

/**
 * Pure, client-side stock check — mirrors evaluateCreditBlock()'s shape. Each line's
 * `available_qty` is denormalized onto it at item-selection time (see SalesOrderLineItemTable's
 * handleItemChange), so this needs no network call of its own; recomputed on every render, same
 * "no extra request per keystroke" posture the credit check already has. The server independently
 * re-computes and enforces the authoritative version on every create/update/approve — this is a
 * preview, never the source of truth.
 */
export function evaluateStockBlock(lines: SalesOrderEditorValues['items']): { blocked: boolean; message: string } {
  const messages = lines
    .filter((line) => line.item_id && line.available_qty !== undefined && line.available_qty !== '')
    .filter((line) => Number(line.qty) > Number(line.available_qty))
    .map(
      (line) =>
        `Stok tidak mencukupi untuk item ${line.item_name || line.item_code}. Stok tersedia: ${formatNumber(Number(line.available_qty))}, diminta: ${formatNumber(Number(line.qty))}.`,
    )

  return { blocked: messages.length > 0, message: messages.join(' ') }
}
