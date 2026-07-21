import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { assignUserRole } from '../api/userApi'
import { fetchRoles } from '../api/roleApi'
import type { User } from '../types'

interface AssignRoleDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  user: User | null
}

export function AssignRoleDialog({ open, onOpenChange, user }: AssignRoleDialogProps) {
  const queryClient = useQueryClient()
  const rolesQuery = useQuery({ queryKey: ['roles'], queryFn: fetchRoles, enabled: open })
  const [role, setRole] = useState<string>('')

  useEffect(() => {
    if (open && user) setRole(user.roles[0] ?? '')
  }, [open, user])

  const mutation = useMutation({
    mutationFn: () => assignUserRole(user!.id, role),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users-paged'] })
      toast.success('Role assigned.')
      onOpenChange(false)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  if (!user) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Assign Role</DialogTitle>
          <DialogDescription>Choose the role {user.email} should have.</DialogDescription>
        </DialogHeader>

        <Select value={role} onValueChange={setRole}>
          <SelectTrigger className="w-full">
            <SelectValue placeholder="Select role" />
          </SelectTrigger>
          <SelectContent>
            {(rolesQuery.data ?? []).map((r) => (
              <SelectItem key={r.id} value={r.name}>
                {r.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button onClick={() => mutation.mutate()} disabled={!role || mutation.isPending}>
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            Assign
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
