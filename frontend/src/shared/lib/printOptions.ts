export type PrintFontSize = 'small' | 'medium' | 'large'
/**
 * 'roll' only has layout support in Invoice print — every other consumer's own `paperTypeOptions`
 * list simply never offers it, same convention as 'half' already being Invoice-only. 'letter' is
 * Payment Voucher print-only, same reasoning.
 *
 * 'dotmatrix_half' — opt-in only (Delivery Order + Invoice, see their own `paperTypeOptions`
 * arrays): same physical layout as 'half' but never emits a second page, for printers whose own
 * paper size Chrome uses instead of `@page size` (a real stakeholder's dot-matrix setup). See
 * DOTMATRIX_HALF_DEFAULTS below for its tunable numbers.
 */
export type PrintPaperType = 'a4' | 'continuous' | 'half' | 'roll' | 'letter' | 'dotmatrix_half'

/** Font Style dropdown choices — shared by PrintOptionsDialog (print time) and the admin PrintSettingsDialog (features/administration), which edits the same choices for another user. */
export const FONT_FAMILY_OPTIONS = [
  { value: '"Times New Roman", "Tinos", "Liberation Serif", serif', label: 'Times New Roman' },
  { value: 'Arial, Helvetica, sans-serif', label: 'Arial' },
  { value: '"Courier New", "Cutive Mono", monospace', label: 'Courier New' },
  // Invoice print's own exact-replica default (see InvoiceLandscapeLayout) — listed explicitly so
  // it's a real selectable choice, not just an invisible fallback.
  { value: '"DejaVu Sans Condensed", sans-serif', label: 'DejaVu Sans Condensed' },
] as const

export interface PrintOptions {
  fontSize: PrintFontSize
  paperType: PrintPaperType
  qtyDecimals: number
  priceDecimals: number
  amountDecimals: number
  /** Only Invoice print acts on this (renders the DISC line in the totals block) — every other consumer leaves it unset, same convention as paperType/showPaperType below. */
  showDiscount?: boolean
  /** Only Invoice print acts on this (HCTax column + TAX line in the totals block) — every other consumer leaves it unset, same convention as showDiscount. */
  showTax?: boolean
  /** Only Invoice print acts on this (2 vs 0 decimals in the totals block) — every other consumer leaves it unset, same convention as showDiscount. */
  showDecimalTotals?: boolean
  /** Only Invoice print acts on this (Font Family dropdown) — every other consumer leaves it unset, same convention as showDiscount. */
  fontFamily?: string
  /** Only Invoice print acts on this (editable signature block labels, both default to "AUTHORISED SIGNATURE") — every other consumer leaves it unset, same convention as showDiscount. */
  signatureLeftLabel?: string
  signatureRightLabel?: string
  /** Only meaningful when paperType === 'dotmatrix_half' (Delivery/Invoice print only) — see DOTMATRIX_HALF_DEFAULTS. */
  dotMatrixHeightMm?: number
  dotMatrixOffsetLeftMm?: number
  dotMatrixOffsetTopMm?: number
}

/**
 * Starting guesses for the dot-matrix mode's tunable numbers (ticket's own stated values) — the
 * whole point of this mode is that these are wrong until an admin trial-and-errors them against
 * the real stakeholder printer via the Print Settings admin dialog, so this is the one place to
 * change the default guess.
 */
export const DOTMATRIX_HALF_DEFAULTS = { heightMm: 135, offsetLeftMm: 0, offsetTopMm: 0 } as const

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
  half: 'Half (A5 Landscape, 210 × 148.5mm)',
  roll: 'Roll (Thermal 80mm)',
  letter: 'Letter',
  dotmatrix_half: 'Dot Matrix Half (9.5" × 5.5")',
}

/**
 * Only Continuous/Half get an explicit @page override — A4 relies on the browser/printer
 * default, exactly like before this option existed. Roll computes its own @page string at
 * render time (paper height depends on measured content), so it's left null here too.
 *
 * Half is A5 LANDSCAPE — 210 x 148.5mm (595.276 x 420.945pt per the reference PDF's own page
 * box, see invoice-print-spec.md), not the 148 x 210mm portrait box this shipped with
 * initially. margin:0 (not 6mm) because the precise SkyBiz-replica layout positions every
 * element with absolute mm coordinates that already bake the page margin in — a nonzero
 * @page margin here would double-offset everything.
 */
export const PRINT_PAPER_PAGE_CSS: Record<PrintPaperType, string | null> = {
  a4: null,
  continuous: '@page { size: 9.5in 11in; margin: 6mm; }',
  half: '@page { size: 210mm 148.5mm; margin: 0; }',
  roll: null,
  // Payment Voucher print's own A4/Letter toggle — A4 stays `null` (browser default, same as
  // every other a4 consumer) since its own content div is already sized to 210x297mm; Letter
  // needs an explicit @page since the browser default is A4-shaped on most locales/printers.
  letter: '@page { size: 216mm 279mm; margin: 12mm; }',
  // Never read from here — Delivery/Invoice print build their own dot-matrix @page string inline
  // (deliberately margin-only, no size, so Chrome follows the printer's own paper size) and no
  // other consumer offers this paper type. Present only so this Record<PrintPaperType, ...> stays
  // exhaustive.
  dotmatrix_half: null,
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
 * the full 'a4' | 'continuous' | 'half' | 'roll' range.
 */
const INVOICE_PRINT_PAPER_TYPE_STORAGE_KEY = 'print-paper-type-invoice'

export function loadInvoicePaperTypePreference(): PrintPaperType {
  const stored = localStorage.getItem(INVOICE_PRINT_PAPER_TYPE_STORAGE_KEY)
  return stored === 'continuous' || stored === 'half' || stored === 'roll' ? stored : 'a4'
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

/**
 * Whole-object PrintOptions persistence under one key — unlike Invoice (which saves
 * paperType/showDiscount as two separate keys, see loadInvoicePaperTypePreference above), Delivery
 * and Sales Order each persist their full PrintOptions object as one JSON blob under their own key
 * so neither collides with Invoice's or each other's.
 */
function createPrintOptionsStorage(storageKey: string) {
  return {
    load(): Partial<PrintOptions> {
      try {
        const raw = localStorage.getItem(storageKey)
        return raw ? JSON.parse(raw) : {}
      } catch {
        return {}
      }
    },
    save(options: PrintOptions): void {
      localStorage.setItem(storageKey, JSON.stringify(options))
    },
  }
}

const deliveryPrintOptionsStorage = createPrintOptionsStorage('print-options:delivery-order')
export const loadDeliveryPrintOptions = deliveryPrintOptionsStorage.load
export const saveDeliveryPrintOptions = deliveryPrintOptionsStorage.save

const salesOrderPrintOptionsStorage = createPrintOptionsStorage('print-options:sales-order')
export const loadSalesOrderPrintOptions = salesOrderPrintOptionsStorage.load
export const saveSalesOrderPrintOptions = salesOrderPrintOptionsStorage.save

const paymentVoucherPrintOptionsStorage = createPrintOptionsStorage('print-options:payment-voucher')
export const loadPaymentVoucherPrintOptions = paymentVoucherPrintOptionsStorage.load
export const savePaymentVoucherPrintOptions = paymentVoucherPrintOptionsStorage.save

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

/**
 * Opens a print-preview route in a new browser tab instead of navigating the
 * current one. Print routes render standalone with no app chrome (sidebar,
 * topbar, breadcrumb — see the flat route block in router.tsx), so the new
 * tab shows just the document, ready to print via its own in-page Print
 * button or the browser's native print icon.
 */
export function openPrintWindow(url: string): void {
  window.open(url, '_blank', 'noopener')
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
