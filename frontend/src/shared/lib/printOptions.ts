export type PrintFontSize = 'small' | 'medium' | 'large'

export interface PrintOptions {
  fontSize: PrintFontSize
  qtyDecimals: number
  priceDecimals: number
  amountDecimals: number
}

/** Matches the pre-existing print output exactly (formatNumber/formatCurrency both rendered 0 decimals) so opening this dialog is opt-in, never a silent format change. */
export const defaultPrintOptions: PrintOptions = {
  fontSize: 'medium',
  qtyDecimals: 0,
  priceDecimals: 0,
  amountDecimals: 0,
}

export const PRINT_FONT_SIZE_PX: Record<PrintFontSize, string> = {
  small: '11px',
  medium: '13px',
  large: '15px',
}

export const PRINT_FONT_SIZE_LABELS: Record<PrintFontSize, string> = {
  small: 'Small',
  medium: 'Medium',
  large: 'Large',
}

/** Plain number, no currency symbol — for Qty columns. */
export function formatQty(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** Rupiah-formatted — for Unit Price / Amount columns and totals. */
export function formatMoney(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(Number(value))
}
