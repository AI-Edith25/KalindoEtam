import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowDown, ArrowUp, Loader2 } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Checkbox } from '@/components/ui/checkbox'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { LoadingOverlay } from '@/components/shared/LoadingOverlay'
import { toastApiError } from '@/shared/services/errorHandler'
import {
  ALL_PRINT_COLUMNS,
  buildPrintColumnRows,
  INVOICE_DEFAULT_MARGINS,
  PRINT_COLUMN_LABELS,
  PRINT_FONT_SIZE_LABELS,
  PRINT_PAPER_TYPE_LABELS,
  type PrintColumnKey,
  type PrintColumnRow,
  type PrintMargins,
  type PrintPaperType,
} from '@/shared/lib/printOptions'
import { fetchInvoicePrintSetting, updateInvoicePrintSetting, type InvoicePrintSettingPayload } from '../api/invoicePrintSettingApi'

const FONT_FAMILY_OPTIONS = [
  { value: '"Times New Roman", "Tinos", "Liberation Serif", serif', label: 'Times New Roman' },
  { value: 'Arial, Helvetica, sans-serif', label: 'Arial' },
  { value: '"Courier New", "Cutive Mono", monospace', label: 'Courier New' },
]

const DECIMAL_CHOICES = ['0', '1', '2', '3', '4']

const formSchema = z.object({
  paper_type: z.enum(['a4', 'continuous', 'half']),
  orientation: z.enum(['portrait', 'landscape']),
  scale_percent: z.string(),
  font_family: z.string(),
  font_size: z.enum(['small', 'medium', 'large']),
  qty_decimals: z.string(),
  price_decimals: z.string(),
  amount_decimals: z.string(),
  number_format: z.enum(['id', 'en']),
  show_currency_symbol: z.boolean(),
  show_discount: z.boolean(),
  show_logo: z.boolean(),
  show_address: z.boolean(),
  show_phone: z.boolean(),
  show_email: z.boolean(),
  footer_notes: z.string(),
  show_signature_block: z.boolean(),
  signature_left_label: z.string(),
  signature_right_label: z.string(),
  show_page_number: z.boolean(),
})

type FormValues = z.infer<typeof formSchema>

const DEFAULT_VALUES: FormValues = {
  paper_type: 'a4',
  orientation: 'portrait',
  scale_percent: '100',
  font_family: FONT_FAMILY_OPTIONS[0].value,
  font_size: 'medium',
  qty_decimals: '0',
  price_decimals: '2',
  amount_decimals: '2',
  number_format: 'en',
  // Unit Price/Line Amount table columns never showed a currency symbol before this setting
  // existed (only the Grand Total line did, via its own hardcoded "RP" literal) — false keeps
  // that exact look until an admin opts in.
  show_currency_symbol: false,
  show_discount: false,
  show_logo: false,
  show_address: true,
  show_phone: true,
  show_email: true,
  footer_notes: '',
  show_signature_block: true,
  signature_left_label: 'AUTHORISED SIGNATURE',
  signature_right_label: 'AUTHORISED SIGNATURE',
  show_page_number: true,
}

/**
 * Administration > Invoice Print Settings — the company-wide default for Invoice Print
 * Preview's "Print Options" dialog. Every user opening an invoice to print starts from
 * whatever is saved here; Print Preview itself still allows a same-session override plus a
 * "Reset to company default" button back to this. Purely presentational — nothing here touches
 * invoice value calculation.
 */
export function InvoicePrintSettingsPage() {
  const queryClient = useQueryClient()
  const [columns, setColumns] = useState<PrintColumnRow[]>(() => buildPrintColumnRows(ALL_PRINT_COLUMNS))
  // Keyed by paper type (A4 defaults to 12mm all sides, Continuous/Half to 6mm — their
  // pre-existing hardcoded margins) — the margin inputs below always edit whichever paper type
  // is currently selected in the Paper Type dropdown, same convention as the Print Preview
  // dialog's own advanced section.
  const [margins, setMargins] = useState<Record<PrintPaperType, PrintMargins>>(INVOICE_DEFAULT_MARGINS)

  const settingQuery = useQuery({ queryKey: ['invoice-print-setting'], queryFn: fetchInvoicePrintSetting })
  const setting = settingQuery.data ?? null

  const form = useForm<FormValues>({ resolver: zodResolver(formSchema), defaultValues: DEFAULT_VALUES })
  const selectedPaperType = form.watch('paper_type')

  useEffect(() => {
    if (!setting) return
    form.reset({
      paper_type: setting.paper_type,
      orientation: setting.orientation,
      scale_percent: String(setting.scale_percent),
      font_family: setting.font_family,
      font_size: setting.font_size,
      qty_decimals: String(setting.qty_decimals),
      price_decimals: String(setting.price_decimals),
      amount_decimals: String(setting.amount_decimals),
      number_format: setting.number_format,
      show_currency_symbol: setting.show_currency_symbol,
      show_discount: setting.show_discount,
      show_logo: setting.show_logo,
      show_address: setting.show_address,
      show_phone: setting.show_phone,
      show_email: setting.show_email,
      footer_notes: setting.footer_notes ?? '',
      show_signature_block: setting.show_signature_block,
      signature_left_label: setting.signature_left_label,
      signature_right_label: setting.signature_right_label,
      show_page_number: setting.show_page_number,
    })
    setColumns(buildPrintColumnRows(setting.visible_columns as PrintColumnKey[]))
    setMargins(setting.margins)
  }, [setting, form])

  function updateMargin(side: keyof PrintMargins, value: number) {
    setMargins((prev) => ({ ...prev, [selectedPaperType]: { ...prev[selectedPaperType], [side]: value } }))
  }

  const saveMutation = useMutation({
    mutationFn: (payload: InvoicePrintSettingPayload) => updateInvoicePrintSetting(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice-print-setting'] })
      toast.success('Invoice print settings updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const visibleColumnCount = columns.filter((c) => c.visible).length

  function toggleColumn(key: PrintColumnKey, checked: boolean) {
    // At least one column must stay active — the print table can't render with zero columns.
    if (!checked && visibleColumnCount <= 1) return
    setColumns((prev) => prev.map((c) => (c.key === key ? { ...c, visible: checked } : c)))
  }

  function moveColumn(index: number, direction: -1 | 1) {
    setColumns((prev) => {
      const next = [...prev]
      const target = index + direction
      if (target < 0 || target >= next.length) return prev
      ;[next[index], next[target]] = [next[target], next[index]]
      return next
    })
  }

  const onSubmit = (values: FormValues) => {
    saveMutation.mutate({
      paper_type: values.paper_type,
      orientation: values.orientation,
      margins,
      scale_percent: Number(values.scale_percent),
      font_family: values.font_family,
      font_size: values.font_size,
      qty_decimals: Number(values.qty_decimals),
      price_decimals: Number(values.price_decimals),
      amount_decimals: Number(values.amount_decimals),
      number_format: values.number_format,
      show_currency_symbol: values.show_currency_symbol,
      show_discount: values.show_discount,
      visible_columns: columns.filter((c) => c.visible).map((c) => c.key),
      show_logo: values.show_logo,
      show_address: values.show_address,
      show_phone: values.show_phone,
      show_email: values.show_email,
      footer_notes: values.footer_notes.trim() === '' ? null : values.footer_notes,
      show_signature_block: values.show_signature_block,
      signature_left_label: values.signature_left_label,
      signature_right_label: values.signature_right_label,
      show_page_number: values.show_page_number,
    })
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="administration" />

      <PageHeader
        title="Invoice Print Settings"
        description="Company-wide default for Invoice Print Preview's layout. Users can still override per-print; this is what they start from."
      />

      <Card className="relative max-w-3xl">
        {(settingQuery.isLoading || !setting) && <LoadingOverlay />}

        {setting && (
          <CardContent className="pt-6">
            <Form {...form}>
              <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
                <section className="flex flex-col gap-4">
                  <h3 className="text-sm font-semibold">Kertas &amp; Layout</h3>
                  <div className="grid grid-cols-2 gap-4">
                    <FormField
                      control={form.control}
                      name="paper_type"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Paper Type</FormLabel>
                          <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                              <SelectTrigger className="w-full">
                                <SelectValue />
                              </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {Object.entries(PRINT_PAPER_TYPE_LABELS).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                  {label}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="orientation"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Orientasi</FormLabel>
                          <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                              <SelectTrigger className="w-full">
                                <SelectValue />
                              </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              <SelectItem value="portrait">Portrait</SelectItem>
                              <SelectItem value="landscape">Landscape</SelectItem>
                            </SelectContent>
                          </Select>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Margin di bawah berlaku untuk Paper Type yang sedang dipilih di atas ({PRINT_PAPER_TYPE_LABELS[selectedPaperType]}) — tiap jenis
                    kertas punya setelan marginnya sendiri.
                  </p>
                  <div className="grid grid-cols-4 gap-4">
                    <div className="flex flex-col gap-1.5">
                      <label className="text-sm font-medium">Margin Atas (mm)</label>
                      <Input
                        type="number"
                        min={0}
                        max={60}
                        step="0.5"
                        value={margins[selectedPaperType].top}
                        onChange={(e) => updateMargin('top', Number(e.target.value))}
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-sm font-medium">Margin Bawah (mm)</label>
                      <Input
                        type="number"
                        min={0}
                        max={60}
                        step="0.5"
                        value={margins[selectedPaperType].bottom}
                        onChange={(e) => updateMargin('bottom', Number(e.target.value))}
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-sm font-medium">Margin Kiri (mm)</label>
                      <Input
                        type="number"
                        min={0}
                        max={60}
                        step="0.5"
                        value={margins[selectedPaperType].left}
                        onChange={(e) => updateMargin('left', Number(e.target.value))}
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-sm font-medium">Margin Kanan (mm)</label>
                      <Input
                        type="number"
                        min={0}
                        max={60}
                        step="0.5"
                        value={margins[selectedPaperType].right}
                        onChange={(e) => updateMargin('right', Number(e.target.value))}
                      />
                    </div>
                  </div>
                  <FormField
                    control={form.control}
                    name="scale_percent"
                    render={({ field }) => (
                      <FormItem className="max-w-xs">
                        <FormLabel>Skala (%)</FormLabel>
                        <FormControl>
                          <Input type="number" min={50} max={150} step="5" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </section>

                <section className="flex flex-col gap-4">
                  <h3 className="text-sm font-semibold">Teks</h3>
                  <div className="grid grid-cols-2 gap-4">
                    <FormField
                      control={form.control}
                      name="font_size"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Font Size</FormLabel>
                          <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                              <SelectTrigger className="w-full">
                                <SelectValue />
                              </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {Object.entries(PRINT_FONT_SIZE_LABELS).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                  {label}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="font_family"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Font Family</FormLabel>
                          <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                              <SelectTrigger className="w-full">
                                <SelectValue />
                              </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {FONT_FAMILY_OPTIONS.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                  {option.label}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </div>
                </section>

                <section className="flex flex-col gap-4">
                  <h3 className="text-sm font-semibold">Angka</h3>
                  <div className="grid grid-cols-3 gap-4">
                    <FormField
                      control={form.control}
                      name="qty_decimals"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Decimal Quantity</FormLabel>
                          <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                              <SelectTrigger className="w-full">
                                <SelectValue />
                              </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {DECIMAL_CHOICES.map((n) => (
                                <SelectItem key={n} value={n}>
                                  {n}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="price_decimals"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Decimal Unit Price</FormLabel>
                          <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                              <SelectTrigger className="w-full">
                                <SelectValue />
                              </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {DECIMAL_CHOICES.map((n) => (
                                <SelectItem key={n} value={n}>
                                  {n}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="amount_decimals"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Decimal Amount</FormLabel>
                          <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                              <SelectTrigger className="w-full">
                                <SelectValue />
                              </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {DECIMAL_CHOICES.map((n) => (
                                <SelectItem key={n} value={n}>
                                  {n}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </FormItem>
                      )}
                    />
                  </div>
                  <FormField
                    control={form.control}
                    name="number_format"
                    render={({ field }) => (
                      <FormItem className="max-w-xs">
                        <FormLabel>Pemisah Ribuan/Desimal</FormLabel>
                        <Select value={field.value} onValueChange={field.onChange}>
                          <FormControl>
                            <SelectTrigger className="w-full">
                              <SelectValue />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            <SelectItem value="en">1,000.00</SelectItem>
                            <SelectItem value="id">1.000,00</SelectItem>
                          </SelectContent>
                        </Select>
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="show_currency_symbol"
                    render={({ field }) => (
                      <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                        <FormLabel className="!mt-0">Tampilkan Simbol Mata Uang di Kolom Tabel</FormLabel>
                        <FormControl>
                          <Switch checked={field.value} onCheckedChange={field.onChange} />
                        </FormControl>
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="show_discount"
                    render={({ field }) => (
                      <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                        <FormLabel className="!mt-0">Tampilkan Diskon</FormLabel>
                        <FormControl>
                          <Switch checked={field.value} onCheckedChange={field.onChange} />
                        </FormControl>
                      </FormItem>
                    )}
                  />
                </section>

                <section className="flex flex-col gap-2">
                  <h3 className="text-sm font-semibold">Kolom Tabel Item</h3>
                  <p className="text-xs text-muted-foreground">Minimal satu kolom harus tetap aktif. Panah memindahkan urutan cetak kolom.</p>
                  <div className="flex flex-col gap-1">
                    {columns.map((column, index) => (
                      <div key={column.key} className="flex items-center gap-3 rounded-md border p-2">
                        <Checkbox
                          checked={column.visible}
                          onCheckedChange={(checked) => toggleColumn(column.key, checked === true)}
                          disabled={column.visible && visibleColumnCount <= 1}
                        />
                        <span className="flex-1 text-sm">{PRINT_COLUMN_LABELS[column.key]}</span>
                        <Button type="button" variant="ghost" size="icon" disabled={index === 0} onClick={() => moveColumn(index, -1)}>
                          <ArrowUp className="size-4" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          disabled={index === columns.length - 1}
                          onClick={() => moveColumn(index, 1)}
                        >
                          <ArrowDown className="size-4" />
                        </Button>
                      </div>
                    ))}
                  </div>
                </section>

                <section className="flex flex-col gap-4">
                  <h3 className="text-sm font-semibold">Header &amp; Footer</h3>
                  <div className="grid grid-cols-2 gap-3">
                    <FormField
                      control={form.control}
                      name="show_logo"
                      render={({ field }) => (
                        <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                          <FormLabel className="!mt-0">Tampilkan Logo</FormLabel>
                          <FormControl>
                            <Switch checked={field.value} onCheckedChange={field.onChange} />
                          </FormControl>
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="show_address"
                      render={({ field }) => (
                        <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                          <FormLabel className="!mt-0">Tampilkan Alamat</FormLabel>
                          <FormControl>
                            <Switch checked={field.value} onCheckedChange={field.onChange} />
                          </FormControl>
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="show_phone"
                      render={({ field }) => (
                        <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                          <FormLabel className="!mt-0">Tampilkan Telepon</FormLabel>
                          <FormControl>
                            <Switch checked={field.value} onCheckedChange={field.onChange} />
                          </FormControl>
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="show_email"
                      render={({ field }) => (
                        <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                          <FormLabel className="!mt-0">Tampilkan Email</FormLabel>
                          <FormControl>
                            <Switch checked={field.value} onCheckedChange={field.onChange} />
                          </FormControl>
                        </FormItem>
                      )}
                    />
                  </div>
                  <FormField
                    control={form.control}
                    name="footer_notes"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Catatan / Terms Footer</FormLabel>
                        <FormControl>
                          <Textarea rows={3} placeholder="Kosongkan untuk tidak menampilkan catatan tambahan" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="show_signature_block"
                    render={({ field }) => (
                      <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                        <FormLabel className="!mt-0">Tampilkan Blok Tanda Tangan</FormLabel>
                        <FormControl>
                          <Switch checked={field.value} onCheckedChange={field.onChange} />
                        </FormControl>
                      </FormItem>
                    )}
                  />
                  <div className="grid grid-cols-2 gap-4">
                    <FormField
                      control={form.control}
                      name="signature_left_label"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Label Tanda Tangan Kiri</FormLabel>
                          <FormControl>
                            <Input {...field} />
                          </FormControl>
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="signature_right_label"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Label Tanda Tangan Kanan</FormLabel>
                          <FormControl>
                            <Input {...field} />
                          </FormControl>
                        </FormItem>
                      )}
                    />
                  </div>
                  <FormField
                    control={form.control}
                    name="show_page_number"
                    render={({ field }) => (
                      <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                        <FormLabel className="!mt-0">Tampilkan Nomor Halaman</FormLabel>
                        <FormControl>
                          <Switch checked={field.value} onCheckedChange={field.onChange} />
                        </FormControl>
                      </FormItem>
                    )}
                  />
                </section>

                <div>
                  <Button type="submit" disabled={saveMutation.isPending}>
                    {saveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
                    Save Changes
                  </Button>
                </div>
              </form>
            </Form>
          </CardContent>
        )}
      </Card>
    </div>
  )
}
