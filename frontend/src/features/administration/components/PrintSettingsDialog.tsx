import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { toastApiError } from '@/shared/services/errorHandler'
import { DOTMATRIX_HALF_DEFAULTS, FONT_FAMILY_OPTIONS, PRINT_PAPER_TYPE_LABELS, type PrintPaperType } from '@/shared/lib/printOptions'
import { fetchUserPrintSettings, saveUserPrintSetting, type PrintSettingDocumentType } from '@/shared/lib/printSettingApi'
import type { User } from '../types'

interface PrintSettingsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  user: User | null
  /** administration.print_settings.update — the backend enforces this regardless (403), this only disables the Save button so the admin isn't surprised by a silent failure. */
  canUpdate: boolean
}

/** Which paper types each document's own print page actually offers — kept in lockstep with DeliveryPrintPage.tsx/InvoicePrintPage.tsx's own `paperTypeOptions`. */
const PAPER_TYPE_OPTIONS: Record<PrintSettingDocumentType, PrintPaperType[]> = {
  'delivery-order': ['a4', 'half', 'dotmatrix_half'],
  invoice: ['a4', 'half', 'continuous', 'roll', 'dotmatrix_half'],
}

interface SectionState {
  paperType: PrintPaperType
  fontFamily: string
  dotMatrixHeightMm: number
  dotMatrixOffsetLeftMm: number
  dotMatrixOffsetTopMm: number
}

const emptySection = (): SectionState => ({
  paperType: 'a4',
  fontFamily: FONT_FAMILY_OPTIONS[3].value,
  dotMatrixHeightMm: DOTMATRIX_HALF_DEFAULTS.heightMm,
  dotMatrixOffsetLeftMm: DOTMATRIX_HALF_DEFAULTS.offsetLeftMm,
  dotMatrixOffsetTopMm: DOTMATRIX_HALF_DEFAULTS.offsetTopMm,
})

/**
 * Admin-only trial-and-error of another user's Delivery Order / Invoice print settings — the
 * whole reason a stakeholder's dot-matrix paper size/offset can be tuned without touching that
 * user's own browser. Deliberately a narrower field set than the print-time PrintOptionsDialog
 * (Paper Type, Font Style, and — only for the dot-matrix paper type — the 3 tuning numbers): every
 * other PrintOptions field (decimals, discount/tax, signature labels) isn't part of "does this fit
 * the stakeholder's printer" and stays print-time-only, exactly like the ticket asks for.
 *
 * Font Size is intentionally NOT here — neither DeliveryPrintPage nor InvoicePrintPage actually
 * reads it (`showFontSize={false}` on both, since Half/A4 typography is locked to fixed pt values)
 * so a Font Size control here would be a dead knob. Font Style (family) is the real, working lever.
 */
export function PrintSettingsDialog({ open, onOpenChange, user, canUpdate }: PrintSettingsDialogProps) {
  const settingsQuery = useQuery({
    queryKey: ['users', user?.id, 'print-settings'],
    queryFn: () => fetchUserPrintSettings(user!.id),
    enabled: open && !!user,
  })
  const [sections, setSections] = useState<Record<PrintSettingDocumentType, SectionState>>({
    'delivery-order': emptySection(),
    invoice: emptySection(),
  })
  const [activeDocumentType, setActiveDocumentType] = useState<PrintSettingDocumentType>('delivery-order')

  useEffect(() => {
    if (!settingsQuery.data) return
    setSections({
      'delivery-order': { ...emptySection(), ...settingsQuery.data['delivery-order'] },
      invoice: { ...emptySection(), ...settingsQuery.data.invoice },
    })
  }, [settingsQuery.data])

  const mutation = useMutation({
    mutationFn: ({ documentType, section }: { documentType: PrintSettingDocumentType; section: SectionState }) =>
      saveUserPrintSetting(user!.id, documentType, section),
    onSuccess: () => toast.success('Print settings saved.'),
    onError: (error) => toastApiError(error),
  })

  const updateSection = (documentType: PrintSettingDocumentType, patch: Partial<SectionState>) => {
    setSections((prev) => ({ ...prev, [documentType]: { ...prev[documentType], ...patch } }))
  }

  if (!user) return null

  const renderSection = (documentType: PrintSettingDocumentType) => {
    const section = sections[documentType]
    return (
      <div className="flex flex-col gap-4 pt-4">
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Paper Type</label>
          <Select value={section.paperType} onValueChange={(value) => updateSection(documentType, { paperType: value as PrintPaperType })}>
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {PAPER_TYPE_OPTIONS[documentType].map((value) => (
                <SelectItem key={value} value={value}>
                  {PRINT_PAPER_TYPE_LABELS[value]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Font Style</label>
          <Select value={section.fontFamily} onValueChange={(value) => updateSection(documentType, { fontFamily: value })}>
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

        {section.paperType === 'dotmatrix_half' && (
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Dot Matrix Tuning (mm)</label>
            <div className="grid grid-cols-3 gap-2">
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">Sheet Height</span>
                <Input
                  type="number"
                  value={section.dotMatrixHeightMm}
                  onChange={(e) => updateSection(documentType, { dotMatrixHeightMm: Number(e.target.value) })}
                />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">Offset Left</span>
                <Input
                  type="number"
                  value={section.dotMatrixOffsetLeftMm}
                  onChange={(e) => updateSection(documentType, { dotMatrixOffsetLeftMm: Number(e.target.value) })}
                />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">Offset Top</span>
                <Input
                  type="number"
                  value={section.dotMatrixOffsetTopMm}
                  onChange={(e) => updateSection(documentType, { dotMatrixOffsetTopMm: Number(e.target.value) })}
                />
              </div>
            </div>
          </div>
        )}

        <Button
          className="self-start"
          onClick={() => mutation.mutate({ documentType, section })}
          disabled={!canUpdate || mutation.isPending}
        >
          {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
          Save
        </Button>
      </div>
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Print Settings</DialogTitle>
          <DialogDescription>Tune {user.name}'s Delivery Order / Invoice print — e.g. for a stakeholder's dot-matrix printer.</DialogDescription>
        </DialogHeader>

        {settingsQuery.isLoading ? (
          <div className="flex justify-center py-8">
            <Loader2 className="size-6 animate-spin text-muted-foreground" />
          </div>
        ) : (
          <div className="flex flex-col gap-2">
            <div className="flex gap-2">
              <Button
                type="button"
                variant={activeDocumentType === 'delivery-order' ? 'default' : 'outline'}
                onClick={() => setActiveDocumentType('delivery-order')}
              >
                Delivery Order
              </Button>
              <Button type="button" variant={activeDocumentType === 'invoice' ? 'default' : 'outline'} onClick={() => setActiveDocumentType('invoice')}>
                Invoice
              </Button>
            </div>
            {renderSection(activeDocumentType)}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
