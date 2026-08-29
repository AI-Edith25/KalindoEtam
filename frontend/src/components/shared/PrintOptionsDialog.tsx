import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  PRINT_FONT_SIZE_LABELS,
  PRINT_PAPER_TYPE_LABELS,
  type PrintFontSize,
  type PrintOptions,
  type PrintPaperType,
} from '@/shared/lib/printOptions'

const DECIMAL_CHOICES = ['0', '1', '2', '3', '4']

const FONT_FAMILY_OPTIONS = [
  { value: '"Times New Roman", "Tinos", "Liberation Serif", serif', label: 'Times New Roman' },
  { value: 'Arial, Helvetica, sans-serif', label: 'Arial' },
  { value: '"Courier New", "Cutive Mono", monospace', label: 'Courier New' },
]

const NUMERIC_FONT_SIZE_CHOICES = [8, 9, 10, 11, 12, 13, 14, 16]
const DEFAULT_FONT_SIZE_PT = 10

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
  /** Only Invoice print renders/acts on this (Font Family dropdown) — every other consumer leaves it hidden, same convention as showDiscount. */
  showFontFamily?: boolean
  /** Only Invoice print uses this — swaps the Font Size control from the shared small/medium/large Select to a numeric point-size Select bound to `options.fontSizePt` instead of `options.fontSize`. Every other consumer leaves it off and keeps the small/medium/large control. */
  useNumericFontSize?: boolean
  /** Only Invoice print renders/acts on this (editable signature block labels) — every other consumer leaves it hidden, same convention as showDiscount. */
  showSignatureLabels?: boolean
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
  showFontFamily = false,
  useNumericFontSize = false,
  showSignatureLabels = false,
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
          {useNumericFontSize ? (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Font Size (pt)</label>
              <Select
                value={String(options.fontSizePt ?? DEFAULT_FONT_SIZE_PT)}
                onValueChange={(value) => onChange({ ...options, fontSizePt: Number(value) })}
              >
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {NUMERIC_FONT_SIZE_CHOICES.map((pt) => (
                    <SelectItem key={pt} value={String(pt)}>
                      {pt}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          ) : (
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
              <Select value={options.fontFamily ?? FONT_FAMILY_OPTIONS[0].value} onValueChange={(value) => onChange({ ...options, fontFamily: value })}>
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
                checked={options.showDecimalTotals ?? false}
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
                  value={options.signatureLeftLabel ?? 'AUTHORISED SIGNATURE'}
                  onChange={(e) => onChange({ ...options, signatureLeftLabel: e.target.value })}
                />
              </div>
              <div className="flex flex-col gap-1.5">
                <label className="text-xs font-medium">Label Tanda Tangan Kanan</label>
                <Input
                  value={options.signatureRightLabel ?? 'AUTHORISED SIGNATURE'}
                  onChange={(e) => onChange({ ...options, signatureRightLabel: e.target.value })}
                />
              </div>
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
