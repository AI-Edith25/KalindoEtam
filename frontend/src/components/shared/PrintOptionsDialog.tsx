import { ArrowDown, ArrowUp } from 'lucide-react'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  ALL_PRINT_COLUMNS,
  buildPrintColumnRows,
  getPrintMargins,
  PRINT_COLUMN_LABELS,
  PRINT_FONT_SIZE_LABELS,
  PRINT_PAPER_TYPE_LABELS,
  type PrintColumnKey,
  type PrintFontSize,
  type PrintMargins,
  type PrintOptions,
  type PrintPaperType,
} from '@/shared/lib/printOptions'

const DECIMAL_CHOICES = ['0', '1', '2', '3', '4']

const FONT_FAMILY_OPTIONS = [
  { value: '"Times New Roman", "Tinos", "Liberation Serif", serif', label: 'Times New Roman' },
  { value: 'Arial, Helvetica, sans-serif', label: 'Arial' },
  { value: '"Courier New", "Cutive Mono", monospace', label: 'Courier New' },
]

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
  /** Only Invoice print renders/acts on this (the Total Discount line) — every other consumer leaves it hidden, same convention as showPaperType. */
  showDiscount?: boolean
  /**
   * Orientation/margins/scale/font family/number format/column visibility+order/header-footer
   * toggles — only Invoice print (A4/Continuous/Half) has layout support for any of this, so
   * every other consumer leaves it off, same convention as showPaperType/showDiscount above.
   */
  advanced?: boolean
  /** Only rendered when advanced is on and this is provided — pulls the current company default back in, overwriting whatever the user has changed in this session. */
  onResetToDefault?: () => void
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
  advanced = false,
  onResetToDefault,
}: PrintOptionsDialogProps) {
  const visibleColumns = options.visibleColumns ?? ALL_PRINT_COLUMNS
  const columnRows = buildPrintColumnRows(visibleColumns)
  const visibleColumnCount = visibleColumns.length

  function toggleColumn(key: PrintColumnKey, checked: boolean) {
    if (!checked && visibleColumnCount <= 1) return
    const nextRows = columnRows.map((row) => (row.key === key ? { ...row, visible: checked } : row))
    onChange({ ...options, visibleColumns: nextRows.filter((row) => row.visible).map((row) => row.key) })
  }

  function moveColumn(index: number, direction: -1 | 1) {
    const target = index + direction
    if (target < 0 || target >= columnRows.length) return
    const nextRows = [...columnRows]
    ;[nextRows[index], nextRows[target]] = [nextRows[target], nextRows[index]]
    onChange({ ...options, visibleColumns: nextRows.filter((row) => row.visible).map((row) => row.key) })
  }

  // Margins are keyed by paper type (A4/Continuous/Half had different hardcoded defaults before
  // this setting existed) — editing here always targets whichever paper type is currently active.
  const activeMargins = getPrintMargins(options, options.paperType)

  function updateMargin(side: keyof PrintMargins, value: number) {
    onChange({ ...options, margins: { ...options.margins, [options.paperType]: { ...activeMargins, [side]: value } } })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className={advanced ? 'max-h-[85vh] overflow-y-auto' : undefined}>
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
          {showDiscount && (
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox
                checked={options.showDiscount ?? false}
                onCheckedChange={(checked) => onChange({ ...options, showDiscount: checked === true })}
              />
              Tampilkan Diskon
            </label>
          )}

          {advanced && (
            <>
              <div className="border-t pt-4">
                <p className="mb-3 text-sm font-semibold">Kertas &amp; Layout</p>
                <div className="flex flex-col gap-3">
                  <div className="flex flex-col gap-1.5">
                    <label className="text-sm font-medium">Orientasi</label>
                    <Select
                      value={options.orientation ?? 'portrait'}
                      onValueChange={(value) => onChange({ ...options, orientation: value as 'portrait' | 'landscape' })}
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="portrait">Portrait</SelectItem>
                        <SelectItem value="landscape">Landscape</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="grid grid-cols-4 gap-2">
                    <div className="flex flex-col gap-1.5">
                      <label className="text-xs font-medium">Atas (mm)</label>
                      <Input type="number" min={0} max={60} value={activeMargins.top} onChange={(e) => updateMargin('top', Number(e.target.value))} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-xs font-medium">Bawah (mm)</label>
                      <Input
                        type="number"
                        min={0}
                        max={60}
                        value={activeMargins.bottom}
                        onChange={(e) => updateMargin('bottom', Number(e.target.value))}
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-xs font-medium">Kiri (mm)</label>
                      <Input
                        type="number"
                        min={0}
                        max={60}
                        value={activeMargins.left}
                        onChange={(e) => updateMargin('left', Number(e.target.value))}
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-xs font-medium">Kanan (mm)</label>
                      <Input
                        type="number"
                        min={0}
                        max={60}
                        value={activeMargins.right}
                        onChange={(e) => updateMargin('right', Number(e.target.value))}
                      />
                    </div>
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <label className="text-sm font-medium">Skala (%)</label>
                    <Input
                      type="number"
                      min={50}
                      max={150}
                      step={5}
                      value={options.scalePercent ?? 100}
                      onChange={(e) => onChange({ ...options, scalePercent: Number(e.target.value) })}
                    />
                  </div>
                </div>
              </div>

              <div className="border-t pt-4">
                <p className="mb-3 text-sm font-semibold">Teks</p>
                <div className="flex flex-col gap-1.5">
                  <label className="text-sm font-medium">Font Family</label>
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
              </div>

              <div className="border-t pt-4">
                <p className="mb-3 text-sm font-semibold">Angka</p>
                <div className="flex flex-col gap-3">
                  <div className="flex flex-col gap-1.5">
                    <label className="text-sm font-medium">Pemisah Ribuan/Desimal</label>
                    <Select
                      value={options.numberFormat ?? 'en'}
                      onValueChange={(value) => onChange({ ...options, numberFormat: value as 'id' | 'en' })}
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="en">1,000.00</SelectItem>
                        <SelectItem value="id">1.000,00</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <label className="flex items-center justify-between text-sm font-medium">
                    Tampilkan Simbol Mata Uang di Kolom Tabel
                    <Switch
                      checked={options.showCurrencySymbol ?? false}
                      onCheckedChange={(checked) => onChange({ ...options, showCurrencySymbol: checked })}
                    />
                  </label>
                </div>
              </div>

              <div className="border-t pt-4">
                <p className="mb-1 text-sm font-semibold">Kolom Tabel Item</p>
                <p className="mb-3 text-xs text-muted-foreground">Minimal satu kolom harus tetap aktif.</p>
                <div className="flex flex-col gap-1">
                  {columnRows.map((row, index) => (
                    <div key={row.key} className="flex items-center gap-2 rounded-md border p-1.5">
                      <Checkbox
                        checked={row.visible}
                        onCheckedChange={(checked) => toggleColumn(row.key, checked === true)}
                        disabled={row.visible && visibleColumnCount <= 1}
                      />
                      <span className="flex-1 text-sm">{PRINT_COLUMN_LABELS[row.key]}</span>
                      <Button type="button" variant="ghost" size="icon" className="size-6" disabled={index === 0} onClick={() => moveColumn(index, -1)}>
                        <ArrowUp className="size-3.5" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-6"
                        disabled={index === columnRows.length - 1}
                        onClick={() => moveColumn(index, 1)}
                      >
                        <ArrowDown className="size-3.5" />
                      </Button>
                    </div>
                  ))}
                </div>
              </div>

              <div className="border-t pt-4">
                <p className="mb-3 text-sm font-semibold">Header &amp; Footer</p>
                <div className="flex flex-col gap-3">
                  <label className="flex items-center justify-between text-sm font-medium">
                    Tampilkan Logo
                    <Switch checked={options.showLogo ?? false} onCheckedChange={(checked) => onChange({ ...options, showLogo: checked })} />
                  </label>
                  <label className="flex items-center justify-between text-sm font-medium">
                    Tampilkan Alamat
                    <Switch checked={options.showAddress ?? true} onCheckedChange={(checked) => onChange({ ...options, showAddress: checked })} />
                  </label>
                  <label className="flex items-center justify-between text-sm font-medium">
                    Tampilkan Telepon
                    <Switch checked={options.showPhone ?? true} onCheckedChange={(checked) => onChange({ ...options, showPhone: checked })} />
                  </label>
                  <label className="flex items-center justify-between text-sm font-medium">
                    Tampilkan Email
                    <Switch checked={options.showEmail ?? true} onCheckedChange={(checked) => onChange({ ...options, showEmail: checked })} />
                  </label>
                  <div className="flex flex-col gap-1.5">
                    <label className="text-sm font-medium">Catatan / Terms Footer</label>
                    <Textarea
                      rows={2}
                      value={options.footerNotes ?? ''}
                      onChange={(e) => onChange({ ...options, footerNotes: e.target.value })}
                      placeholder="Kosongkan untuk tidak menampilkan catatan tambahan"
                    />
                  </div>
                  <label className="flex items-center justify-between text-sm font-medium">
                    Tampilkan Blok Tanda Tangan
                    <Switch
                      checked={options.showSignatureBlock ?? true}
                      onCheckedChange={(checked) => onChange({ ...options, showSignatureBlock: checked })}
                    />
                  </label>
                  {(options.showSignatureBlock ?? true) && (
                    <div className="grid grid-cols-2 gap-2">
                      <div className="flex flex-col gap-1.5">
                        <label className="text-xs font-medium">Label Kiri</label>
                        <Input
                          value={options.signatureLeftLabel ?? 'AUTHORISED SIGNATURE'}
                          onChange={(e) => onChange({ ...options, signatureLeftLabel: e.target.value })}
                        />
                      </div>
                      <div className="flex flex-col gap-1.5">
                        <label className="text-xs font-medium">Label Kanan</label>
                        <Input
                          value={options.signatureRightLabel ?? 'AUTHORISED SIGNATURE'}
                          onChange={(e) => onChange({ ...options, signatureRightLabel: e.target.value })}
                        />
                      </div>
                    </div>
                  )}
                  <label className="flex items-center justify-between text-sm font-medium">
                    Tampilkan Nomor Halaman
                    <Switch
                      checked={options.showPageNumber ?? true}
                      onCheckedChange={(checked) => onChange({ ...options, showPageNumber: checked })}
                    />
                  </label>
                </div>
              </div>
            </>
          )}
        </div>
        <DialogFooter>
          {advanced && onResetToDefault && (
            <Button type="button" variant="outline" onClick={onResetToDefault}>
              Reset ke Default Perusahaan
            </Button>
          )}
          <Button onClick={() => onOpenChange(false)}>Done</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
