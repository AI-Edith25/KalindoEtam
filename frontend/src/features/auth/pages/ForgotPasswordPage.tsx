import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { toastApiError } from '@/shared/services/errorHandler'
import { resetPasswordUnverifiedRequest } from '@/shared/services/authApi'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

const emailSchema = z.object({
  email: z.string().min(1, 'Email wajib diisi').email('Masukkan email yang valid'),
})
type EmailFormValues = z.infer<typeof emailSchema>

/**
 * Phase 1 (no email/OTP verification, known temporary trade-off) — the email step never
 * calls the backend, so a non-existent email reaches the exact same reset form as a real
 * one. Only the final submit hits the API, which always returns the same generic response.
 */
export function ForgotPasswordPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState<'email' | 'reset'>('email')
  const [email, setEmail] = useState('')

  const emailForm = useForm<EmailFormValues>({
    resolver: zodResolver(emailSchema),
    defaultValues: { email: '' },
  })

  // Plain useState here (not react-hook-form) — matches RoleFormDialog.tsx's pattern.
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [resetError, setResetError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: () => resetPasswordUnverifiedRequest({ email, password: newPassword, password_confirmation: confirmPassword }),
    onSuccess: () => {
      toast.success('Password berhasil diubah. Silakan login dengan password baru Anda.')
      navigate('/login', { replace: true })
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmitEmail = (values: EmailFormValues) => {
    setEmail(values.email)
    setStep('reset')
  }

  const onSubmitReset = (event: React.FormEvent) => {
    event.preventDefault()

    if (newPassword.length < 8) {
      setResetError('Minimal 8 karakter')
      return
    }
    if (newPassword !== confirmPassword) {
      setResetError('Konfirmasi password tidak cocok')
      return
    }

    setResetError(null)
    mutation.mutate()
  }

  return (
    <div className="flex min-h-svh items-center justify-center bg-muted p-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle>Lupa Password</CardTitle>
          <CardDescription>
            {step === 'email'
              ? 'Masukkan email akun Anda untuk melanjutkan.'
              : 'Jika email terdaftar, silakan lanjutkan proses berikut untuk mengatur password baru.'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {step === 'email' ? (
            <Form {...emailForm}>
              <form onSubmit={emailForm.handleSubmit(onSubmitEmail)} className="space-y-4" noValidate>
                <FormField
                  control={emailForm.control}
                  name="email"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Email</FormLabel>
                      <FormControl>
                        <Input type="email" autoComplete="username" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <Button type="submit" className="w-full">
                  Lanjutkan
                </Button>
              </form>
            </Form>
          ) : (
            <form onSubmit={onSubmitReset} className="space-y-4" noValidate>
              <div className="grid gap-2">
                <Label htmlFor="reset-new-password">Password Baru</Label>
                <Input
                  id="reset-new-password"
                  type="password"
                  autoComplete="off"
                  value={newPassword}
                  onChange={(event) => setNewPassword(event.target.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="reset-confirm-password">Konfirmasi Password Baru</Label>
                <Input
                  id="reset-confirm-password"
                  type="password"
                  autoComplete="off"
                  value={confirmPassword}
                  onChange={(event) => setConfirmPassword(event.target.value)}
                />
              </div>
              {resetError && <p className="text-sm text-destructive">{resetError}</p>}
              <Button type="submit" className="w-full" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                Simpan Password Baru
              </Button>
            </form>
          )}

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
