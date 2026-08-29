export type PrintFontSize = 'small' | 'medium' | 'large'
export type PrintPaperType = 'a4' | 'continuous' | 'half'
export type PrintOrientation = 'portrait' | 'landscape'
export type PrintNumberFormat = 'id' | 'en'
export type PrintColumnKey = 'itemCode' | 'description' | 'sales' | 'qty' | 'uom' | 'unitCost' | 'lineAmt'

export const ALL_PRINT_COLUMNS: PrintColumnKey[] = ['itemCode', 'description', 'sales', 'qty', 'uom', 'unitCost', 'lineAmt']

export const PRINT_COLUMN_LABELS: Record<PrintColumnKey, string> = {
  itemCode: 'ItemCode',
  description: 'Description',
  sales: 'Sales',
  qty: 'Qty',
  uom: 'UOM',
  unitCost: 'HCUnitCost',
  lineAmt: 'HCLineAmt',
}

export interface PrintColumnRow {
  key: PrintColumnKey
  visible: boolean
}

/**
 * Turns "which columns are visible, in order" (the only thing actually persisted —
 * PrintOptions.visibleColumns) into a full checkbox-list row for every column, hidden ones
 * included. Order of the whole array (visible + hidden) is the saved column order — a hidden
 * column keeps its position so re-checking it doesn't jump it to the end. Shared by
 * PrintOptionsDialog's advanced section and InvoicePrintSettingsPage's column editor so the two
 * don't grow slightly different reordering behavior over time.
 */
export function buildPrintColumnRows(visibleColumns: PrintColumnKey[]): PrintColumnRow[] {
  const hidden = ALL_PRINT_COLUMNS.filter((key) => !visibleColumns.includes(key))
  return [...visibleColumns.map((key) => ({ key, visible: true })), ...hidden.map((key) => ({ key, visible: false }))]
}

export interface PrintMargins {
  top: number
  bottom: number
  left: number
  right: number
}

export interface PrintOptions {
  fontSize: PrintFontSize
  paperType: PrintPaperType
  qtyDecimals: number
  priceDecimals: number
  amountDecimals: number
  /** Only Invoice print acts on this (renders the Total Discount line) — every other consumer leaves it unset, same convention as paperType/showPaperType below. */
  showDiscount?: boolean
  /**
   * Invoice print's own extended layout controls (Administration > Invoice Print Settings +
   * Print Preview's "Print Options" dialog, advanced section) — every other PrintOptionsDialog
   * consumer (SO/DO/Payment print) leaves all of these unset, same convention as showDiscount.
   */
  orientation?: PrintOrientation
  /** Keyed by paper type — A4/Continuous/Half had different hardcoded margins before this setting existed (12mm vs 6mm), so one flat margin can't represent "unset" for all three without changing at least one of them. Missing entries fall back to INVOICE_DEFAULT_MARGINS[paperType], see getPrintMargins. */
  margins?: Partial<Record<PrintPaperType, PrintMargins>>
  scalePercent?: number
  fontFamily?: string
  numberFormat?: PrintNumberFormat
  showCurrencySymbol?: boolean
  /** Order IS the display order — a column absent from this array is hidden, not just "unordered". */
  visibleColumns?: PrintColumnKey[]
  showLogo?: boolean
  showAddress?: boolean
  showPhone?: boolean
  showEmail?: boolean
  footerNotes?: string
  showSignatureBlock?: boolean
  signatureLeftLabel?: string
  signatureRightLabel?: string
  showPageNumber?: boolean
}

/** Pre-existing hardcoded margins per paper type (A4 12mm, Continuous/Half 6mm) — the fallback whenever PrintOptions.margins has no entry for a paper type, so this feature shipping never silently changes an existing invoice's look. */
export const INVOICE_DEFAULT_MARGINS: Record<PrintPaperType, PrintMargins> = {
  a4: { top: 12, bottom: 12, left: 12, right: 12 },
  continuous: { top: 6, bottom: 6, left: 6, right: 6 },
  half: { top: 6, bottom: 6, left: 6, right: 6 },
}

export function getPrintMargins(options: Pick<PrintOptions, 'margins'>, paperType: PrintPaperType): PrintMargins {
  return options.margins?.[paperType] ?? INVOICE_DEFAULT_MARGINS[paperType]
}

type InvoiceAdvancedOptions = Required<
  Pick<
    PrintOptions,
    | 'orientation'
    | 'scalePercent'
    | 'fontFamily'
    | 'numberFormat'
    | 'showCurrencySymbol'
    | 'visibleColumns'
    | 'showLogo'
    | 'showAddress'
    | 'showPhone'
    | 'showEmail'
    | 'footerNotes'
    | 'showSignatureBlock'
    | 'signatureLeftLabel'
    | 'signatureRightLabel'
    | 'showPageNumber'
  >
> & { margins: Record<PrintPaperType, PrintMargins> }

/**
 * Fallback when there is no company default yet (or it hasn't loaded) — matches
 * InvoicePrintPage's pre-existing hardcoded layout exactly, so this feature shipping never
 * silently changes an existing invoice's look. Kept in sync by hand with the seeded row in
 * database/migrations/..._create_invoice_print_settings_table.php.
 */
export const INVOICE_ADVANCED_DEFAULTS: InvoiceAdvancedOptions = {
  orientation: 'portrait',
  margins: INVOICE_DEFAULT_MARGINS,
  scalePercent: 100,
  fontFamily: '"Times New Roman", "Tinos", "Liberation Serif", serif',
  numberFormat: 'en',
  // Table cells never showed a currency symbol before this setting existed (only the Grand
  // Total line did, via its own hardcoded "RP" literal, unaffected by this flag) — false keeps
  // that exact look until an admin opts in.
  showCurrencySymbol: false,
  visibleColumns: ALL_PRINT_COLUMNS,
  showLogo: false,
  showAddress: true,
  showPhone: true,
  showEmail: true,
  footerNotes: '',
  showSignatureBlock: true,
  signatureLeftLabel: 'AUTHORISED SIGNATURE',
  signatureRightLabel: 'AUTHORISED SIGNATURE',
  showPageNumber: true,
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
 * Invoice print used to persist paperType/showDiscount per-browser via localStorage under its
 * own keys (deliberately separate from PRINT_PAPER_TYPE_STORAGE_KEY above, which Payment print
 * still uses — Payment has no layout support for 'half'). That's superseded by the company
 * default (Administration > Invoice Print Settings, fetched fresh each session) plus an
 * explicit "Reset to company default" button — see InvoicePrintPage — so there is no longer a
 * separate per-browser sticky preference for Invoice specifically.
 */

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

/**
 * Invoice print's own number formatter — separate from formatQty/formatMoney above, which are
 * fixed to id-ID grouping/currency style. numberFormat picks plain grouping only ('en' = comma
 * thousands + dot decimal, 'id' = dot thousands + comma decimal); showCurrencySymbol prepends
 * "RP" (matching the legacy Grand Total line's own literal), never Intl's own currency style,
 * so the symbol placement stays identical regardless of numberFormat.
 */
export function formatPrintNumber(
  value: number | string,
  decimals: number,
  numberFormat: PrintNumberFormat = 'en',
  showCurrencySymbol = false,
): string {
  const locale = numberFormat === 'id' ? 'id-ID' : 'en-US'
  const formatted = new Intl.NumberFormat(locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
  return showCurrencySymbol ? `RP ${formatted}` : formatted
}
