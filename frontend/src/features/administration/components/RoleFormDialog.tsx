import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { toastApiError } from '@/shared/services/errorHandler'
import { createRole } from '../api/roleApi'

interface RoleFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function RoleFormDialog({ open, onOpenChange }: RoleFormDialogProps) {
  const queryClient = useQueryClient()
  const [name, setName] = useState('')

  const mutation = useMutation({
    mutationFn: () => createRole(name),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      toast.success('Role created.')
      setName('')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New Role</DialogTitle>
          <DialogDescription>Give the role a name, then assign its permissions from the role list.</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-2">
          <Label htmlFor="role-name">Role Name</Label>
          <Input id="role-name" value={name} onChange={(event) => setName(event.target.value)} placeholder="e.g. Accountant" />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button onClick={() => mutation.mutate()} disabled={!name.trim() || mutation.isPending}>
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            Create Role
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
