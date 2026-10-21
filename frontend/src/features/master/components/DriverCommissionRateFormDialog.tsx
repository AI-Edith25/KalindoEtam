import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { searchItemsLookup } from '@/features/master/api/lookupsApi'
import { toastApiError } from '@/shared/services/errorHandler'
import { createDriverCommissionRate } from '../api/driverCommissionRateApi'

interface DriverCommissionRateFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  fleetId: string
}

/** Rates are append-only history (see DriverCommissionRateService::create()) — this dialog only ever inserts, never edits. */
export function DriverCommissionRateFormDialog({ open, onOpenChange, fleetId }: DriverCommissionRateFormDialogProps) {
  const queryClient = useQueryClient()
  const [itemId, setItemId] = useState('')
  const [itemLabel, setItemLabel] = useState('')
  const [ratePerZak, setRatePerZak] = useState('')
  const [effectiveDate, setEffectiveDate] = useState('')

  const reset = () => {
    setItemId('')
    setItemLabel('')
    setRatePerZak('')
    setEffectiveDate('')
  }

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: `${item.item_code} — ${item.item_name}` }))
  }

  const mutation = useMutation({
    mutationFn: () =>
      createDriverCommissionRate({
        fleet_id: fleetId,
        item_id: itemId,
        rate_per_zak: Number(ratePerZak),
        effective_date: effectiveDate,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['driver-commission-rates', fleetId] })
      toast.success('Commission rate added.')
      reset()
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const canSubmit = !!itemId && !!ratePerZak && !!effectiveDate

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) reset(); onOpenChange(next) }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add Commission Rate</DialogTitle>
          <DialogDescription>Adds a new tariff entry — existing history for this item is kept, never overwritten.</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Item</label>
            <SearchableSelect
              loadOptions={loadItemOptions}
              selectedOption={itemId ? { value: itemId, label: itemLabel } : undefined}
              value={itemId || undefined}
              onChange={(value, option) => {
                setItemId(value ?? '')
                setItemLabel(option?.label ?? '')
              }}
              placeholder="Select item"
              aria-label="Item"
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Rate / Zak (Rp)</label>
            <RupiahInput value={ratePerZak} onChange={setRatePerZak} aria-label="Rate per zak" />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Effective Date</label>
            <Input type="date" value={effectiveDate} onChange={(e) => setEffectiveDate(e.target.value)} />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button onClick={() => mutation.mutate()} disabled={!canSubmit || mutation.isPending}>
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            Add Rate
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
