import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import { Loader2, MailCheck } from 'lucide-react'
import { toastApiError } from '@/shared/services/errorHandler'
import { forgotPasswordRequest } from '@/shared/services/authApi'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'

const emailSchema = z.object({
  email: z.string().min(1, 'Email wajib diisi').email('Masukkan email yang valid'),
})
type EmailFormValues = z.infer<typeof emailSchema>

/**
 * Phase 2 — email actually goes out now (see AuthService::forgotPassword, PasswordResetMail). This
 * page only collects the email and always shows the same confirmation regardless of whether the
 * account exists, preserving the anti-enumeration property the API itself already guarantees (a
 * generic response here would be undone by a page that behaved differently per outcome). Setting
 * the new password itself happens on ResetPasswordPage, reached via the link the email contains.
 */
export function ForgotPasswordPage() {
  const [submitted, setSubmitted] = useState(false)

  const form = useForm<EmailFormValues>({
    resolver: zodResolver(emailSchema),
    defaultValues: { email: '' },
  })

  const mutation = useMutation({
    mutationFn: (values: EmailFormValues) => forgotPasswordRequest(values),
    onSuccess: () => setSubmitted(true),
    onError: (error) => toastApiError(error),
  })

  return (
    <div className="flex min-h-svh items-center justify-center bg-muted p-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle>Lupa Password</CardTitle>
          <CardDescription>
            {submitted ? 'Silakan cek inbox email Anda.' : 'Masukkan email akun Anda untuk menerima link reset password.'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {submitted ? (
            <div className="flex flex-col items-center gap-2 py-4 text-center text-sm text-muted-foreground">
              <MailCheck className="size-8" />
              <p>Jika email terdaftar, link reset password telah dikirim. Link berlaku selama 60 menit.</p>
            </div>
          ) : (
            <Form {...form}>
              <form onSubmit={form.handleSubmit((values) => mutation.mutate(values))} className="space-y-4" noValidate>
                <FormField
                  control={form.control}
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
                <Button type="submit" className="w-full" disabled={mutation.isPending}>
                  {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                  Kirim Link Reset
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
