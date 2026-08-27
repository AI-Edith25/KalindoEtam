/**
 * Centralized qty parsing/formatting/validation, driven by an Item's
 * qty_category (0 decimal places for 'unit', 2 for 'weight' — mirrors
 * App\Enums\QtyCategory::decimalPlaces() on the backend). Every line-item
 * form across Purchase + Inventory goes through this instead of ad-hoc
 * per-component Number.isInteger checks.
 */
export type QtyCategory = 'unit' | 'weight'

export function qtyDecimalPlaces(category: QtyCategory): number {
  return category === 'weight' ? 2 : 0
}

/** Accepts either ',' or '.' as the decimal separator before parsing — matches the ID locale input the user types. */
export function parseLocaleQty(value: string): number {
  return Number(value.replace(',', '.'))
}

function hasAtMostDecimalPlaces(value: number, decimalPlaces: number): boolean {
  const factor = 10 ** decimalPlaces
  return Math.abs(Math.round(value * factor) - value * factor) < 1e-6
}

/** True when `value` is valid for the given category — whole number for 'unit', at most `qtyDecimalPlaces(category)` decimals for 'weight'. */
export function isValidQtyForCategory(value: string, category: QtyCategory): boolean {
  const num = parseLocaleQty(value)
  if (Number.isNaN(num) || num < 0) return false
  return hasAtMostDecimalPlaces(num, qtyDecimalPlaces(category))
}

export function qtyErrorMessage(category: QtyCategory): string {
  return category === 'unit'
    ? 'Item ini dihitung per satuan. Masukkan bilangan bulat.'
    : 'Maksimal 2 angka desimal.'
}

const qtyFormatterCache = new Map<number, Intl.NumberFormat>()

function qtyFormatter(decimalPlaces: number): Intl.NumberFormat {
  let formatter = qtyFormatterCache.get(decimalPlaces)
  if (!formatter) {
    formatter = new Intl.NumberFormat('id-ID', { minimumFractionDigits: decimalPlaces, maximumFractionDigits: decimalPlaces })
    qtyFormatterCache.set(decimalPlaces, formatter)
  }
  return formatter
}

/** Formats qty per its category's decimal places — `20` for Unit, `50,65` for Weight. */
export function formatQty(value: string | number, category: QtyCategory): string {
  return qtyFormatter(qtyDecimalPlaces(category)).format(Number(value))
}
