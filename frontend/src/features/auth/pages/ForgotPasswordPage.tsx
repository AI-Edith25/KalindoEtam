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

const emailSchema = z.object({
  email: z.string().min(1, 'Email wajib diisi').email('Masukkan email yang valid'),
})
type EmailFormValues = z.infer<typeof emailSchema>

const resetSchema = z
  .object({
    newPassword: z.string().min(8, 'Minimal 8 karakter'),
    confirmPassword: z.string().min(1, 'Konfirmasi password wajib diisi'),
  })
  .refine((data) => data.newPassword === data.confirmPassword, {
    message: 'Konfirmasi password tidak cocok',
    path: ['confirmPassword'],
  })
type ResetFormValues = z.infer<typeof resetSchema>

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

  const resetForm = useForm<ResetFormValues>({
    resolver: zodResolver(resetSchema),
    defaultValues: { newPassword: '', confirmPassword: '' },
  })

  const mutation = useMutation({
    mutationFn: (values: ResetFormValues) =>
      resetPasswordUnverifiedRequest({ email, password: values.newPassword, password_confirmation: values.confirmPassword }),
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

  const onSubmitReset = (values: ResetFormValues) => mutation.mutate(values)

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
            <Form {...resetForm}>
              <form onSubmit={resetForm.handleSubmit(onSubmitReset)} className="space-y-4" noValidate>
                <FormField
                  control={resetForm.control}
                  name="newPassword"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Password Baru</FormLabel>
                      <FormControl>
                        {/* autoComplete="off" (not "new-password") — Chrome's "suggest a strong password" popup for new-password fields can swallow real keystrokes/paste until dismissed. */}
                        <Input type="password" autoComplete="off" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={resetForm.control}
                  name="confirmPassword"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Konfirmasi Password Baru</FormLabel>
                      <FormControl>
                        <Input type="password" autoComplete="off" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <Button type="submit" className="w-full" disabled={mutation.isPending}>
                  {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                  Simpan Password Baru
                </Button>
              </form>
            </Form>
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
