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
/**
 * Base units (what stock is held in) per 1 of the line's chosen UOM — 1 when the line is in the
 * item's base UOM or hasn't picked one. `available_qty` is always base units, so the line's qty
 * must be scaled by this before comparing against it.
 */
export function lineUomFactor(line: Pick<SalesOrderEditorValues['items'][number], 'uom_id' | 'item_uoms'>): number {
  const choice = line.item_uoms?.find((c) => c.uom_id === line.uom_id)

  return choice ? Number(choice.conversion_factor) : 1
}

export function evaluateStockBlock(lines: SalesOrderEditorValues['items']): { blocked: boolean; message: string } {
  const messages = lines
    .filter((line) => line.item_id && line.available_qty !== undefined && line.available_qty !== '')
    .filter((line) => Number(line.qty) * lineUomFactor(line) > Number(line.available_qty))
    .map(
      (line) =>
        `Stok tidak mencukupi untuk item ${line.item_name || line.item_code}. Stok tersedia: ${formatNumber(Number(line.available_qty))}, diminta: ${formatNumber(Number(line.qty) * lineUomFactor(line))}.`,
    )

  return { blocked: messages.length > 0, message: messages.join(' ') }
}
