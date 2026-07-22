import { useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Upload, Building2 } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { LoadingOverlay } from '@/components/shared/LoadingOverlay'
import { toastApiError } from '@/shared/services/errorHandler'
import {
  deleteCompanyLogo,
  fetchCompanyLogo,
  fetchCompanyLogoObjectUrl,
  fetchCurrentCompany,
  updateCompany,
  uploadCompanyLogo,
} from '../api/companyApi'
import { administrationNav } from '../navigation'
import type { CompanyFormValues } from '../types'

const companyFormSchema = z.object({
  name: z.string().min(1, 'Company Name is required').max(255),
  code: z.string().min(1, 'Code is required').max(255),
  address: z.string().max(255).nullable(),
  phone: z.string().max(255).nullable(),
  email: z.string().email('Must be a valid email').max(255).nullable().or(z.literal('')),
  npwp: z.string().max(255).nullable(),
  fiscal_year_start: z.string().min(1, 'Fiscal Year Start is required'),
})

export function CompanyPage() {
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [logoObjectUrl, setLogoObjectUrl] = useState<string | null>(null)

  const companyQuery = useQuery({ queryKey: ['company'], queryFn: fetchCurrentCompany })
  const company = companyQuery.data ?? null

  const logoQuery = useQuery({
    queryKey: ['company-logo', company?.id],
    queryFn: () => fetchCompanyLogo(company!.id),
    enabled: !!company,
  })
  const logo = logoQuery.data ?? null

  useEffect(() => {
    if (!logo) {
      setLogoObjectUrl(null)
      return
    }

    let cancelled = false
    fetchCompanyLogoObjectUrl(logo.id).then((url) => {
      if (!cancelled) setLogoObjectUrl(url)
    })

    return () => {
      cancelled = true
    }
  }, [logo])

  const form = useForm<CompanyFormValues>({
    resolver: zodResolver(companyFormSchema),
    defaultValues: { name: '', code: '', address: '', phone: '', email: '', npwp: '', fiscal_year_start: '' },
  })

  useEffect(() => {
    if (!company) return

    form.reset({
      name: company.name,
      code: company.code,
      address: company.address ?? '',
      phone: company.phone ?? '',
      email: company.email ?? '',
      npwp: company.npwp ?? '',
      fiscal_year_start: company.fiscal_year_start,
    })
  }, [company, form])

  const saveMutation = useMutation({
    mutationFn: (values: CompanyFormValues) => updateCompany(company!.id, values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['company'] })
      toast.success('Company updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const uploadLogoMutation = useMutation({
    mutationFn: async (file: File) => {
      // A logo is conceptually one image — replace, not accumulate, since
      // DocumentAttachment itself supports many. See docs/ADMINISTRATION_DESIGN.md
      // §3 Open Question 1.
      if (logo) await deleteCompanyLogo(logo.id)
      return uploadCompanyLogo(company!.id, file)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['company-logo', company?.id] })
      toast.success('Logo updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: CompanyFormValues) => saveMutation.mutate(values)

  const handleLogoChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (file) uploadLogoMutation.mutate(file)
    event.target.value = ''
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={administrationNav} />

      <PageHeader title="Company" description="Your organization's identity, contact details, and fiscal calendar — shown across the app and on printed documents." />

      <Card className="relative max-w-2xl">
        {(companyQuery.isLoading || !company) && <LoadingOverlay />}

        {company && (
          <CardContent className="flex flex-col gap-6">
            <div className="flex items-center gap-4">
              <div className="flex size-16 items-center justify-center overflow-hidden rounded-md border bg-muted">
                {logoObjectUrl ? (
                  <img src={logoObjectUrl} alt="Company logo" className="size-full object-contain" />
                ) : (
                  <Building2 className="size-6 text-muted-foreground" />
                )}
              </div>
              <div className="flex flex-col gap-2">
                <input ref={fileInputRef} type="file" accept="image/*" className="hidden" onChange={handleLogoChange} />
                <Button type="button" variant="outline" size="sm" onClick={() => fileInputRef.current?.click()} disabled={uploadLogoMutation.isPending}>
                  {uploadLogoMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Upload className="size-4" />}
                  {logo ? 'Replace Logo' : 'Upload Logo'}
                </Button>
                <p className="text-xs text-muted-foreground">PNG or JPG, shown in the sidebar and on printed documents.</p>
              </div>
            </div>

            <Form {...form}>
              <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
                <div className="grid grid-cols-2 gap-4">
                  <FormField
                    control={form.control}
                    name="name"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Company Name</FormLabel>
                        <FormControl>
                          <Input {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="code"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Code</FormLabel>
                        <FormControl>
                          <Input {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>

                <FormField
                  control={form.control}
                  name="address"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Address</FormLabel>
                      <FormControl>
                        <Textarea {...field} value={field.value ?? ''} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <div className="grid grid-cols-2 gap-4">
                  <FormField
                    control={form.control}
                    name="phone"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Phone</FormLabel>
                        <FormControl>
                          <Input {...field} value={field.value ?? ''} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="email"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Email</FormLabel>
                        <FormControl>
                          <Input type="email" {...field} value={field.value ?? ''} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <FormField
                    control={form.control}
                    name="npwp"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>NPWP</FormLabel>
                        <FormControl>
                          <Input placeholder="e.g. 01.234.567.8-901.000" {...field} value={field.value ?? ''} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="fiscal_year_start"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Fiscal Year Start</FormLabel>
                        <FormControl>
                          <Input type="date" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>

                <div>
                  <Button type="submit" disabled={saveMutation.isPending}>
                    {saveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
                    Save Changes
                  </Button>
                </div>
              </form>
            </Form>
          </CardContent>
        )}
      </Card>
    </div>
  )
}
