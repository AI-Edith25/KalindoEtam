import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  DOTMATRIX_HALF_DEFAULTS,
  FONT_FAMILY_OPTIONS,
  PRINT_FONT_SIZE_LABELS,
  PRINT_PAPER_TYPE_LABELS,
  type PrintFontSize,
  type PrintOptions,
  type PrintPaperType,
} from '@/shared/lib/printOptions'

const DECIMAL_CHOICES = ['0', '1', '2', '3', '4']

interface PrintOptionsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  options: PrintOptions
  onChange: (options: PrintOptions) => void
  /** Which decimal-precision controls to show — Delivery (no pricing) only needs Qty. */
  fields?: Array<'qty' | 'price' | 'amount'>
  /** Only Invoice/Delivery print pages actually act on paperType (render @page CSS + resize the preview) — every other consumer leaves it hidden so the control isn't shown with no effect. */
  showPaperType?: boolean
  /** Which values the Paper Type dropdown offers, when shown — defaults to the original 2-value set so every existing caller (Payment print) is unaffected by newer values like 'half' that only Invoice print has layout support for. */
  paperTypeOptions?: PrintPaperType[]
  /** Only Invoice print renders/acts on this (the DISC line in the totals block) — every other consumer leaves it hidden, same convention as showPaperType. */
  showDiscount?: boolean
  /** Only Invoice print renders/acts on this (the HCTax column + TAX line in the totals block) — every other consumer leaves it hidden, same convention as showDiscount. */
  showTax?: boolean
  /** Only Invoice print renders/acts on this (2 vs 0 decimals in the totals block only — table columns stay fixed at their own decimals) — every other consumer leaves it hidden, same convention as showDiscount. Distinct from `fields`, which controls the pre-existing per-column decimal Selects other pages still use. */
  showDecimalToggle?: boolean
  /**
   * Falls back to `false` (unchecked) when `options.showDecimalTotals` is unset — pass this when
   * the caller has its own paper-type-specific default (Invoice's Half paper type defaults this
   * ON, unlike every other paper type) so the checkbox's displayed state matches what's actually
   * rendering instead of always reading as off.
   */
  defaultShowDecimalTotals?: boolean
  /** Only Invoice print renders/acts on this (Font Family dropdown) — every other consumer leaves it hidden, same convention as showDiscount. */
  showFontFamily?: boolean
  /** Falls back to `FONT_FAMILY_OPTIONS[0]` (Times New Roman) when `options.fontFamily` is unset — pass this when the caller has its own paper-type-specific default (Invoice's Half paper type defaults to DejaVu Sans Condensed) so the dropdown's displayed selection matches what's actually rendering. */
  defaultFontFamily?: string
  /** Whether to render the Font Size control (the shared small/medium/large Select) at all —
      defaults to true for every existing consumer. Invoice print sets this to `false`: its
      typography is now locked to fixed pt values per element (no more user-adjustable scale), so
      showing an ineffective Font Size control would be misleading. */
  showFontSize?: boolean
  /** Only Invoice/Delivery print renders/acts on this (editable signature block labels) — every other consumer leaves it hidden, same convention as showDiscount. */
  showSignatureLabels?: boolean
  /** Falls back to 'AUTHORISED SIGNATURE' (Invoice's own default) when `options.signatureLeftLabel`/`signatureRightLabel` are unset — pass these when the caller has its own different default text (Delivery's A4 template defaults to "(AUTHORISED SIGNATURE)" / "Receiver's Signature & Company Stamp", per its own reference layout). */
  defaultSignatureLeftLabel?: string
  defaultSignatureRightLabel?: string
  /** Only Delivery print sets this (Half paper renders no signature block at all) — when set, both signature-label inputs render disabled and this text shows as a hint below them instead of the inputs doing anything. Every other consumer leaves it unset, same convention as showDiscount. */
  signatureLabelsDisabledHint?: string
}

/**
 * Mirrors the legacy print dialog's controls (Font Size, Decimal Qty/Price/Amount)
 * so the pre-print workflow stays familiar. Changes apply live to the print
 * preview underneath — this dialog is a config panel, not a form to submit.
 */
export function PrintOptionsDialog({
  open,
  onOpenChange,
  options,
  onChange,
  fields = ['qty', 'price', 'amount'],
  showPaperType = false,
  paperTypeOptions = ['a4', 'continuous'],
  showDiscount = false,
  showTax = false,
  showDecimalToggle = false,
  defaultShowDecimalTotals = false,
  showFontFamily = false,
  defaultFontFamily,
  showFontSize = true,
  showSignatureLabels = false,
  defaultSignatureLeftLabel = 'AUTHORISED SIGNATURE',
  defaultSignatureRightLabel = 'AUTHORISED SIGNATURE',
  signatureLabelsDisabledHint,
}: PrintOptionsDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Print Options</DialogTitle>
          <DialogDescription>Adjust before printing — applies to the preview immediately.</DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-4">
          {showPaperType && (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Paper Type</label>
              <Select value={options.paperType} onValueChange={(value) => onChange({ ...options, paperType: value as PrintPaperType })}>
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {paperTypeOptions.map((value) => (
                    <SelectItem key={value} value={value}>
                      {PRINT_PAPER_TYPE_LABELS[value]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          {showPaperType && options.paperType === 'dotmatrix_half' && (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Dot Matrix Tuning (mm)</label>
              <div className="grid grid-cols-3 gap-2">
                <div className="flex flex-col gap-1">
                  <span className="text-xs text-muted-foreground">Sheet Height</span>
                  <Input
                    type="number"
                    value={options.dotMatrixHeightMm ?? DOTMATRIX_HALF_DEFAULTS.heightMm}
                    onChange={(e) => onChange({ ...options, dotMatrixHeightMm: Number(e.target.value) })}
                  />
                </div>
                <div className="flex flex-col gap-1">
                  <span className="text-xs text-muted-foreground">Offset Left</span>
                  <Input
                    type="number"
                    value={options.dotMatrixOffsetLeftMm ?? DOTMATRIX_HALF_DEFAULTS.offsetLeftMm}
                    onChange={(e) => onChange({ ...options, dotMatrixOffsetLeftMm: Number(e.target.value) })}
                  />
                </div>
                <div className="flex flex-col gap-1">
                  <span className="text-xs text-muted-foreground">Offset Top</span>
                  <Input
                    type="number"
                    value={options.dotMatrixOffsetTopMm ?? DOTMATRIX_HALF_DEFAULTS.offsetTopMm}
                    onChange={(e) => onChange({ ...options, dotMatrixOffsetTopMm: Number(e.target.value) })}
                  />
                </div>
              </div>
            </div>
          )}
          {showFontSize && (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Font Size</label>
              <Select value={options.fontSize} onValueChange={(value) => onChange({ ...options, fontSize: value as PrintFontSize })}>
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Object.entries(PRINT_FONT_SIZE_LABELS).map(([value, label]) => (
                    <SelectItem key={value} value={value}>
                      {label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          {showFontFamily && (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Font Style</label>
              <Select
                value={options.fontFamily ?? defaultFontFamily ?? FONT_FAMILY_OPTIONS[0].value}
                onValueChange={(value) => onChange({ ...options, fontFamily: value })}
              >
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {FONT_FAMILY_OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          {fields.includes('qty') && (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Decimal Quantity</label>
              <Select value={String(options.qtyDecimals)} onValueChange={(value) => onChange({ ...options, qtyDecimals: Number(value) })}>
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {DECIMAL_CHOICES.map((n) => (
                    <SelectItem key={n} value={n}>
                      {n}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          {fields.includes('price') && (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Decimal Unit Price</label>
              <Select value={String(options.priceDecimals)} onValueChange={(value) => onChange({ ...options, priceDecimals: Number(value) })}>
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {DECIMAL_CHOICES.map((n) => (
                    <SelectItem key={n} value={n}>
                      {n}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          {fields.includes('amount') && (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Decimal Amount</label>
              <Select value={String(options.amountDecimals)} onValueChange={(value) => onChange({ ...options, amountDecimals: Number(value) })}>
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {DECIMAL_CHOICES.map((n) => (
                    <SelectItem key={n} value={n}>
                      {n}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          {showTax && (
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox checked={options.showTax ?? false} onCheckedChange={(checked) => onChange({ ...options, showTax: checked === true })} />
              Tampilkan Tax
            </label>
          )}
          {showDecimalToggle && (
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox
                checked={options.showDecimalTotals ?? defaultShowDecimalTotals}
                onCheckedChange={(checked) => onChange({ ...options, showDecimalTotals: checked === true })}
              />
              Tampilkan Desimal
            </label>
          )}
          {showDiscount && (
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox
                checked={options.showDiscount ?? false}
                onCheckedChange={(checked) => onChange({ ...options, showDiscount: checked === true })}
              />
              Tampilkan Diskon
            </label>
          )}
          {showSignatureLabels && (
            <div className="grid grid-cols-2 gap-2 border-t pt-4">
              <div className="flex flex-col gap-1.5">
                <label className="text-xs font-medium">Label Tanda Tangan Kiri</label>
                <Input
                  value={options.signatureLeftLabel ?? defaultSignatureLeftLabel}
                  onChange={(e) => onChange({ ...options, signatureLeftLabel: e.target.value })}
                  disabled={!!signatureLabelsDisabledHint}
                />
              </div>
              <div className="flex flex-col gap-1.5">
                <label className="text-xs font-medium">Label Tanda Tangan Kanan</label>
                <Input
                  value={options.signatureRightLabel ?? defaultSignatureRightLabel}
                  onChange={(e) => onChange({ ...options, signatureRightLabel: e.target.value })}
                  disabled={!!signatureLabelsDisabledHint}
                />
              </div>
              {signatureLabelsDisabledHint && <p className="col-span-2 text-xs text-muted-foreground">{signatureLabelsDisabledHint}</p>}
            </div>
          )}
        </div>
        <DialogFooter>
          <Button onClick={() => onOpenChange(false)}>Done</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
