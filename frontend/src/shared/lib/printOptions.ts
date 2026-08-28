export type PrintFontSize = 'small' | 'medium' | 'large'
export type PrintPaperType = 'a4' | 'continuous' | 'half'

export interface PrintOptions {
  fontSize: PrintFontSize
  paperType: PrintPaperType
  qtyDecimals: number
  priceDecimals: number
  amountDecimals: number
  /** Only Invoice print acts on this (renders the Total Discount line) — every other consumer leaves it unset, same convention as paperType/showPaperType below. */
  showDiscount?: boolean
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
  half: 'Half (148 × 210mm)',
}

/** Only Continuous/Half get an explicit @page override — A4 relies on the browser/printer default, exactly like before this option existed. */
export const PRINT_PAPER_PAGE_CSS: Record<PrintPaperType, string | null> = {
  a4: null,
  continuous: '@page { size: 9.5in 11in; margin: 6mm; }',
  half: '@page { size: 148mm 210mm; margin: 6mm; }',
}

const PRINT_PAPER_TYPE_STORAGE_KEY = 'print-paper-type'

/** Per-user preference, not per-document — so a chosen paper type sticks across invoices/deliveries without re-selecting each time. */
export function loadPaperTypePreference(): PrintPaperType {
  return localStorage.getItem(PRINT_PAPER_TYPE_STORAGE_KEY) === 'continuous' ? 'continuous' : 'a4'
}

export function savePaperTypePreference(paperType: PrintPaperType): void {
  localStorage.setItem(PRINT_PAPER_TYPE_STORAGE_KEY, paperType)
}

/**
 * Invoice print's own paper-type preference — deliberately a SEPARATE key
 * from PRINT_PAPER_TYPE_STORAGE_KEY above. That key is shared with
 * Incoming/Outgoing Payment print, neither of which has any layout support
 * for 'half'; if Invoice wrote 'half' into the shared key, a Payment print
 * opened afterward would silently inherit an unsupported paper size. Same
 * load/save-preference pattern, just scoped to the one page that supports
 * the full 'a4' | 'continuous' | 'half' range.
 */
const INVOICE_PRINT_PAPER_TYPE_STORAGE_KEY = 'print-paper-type-invoice'

export function loadInvoicePaperTypePreference(): PrintPaperType {
  const stored = localStorage.getItem(INVOICE_PRINT_PAPER_TYPE_STORAGE_KEY)
  return stored === 'continuous' || stored === 'half' ? stored : 'a4'
}

export function saveInvoicePaperTypePreference(paperType: PrintPaperType): void {
  localStorage.setItem(INVOICE_PRINT_PAPER_TYPE_STORAGE_KEY, paperType)
}

const PRINT_SHOW_DISCOUNT_STORAGE_KEY = 'print-show-discount'

/** Same per-user-preference reasoning as loadPaperTypePreference — Invoice print only, see PrintOptions.showDiscount. */
export function loadShowDiscountPreference(): boolean {
  return localStorage.getItem(PRINT_SHOW_DISCOUNT_STORAGE_KEY) === '1'
}

export function saveShowDiscountPreference(showDiscount: boolean): void {
  localStorage.setItem(PRINT_SHOW_DISCOUNT_STORAGE_KEY, showDiscount ? '1' : '0')
}

export const PRINT_FONT_SIZE_PX: Record<PrintFontSize, string> = {
  small: '11px',
  medium: '13px',
  large: '15px',
}

/** "font default lebih kecil" for Half — 2px under the A4/Continuous map at every tier, same three fontSize choices still apply. */
export const PRINT_FONT_SIZE_PX_HALF: Record<PrintFontSize, string> = {
  small: '9px',
  medium: '11px',
  large: '13px',
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
