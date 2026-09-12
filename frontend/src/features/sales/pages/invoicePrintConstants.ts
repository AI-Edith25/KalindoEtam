/**
 * Single source of truth for every shared mm/pt/color value used by Invoice print's two layouts:
 * Landscape (Half, A5 landscape) and Portrait (A4, Continuous). Roll (Thermal 80mm) is a separate,
 * frozen template and does not use anything in this file.
 *
 * Landscape's own per-element baseline positions (the big absolute top/left table extracted
 * verbatim from invoice-print-spec.md — Sections 4/5/6/7/8/11) are intentionally NOT duplicated
 * into this file: that dataset is already a frozen, precisely-verified legacy replica, and
 * mechanically moving ~50 already-correct literals into named constants would only add
 * transcription risk for no functional benefit. What DOES live here is everything genuinely
 * shared or newly introduced: paper sizes, the locked font sizes (now that Font Size is gone),
 * the header label/colon/value geometry (BUG 1), and the totals box geometry (BUG 2) — Portrait
 * is a brand-new component built directly against these constants, and Landscape's own totals
 * box / header meta rows are updated to read the same constants instead of separately-guessed
 * numbers (which is exactly what caused the last two rounds of regressions).
 */

export const DEJAVU_FONT_STACK = '"DejaVu Sans Condensed", sans-serif'

/** Shared @font-face declarations — both layouts render `<style>{DEJAVU_FONT_FACES}</style>` once
    each so the webfont is available regardless of which one is on screen. */
export const DEJAVU_FONT_FACES = `
@font-face{ font-family:'DejaVu Sans Condensed';
  src:url('/fonts/DejaVuSansCondensed.woff2') format('woff2');
  font-weight:400; font-style:normal; font-display:block; }
@font-face{ font-family:'DejaVu Sans Condensed';
  src:url('/fonts/DejaVuSansCondensed-Bold.woff2') format('woff2');
  font-weight:700; font-style:normal; font-display:block; }
@font-face{ font-family:'DejaVu Sans Condensed';
  src:url('/fonts/DejaVuSansCondensed-BoldOblique.woff2') format('woff2');
  font-weight:700; font-style:italic; font-display:block; }
`

/** Visual content margin on every side, every paper type (except Roll). For Portrait this is a
    real CSS padding (since @page margin is always 0 — see PAPER_SIZES). For Landscape it is NOT a
    CSS padding — it's already baked into the frozen absolute coordinates (e.g. left:10mm IS this
    margin), so nothing there reads this constant; it's recorded here only so the relationship
    between physical size and content width (below) is documented in one place. */
export const MARGIN_MM = 10

export type InvoicePaperKey = 'a4' | 'half' | 'continuous'

/** Physical paper size. @page always uses `margin: 0` for every paper type — the 10mm margin
    above is our own layout inset (Portrait's own padding; Landscape's baked-in coordinates), never
    a browser-level @page margin — so the on-screen preview frame and the printed page are always
    identical in size, and there is no "usable vs physical" distinction to reconcile (a real
    @page margin here would have to carve space out of these same numbers, which is what caused
    Continuous's size to be wrong before). */
export const PAPER_SIZES: Record<InvoicePaperKey, { widthMm: number; heightMm: number }> = {
  a4: { widthMm: 210, heightMm: 297 },
  half: { widthMm: 210, heightMm: 148.5 },
  continuous: { widthMm: 241.3, heightMm: 279.4 },
}

/** Content width after MARGIN_MM on both sides: 190mm for A4/Half, 221.3mm for Continuous. */
export function contentWidthMm(paper: InvoicePaperKey): number {
  return PAPER_SIZES[paper].widthMm - 2 * MARGIN_MM
}

export const COLORS = {
  text: '#000',
  /** Item table's own rule color (top rule + header underline) — #383838, not black, per the
      legacy reference (invoice-print-spec.md Section 3). */
  tableRule: '#383838',
  rule: '#000',
}

export const LINE_HEIGHT = 1.164

/** 1pt = 0.352778mm — invoice-print-spec.md Section 1's own conversion factor, used wherever a
    pt-based font-size/line-height needs converting to a real mm measurement (e.g. predicting a
    text line's own rendered height). */
export const PT_TO_MM = 0.352778

/**
 * Locked font sizes (pt) — BUG 5 removes the "Font Size (pt)" control entirely, so every size
 * below is now a fixed constant instead of something the old size-10-default happened to render
 * as. Landscape's own inline sizes (already numerically identical, from invoice-print-spec.md)
 * are left as literals there — see file doc comment — but these are the canonical values, and
 * Portrait (built fresh) reads them directly.
 */
export const FONT_PT = {
  companyName: 14,
  title: 16,
  customerName: 10,
  totalsBox: 10,
  eoeNote: 10,
  bankNote: 9,
  metaRight: 9,
  metaLeft: 8,
  signatureName: 9,
  signatureCaption: 9,
  words: 8,
  tableHeader: 10,
  tableBody: 10,
} as const

/**
 * Header label/":"/value geometry (BUG 1) — three fixed-width columns: label (left-aligned, width
 * already includes its own META_GAP_MM trailing gap), ":" (fixed width), value (left-aligned,
 * gets its own META_GAP_MM leading gap via padding). This is the actual failure mode from the
 * last regression: a label box sized too narrow lets long text run into the ":" or right-aligning
 * it moves short labels away from a shared left edge (also wrong, per the real legacy layout,
 * where every label is LEFT-aligned).
 *
 * Widths below are not estimated — measured directly in Chrome against the real DejaVu Sans
 * Condensed webfont (`document.fonts.load` + `getBoundingClientRect().width`) for every real
 * label string used by either layout, bold included (a row's own boldness varies, so the column
 * is sized to the bold measurement — the wider case — regardless of which specific row is bold):
 *   Right block @9pt bold, widest = "Payment Term" = 22.858mm
 *   Left block  @8pt bold, widest = "EMAIL"        =  8.792mm
 * Each gets + META_GAP_MM then rounded up for safety margin against font-hinting/rounding
 * differences between measurement and final render.
 */
export const META_GAP_MM = 1.5
export const META_COLON_WIDTH_MM = 2.5
/** Covers TEL / EMAIL / Tel (company+customer block) and Attn / Tel / Fax (Portrait's customer block). */
export const META_LABEL_WIDTH_LEFT_MM = 11
/** Covers NO / Date / Reference 1 / Reference 2 / Payment Term / Jatuh Tempo / Sales Person / Page No / Location. */
export const META_LABEL_WIDTH_RIGHT_MM = 25

/**
 * Totals box (BUG 2) — outer border ONLY (no internal rule between rows, per the legacy reference
 * — invoice-print-spec.md Section 8: "Garis pemisah antar baris di dalam: TIDAK ADA"), every row
 * bold, uniform row padding, height auto-fitting whatever rows actually show.
 */
export const TOTALS_BOX = {
  /** label | "RP" | nominal column widths (mm) — nominal's own width is whatever remains of the
      box's own total width once the caller's chosen box width is known (box width itself is
      Landscape/Portrait-specific, since it aligns to each layout's own item-table geometry).
      labelColMm comfortably fits "Grand Total" bold at 10pt (~23.3mm, scaled from the same
      measured-font method as the meta labels above) with real padding; rpColMm fits "RP" with
      padding — leaving nominal generously wide enough for a 14-character amount like
      "999,999,999.00" (needs ~27.6mm at 10pt bold per invoice-print-spec.md's own digit-width
      metric of 0.626em/digit) on both Landscape's 79.08mm-wide box and Portrait's wider one. */
  labelColMm: 30,
  rpColMm: 10,
  rowPaddingVerticalMm: 1,
  rowPaddingHorizontalMm: 1.5,
  borderMm: 0.5,
} as const

/** One totals-box row's real rendered height: 2×vertical padding + a 10pt line at LINE_HEIGHT —
    used by Landscape to predict where the signature block needs to start (see
    LANDSCAPE_SIGNATURE_GAP_MM) before the real `<table>` below it actually renders. */
export const TOTALS_BOX_ROW_HEIGHT_MM = 2 * TOTALS_BOX.rowPaddingVerticalMm + FONT_PT.totalsBox * LINE_HEIGHT * PT_TO_MM

/**
 * Landscape-only derived constants for the signature block's vertical position (BUG 3 — content
 * was rendering past the bottom of the physical page). Not part of the original frozen baseline
 * table (that table only ever measured ONE totals-box row count), so these are new, but they are
 * calculated FROM that frozen table, not guessed:
 *   - LANDSCAPE_LEFT_COLUMN_BOTTOM_MM: bottom of the E&O.E/bank-note column — its last line's own
 *     top (102.05mm, frozen) + a 9pt bold line's own line-height (9 × 1.164 = 10.476pt = 3.696mm).
 *   - LANDSCAPE_SIGNATURE_GAP_MM: the frozen reference itself gives exactly one real data point
 *     for "gap from totals-box bottom to signature name" — box bottom 107.57mm (Section 8, the
 *     3-row TOTAL/TAX/Grand Total case) to signature name top 110.25mm (Section 6) = 2.68mm. Using
 *     that same real gap for every row count reproduces the frozen 110.25mm exactly for that one
 *     verified case, and degrades gracefully (never overflowing 148.5mm even at 4 rows) for every
 *     other Tax/Discount combination — see the fit check in InvoiceLandscapeLayout's own comment.
 */
export const LANDSCAPE_LEFT_COLUMN_BOTTOM_MM = 102.05 + 9 * LINE_HEIGHT * PT_TO_MM
export const LANDSCAPE_SIGNATURE_GAP_MM = 110.25 - 107.57

/**
 * Portrait layout (A4 + Continuous) spacing — a brand-new layout with no legacy pixel spec to
 * replicate (unlike Landscape), so every gap/rule-weight below is a deliberately chosen, explicit
 * constant rather than something inline in the component. Uses normal document flow (not absolute
 * positioning), so these are gaps/padding, not absolute top/left coordinates.
 */
export const PORTRAIT = {
  /** Space between the header block (company info) and the "INVOICE" title. */
  gapAboveTitleMm: 4,
  /** Thick rule under the title — same weight as Landscape's own rule (invoice-print-spec.md Section 5). */
  titleRuleThicknessMm: 0.8,
  /** Space between the title rule and the two-column customer/meta block below it. */
  gapBelowTitleRuleMm: 3,
  /** Two-column block split (customer info vs. NO/Date/.../Location) — right column starts at ~52% of content width. */
  headerLeftColPercent: 48,
  headerRightColPercent: 52,
  gapBetweenColsMm: 4,
  /** Space between the two-column block and the item table. */
  gapAboveTableMm: 3,
  /** Item table rule weights (top rule above header row, bottom rule under it) and cell padding. */
  tableRuleThicknessMm: 0.5,
  tableCellPaddingVerticalMm: 1,
  tableCellPaddingHorizontalMm: 1.5,
  /** Space between the item table and the amount-in-words line, and the rule below that line. */
  gapAboveWordsMm: 3,
  wordsRuleThicknessMm: 0.5,
  gapBelowWordsRuleMm: 3,
  /** Footer two-column split (E&O.E note vs. totals box). */
  footerLeftColPercent: 55,
  footerRightColPercent: 45,
  /** Totals box's own label|RP|nominal column split, as percentages of the box's own width. */
  totalsLabelColPercent: 45,
  totalsRpColPercent: 15,
  totalsNominalColPercent: 40,
  /** Space between the footer row and the signature name row. */
  gapAboveSignatureMm: 10,
  signatureLineWidthMm: 65,
  signatureLineThicknessMm: 0.5,
  signatureGapNameToLineMm: 12,
  signatureGapLineToCaptionMm: 2,
} as const

/**
 * Portrait item table columns, as percentages of content width (table-layout: fixed + percentage
 * colgroup widths) — unlike Landscape's mm-precise legacy replica, Portrait has no fixed physical
 * width to match (A4 is 190mm content, Continuous is 221.3mm), so percentages let one column
 * definition work for both automatically, and guarantee the rightmost column (HCLineAmt) can never
 * run past the content edge (BUG 4). Sums to exactly 100; see getPortraitItemCols for how the
 * "tax" column's width is folded into "lineAmt" when Tax is off.
 *
 * Numeric column percentages are sized against A4's 190mm content width (the narrower of the two
 * Portrait paper types — sizing against it guarantees Continuous's own wider 221.3mm content also
 * fits, since the same percentage there yields more absolute mm, never less). Widths are real
 * measurements against the actual DejaVu Sans Condensed webfont at 10pt (`getBoundingClientRect`
 * in Chrome), not estimates — a first version sized these too narrow and a large amount like
 * "2,999,999,997.00" (28.26mm wide at 10pt) visibly overlapped the neighboring column:
 *   HCUnitCost/HCTax content ≈ 25.23mm, HCLineAmt content ≈ 28.26mm (worst case: unit cost
 *   999,999,999 × qty 3) — each +3mm real padding (1.5mm each side, TOTALS_BOX-style) rounds up to
 *   16 / 16 / 18 percent of 190mm (30.4 / 30.4 / 34.2mm). Description intentionally wraps (see
 *   InvoicePortraitLayout's own white-space handling) so it absorbs whatever percentage remains.
 */
export const PORTRAIT_ITEM_COLS: { key: string; label: string; align: 'left' | 'right'; percent: number }[] = [
  { key: 'no', label: 'No', align: 'left', percent: 5 },
  { key: 'itemCode', label: 'ItemCode', align: 'left', percent: 12 },
  { key: 'description', label: 'Description', align: 'left', percent: 20 },
  { key: 'qty', label: 'Qty', align: 'right', percent: 6 },
  { key: 'uom', label: 'UOM', align: 'left', percent: 7 },
  { key: 'unitCost', label: 'HCUnitCost', align: 'right', percent: 16 },
  { key: 'tax', label: 'HCTax', align: 'right', percent: 16 },
  { key: 'lineAmt', label: 'HCLineAmt', align: 'right', percent: 18 },
]
