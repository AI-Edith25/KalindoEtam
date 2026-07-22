import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Check, Copy, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { toastApiError } from '@/shared/services/errorHandler'
import { resetUserPassword } from '../api/userApi'
import type { User } from '../types'

interface ResetPasswordDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  user: User | null
}

/** Shows the new temporary password exactly once — no email infra exists to send a reset link instead (docs/ADMINISTRATION_DESIGN.md Open Question 4). */
export function ResetPasswordDialog({ open, onOpenChange, user }: ResetPasswordDialogProps) {
  const [newPassword, setNewPassword] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  const mutation = useMutation({
    mutationFn: () => resetUserPassword(user!.id),
    onSuccess: (password) => setNewPassword(password),
    onError: (error) => toastApiError(error),
  })

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setNewPassword(null)
      setCopied(false)
    }
    onOpenChange(next)
  }

  const handleCopy = async () => {
    if (!newPassword) return
    await navigator.clipboard.writeText(newPassword)
    setCopied(true)
  }

  if (!user) return null

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Reset Password</DialogTitle>
          <DialogDescription>
            {newPassword
              ? 'Share this temporary password with the user securely — it will not be shown again.'
              : `Generate a new temporary password for ${user.email}. Their current sessions will be signed out.`}
          </DialogDescription>
        </DialogHeader>

        {newPassword && (
          <div className="flex items-center gap-2">
            <Input readOnly value={newPassword} className="font-mono" />
            <Button type="button" variant="outline" size="icon" onClick={handleCopy}>
              {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
            </Button>
          </div>
        )}

        <DialogFooter>
          {newPassword ? (
            <Button onClick={() => handleOpenChange(false)}>Done</Button>
          ) : (
            <>
              <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={mutation.isPending}>
                Cancel
              </Button>
              <Button onClick={() => mutation.mutate()} disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                Generate New Password
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
