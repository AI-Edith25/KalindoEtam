import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { useAuth } from '@/app/AuthContext'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { toastApiError } from '@/shared/services/errorHandler'
import { changePasswordRequest } from '@/shared/services/authApi'

interface ChangePasswordDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

/** Revokes all sessions on success, so we redirect straight to /login instead of hitting /auth/logout (already-revoked token). */
export function ChangePasswordDialog({ open, onOpenChange }: ChangePasswordDialogProps) {
  const { clearSession } = useAuth()
  const navigate = useNavigate()

  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
      setError(null)
    }
  }, [open])

  const mutation = useMutation({
    mutationFn: () =>
      changePasswordRequest({
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      }),
    onSuccess: () => {
      toast.success('Password berhasil diubah. Silakan login kembali.')
      onOpenChange(false)
      clearSession()
      navigate('/login', { replace: true })
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (event: React.FormEvent) => {
    event.preventDefault()

    if (!currentPassword) {
      setError('Password lama wajib diisi')
      return
    }
    if (newPassword.length < 8) {
      setError('Minimal 8 karakter')
      return
    }
    if (newPassword !== confirmPassword) {
      setError('Konfirmasi password tidak cocok')
      return
    }

    setError(null)
    mutation.mutate()
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Ubah Password</DialogTitle>
          <DialogDescription>Mengubah password akan mengeluarkan Anda dari semua sesi aktif.</DialogDescription>
        </DialogHeader>

        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="change-current-password">Password Lama</Label>
            <Input
              id="change-current-password"
              type="password"
              autoComplete="current-password"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="change-new-password">Password Baru</Label>
            <Input
              id="change-new-password"
              type="password"
              autoComplete="off"
              value={newPassword}
              onChange={(event) => setNewPassword(event.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="change-confirm-password">Konfirmasi Password Baru</Label>
            <Input
              id="change-confirm-password"
              type="password"
              autoComplete="off"
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
            />
          </div>
          {error && <p className="text-sm text-destructive">{error}</p>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
              Batal
            </Button>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Simpan
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
