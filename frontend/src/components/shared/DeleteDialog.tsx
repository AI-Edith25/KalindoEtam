import { ConfirmationDialog } from './ConfirmationDialog'

interface DeleteDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  itemLabel?: string
  onConfirm: () => void | Promise<void>
}

/** Destructive-action preset of ConfirmationDialog, used for every delete action across modules. */
export function DeleteDialog({ open, onOpenChange, itemLabel, onConfirm }: DeleteDialogProps) {
  return (
    <ConfirmationDialog
      open={open}
      onOpenChange={onOpenChange}
      title={itemLabel ? `Delete ${itemLabel}?` : 'Delete this record?'}
      description="This action cannot be undone."
      confirmLabel="Delete"
      variant="destructive"
      onConfirm={onConfirm}
    />
  )
}
