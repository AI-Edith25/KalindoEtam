import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage, FormDescription } from '@/components/ui/form'
import { LoadingOverlay } from '@/components/shared/LoadingOverlay'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchPurchaseSetting, updatePurchaseSetting } from '../api/purchaseSettingApi'

const purchaseSettingFormSchema = z.object({
  weight_over_receipt_tolerance_percent: z.string(),
})

type PurchaseSettingFormValues = z.infer<typeof purchaseSettingFormSchema>

export function PurchaseSettingsPage() {
  const queryClient = useQueryClient()

  const settingQuery = useQuery({ queryKey: ['purchase-setting'], queryFn: fetchPurchaseSetting })
  const setting = settingQuery.data ?? null

  const form = useForm<PurchaseSettingFormValues>({
    resolver: zodResolver(purchaseSettingFormSchema),
    defaultValues: { weight_over_receipt_tolerance_percent: '10' },
  })

  useEffect(() => {
    if (!setting) return
    form.reset({ weight_over_receipt_tolerance_percent: setting.weight_over_receipt_tolerance_percent?.toString() ?? '' })
  }, [setting, form])

  const saveMutation = useMutation({
    mutationFn: (values: PurchaseSettingFormValues) =>
      updatePurchaseSetting({
        weight_over_receipt_tolerance_percent: values.weight_over_receipt_tolerance_percent.trim() === ''
          ? null
          : Number(values.weight_over_receipt_tolerance_percent),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['purchase-setting'] })
      toast.success('Purchase settings updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: PurchaseSettingFormValues) => saveMutation.mutate(values)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="administration" />

      <PageHeader
        title="Purchase Settings"
        description="Rules applied across Purchase — Goods Receipt, Purchase Return, and Purchase Invoice."
      />

      <Card className="relative max-w-2xl">
        {(settingQuery.isLoading || !setting) && <LoadingOverlay />}

        {setting && (
          <CardContent>
            <Form {...form}>
              <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
                <FormField
                  control={form.control}
                  name="weight_over_receipt_tolerance_percent"
                  render={({ field }) => (
                    <FormItem className="max-w-xs">
                      <FormLabel>Weight Over-Receipt Tolerance (%)</FormLabel>
                      <FormControl>
                        <Input type="number" min={0} step="0.01" placeholder="10" {...field} />
                      </FormControl>
                      <FormDescription>
                        For Weight-category items, receiving beyond this % over the outstanding PO qty asks for
                        confirmation instead of saving straight away — a safeguard against typos, since truck-scale
                        results legitimately vary a little from the ordered qty. Leave empty or 0 for no limit.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

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
