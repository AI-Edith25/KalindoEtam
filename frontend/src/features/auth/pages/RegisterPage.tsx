import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { toastApiError } from '@/shared/services/errorHandler'
import { registerRequest } from '@/shared/services/authApi'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

/**
 * Public self-registration — new accounts are created inactive, no role, pending Admin approval
 * via the existing Admin > Users Activate action (see AuthService::register on the backend).
 *
 * Plain useState throughout, not react-hook-form/Controller/zodResolver — a new-password +
 * confirm-password pair bound through Controller with a cross-field refine previously caused an
 * unexplained value-reset-on-every-keystroke bug in production on this exact field shape (see
 * ForgotPasswordPage's own reset step, and feedback memory on this). Matching that established
 * plain-useState pattern here rather than risking the same bug on a brand-new form.
 */
export function RegisterPage() {
  const navigate = useNavigate()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const onSubmit = async (event: React.FormEvent) => {
    event.preventDefault()

    if (!name.trim()) {
      setError('Nama wajib diisi')
      return
    }
    if (!email.trim()) {
      setError('Email wajib diisi')
      return
    }
    if (password.length < 8) {
      setError('Password minimal 8 karakter')
      return
    }
    if (password !== confirmPassword) {
      setError('Konfirmasi password tidak cocok')
      return
    }

    setError(null)
    setIsSubmitting(true)
    try {
      await registerRequest({ name, email, password, password_confirmation: confirmPassword })
      toast.success('Registrasi berhasil. Akun Anda menunggu persetujuan Admin.')
      navigate('/login', { replace: true })
    } catch (apiError) {
      toastApiError(apiError)
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-svh items-center justify-center bg-muted p-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle>Daftar Akun</CardTitle>
          <CardDescription>Akun Anda akan menunggu persetujuan Admin sebelum bisa digunakan.</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={onSubmit} className="space-y-4" noValidate>
            <div className="grid gap-2">
              <Label htmlFor="register-name">Nama</Label>
              <Input id="register-name" autoComplete="name" value={name} onChange={(event) => setName(event.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="register-email">Email</Label>
              <Input
                id="register-email"
                type="email"
                autoComplete="username"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="register-password">Password</Label>
              <Input
                id="register-password"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="register-confirm-password">Konfirmasi Password</Label>
              <Input
                id="register-confirm-password"
                type="password"
                autoComplete="new-password"
                value={confirmPassword}
                onChange={(event) => setConfirmPassword(event.target.value)}
              />
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <Button type="submit" className="w-full" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              Daftar
            </Button>
          </form>

          <p className="mt-4 text-center text-sm text-muted-foreground">
            Sudah punya akun?{' '}
            <Link to="/login" className="underline-offset-4 hover:underline">
              Masuk
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
