export type PrintFontSize = 'small' | 'medium' | 'large'
export type PrintPaperType = 'a4' | 'continuous'

export interface PrintOptions {
  fontSize: PrintFontSize
  paperType: PrintPaperType
  qtyDecimals: number
  priceDecimals: number
  amountDecimals: number
}

/** Matches the pre-existing print output exactly (formatNumber/formatCurrency both rendered 0 decimals, A4/browser-default paper) so opening this dialog is opt-in, never a silent format change. */
export const defaultPrintOptions: PrintOptions = {
  fontSize: 'medium',
  paperType: 'a4',
  qtyDecimals: 0,
  priceDecimals: 0,
  amountDecimals: 0,
}

export const PRINT_PAPER_TYPE_LABELS: Record<PrintPaperType, string> = {
  a4: 'A4',
  continuous: 'Continuous 9.5" × 11" (Dot Matrix)',
}

/** Only Continuous gets an explicit @page override — A4 relies on the browser/printer default, exactly like before this option existed. */
export const PRINT_PAPER_PAGE_CSS: Record<PrintPaperType, string | null> = {
  a4: null,
  continuous: '@page { size: 9.5in 11in; margin: 6mm; }',
}

const PRINT_PAPER_TYPE_STORAGE_KEY = 'print-paper-type'

/** Per-user preference, not per-document — so a chosen paper type sticks across invoices/deliveries without re-selecting each time. */
export function loadPaperTypePreference(): PrintPaperType {
  return localStorage.getItem(PRINT_PAPER_TYPE_STORAGE_KEY) === 'continuous' ? 'continuous' : 'a4'
}

export function savePaperTypePreference(paperType: PrintPaperType): void {
  localStorage.setItem(PRINT_PAPER_TYPE_STORAGE_KEY, paperType)
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
