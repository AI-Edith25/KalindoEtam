import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { toastApiError } from '@/shared/services/errorHandler'
import { resetPasswordRequest } from '@/shared/services/authApi'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

/**
 * Reached via the link PasswordResetMail sends (?email=...&token=...). Plain useState for the
 * password/confirm pair, not react-hook-form/Controller/zodResolver — see RegisterPage's own
 * comment for why a new-password + confirm-password pair avoids that stack in this codebase.
 */
export function ResetPasswordPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const email = searchParams.get('email') ?? ''
  const token = searchParams.get('token') ?? ''

  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: () => resetPasswordRequest({ email, token, password, password_confirmation: confirmPassword }),
    onSuccess: () => {
      toast.success('Password berhasil diubah. Silakan login dengan password baru Anda.')
      navigate('/login', { replace: true })
    },
    onError: (apiError) => toastApiError(apiError),
  })

  const onSubmit = (event: React.FormEvent) => {
    event.preventDefault()

    if (password.length < 8) {
      setError('Minimal 8 karakter')
      return
    }
    if (password !== confirmPassword) {
      setError('Konfirmasi password tidak cocok')
      return
    }

    setError(null)
    mutation.mutate()
  }

  if (!email || !token) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-muted p-4">
        <Card className="w-full max-w-sm">
          <CardHeader>
            <CardTitle>Link Tidak Valid</CardTitle>
            <CardDescription>Link reset password ini tidak lengkap atau sudah tidak valid.</CardDescription>
          </CardHeader>
          <CardContent>
            <Link to="/forgot-password" className="text-sm underline-offset-4 hover:underline">
              Minta link reset password baru
            </Link>
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <div className="flex min-h-svh items-center justify-center bg-muted p-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle>Reset Password</CardTitle>
          <CardDescription>Masukkan password baru untuk akun {email}.</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={onSubmit} className="space-y-4" noValidate>
            <div className="grid gap-2">
              <Label htmlFor="reset-new-password">Password Baru</Label>
              <Input
                id="reset-new-password"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="reset-confirm-password">Konfirmasi Password Baru</Label>
              <Input
                id="reset-confirm-password"
                type="password"
                autoComplete="new-password"
                value={confirmPassword}
                onChange={(event) => setConfirmPassword(event.target.value)}
              />
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <Button type="submit" className="w-full" disabled={mutation.isPending}>
              {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Simpan Password Baru
            </Button>
          </form>

          <p className="mt-4 text-center text-sm text-muted-foreground">
            <Link to="/login" className="underline-offset-4 hover:underline">
              Kembali ke halaman login
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
