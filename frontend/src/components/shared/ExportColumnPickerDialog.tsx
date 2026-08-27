import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'

export interface ExportColumn {
  key: string
  label: string
}

interface ExportColumnPickerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  columns: ExportColumn[]
  /** e.g. "3 dipilih" vs "N hasil filter" — shown in the description so the user knows what they're about to export. */
  targetDescription: string
  onConfirm: (selectedKeys: string[]) => void
}

/**
 * "Kolom mengikuti kolom tabel yang tampil; sediakan opsi pilih kolom yang
 * di-export" — one reusable picker for every Sales module's bulk export
 * (Excel/CSV/PDF). Defaults to every column selected (matches the on-screen
 * table); unchecking narrows what SalesListExport (backend) renders.
 */
export function ExportColumnPickerDialog({ open, onOpenChange, columns, targetDescription, onConfirm }: ExportColumnPickerDialogProps) {
  const [selected, setSelected] = useState<Set<string>>(new Set(columns.map((column) => column.key)))

  useEffect(() => {
    if (open) setSelected(new Set(columns.map((column) => column.key)))
  }, [open, columns])

  const toggle = (key: string) => {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Pilih Kolom Export</DialogTitle>
          <DialogDescription>{targetDescription}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-2 py-2">
          {columns.map((column) => (
            <label key={column.key} className="flex items-center gap-2 text-sm">
              <Checkbox checked={selected.has(column.key)} onCheckedChange={() => toggle(column.key)} />
              {column.label}
            </label>
          ))}
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            type="button"
            disabled={selected.size === 0}
            onClick={() => {
              onConfirm(columns.filter((column) => selected.has(column.key)).map((column) => column.key))
              onOpenChange(false)
            }}
          >
            Export
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
