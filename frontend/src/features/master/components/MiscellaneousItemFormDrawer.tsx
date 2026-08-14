import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { toastApiError } from '@/shared/services/errorHandler'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { createMiscellaneousItem, updateMiscellaneousItem } from '../api/miscellaneousItemApi'
import { fetchUoms } from '../api/lookupsApi'
import { CHARGE_TYPE_LABELS } from '../pages/MiscellaneousItemListPage'
import type { MiscellaneousItem } from '../types'

const miscellaneousItemFormSchema = z.object({
  misc_code: z.string().min(1, 'Misc Code is required').max(255),
  description: z.string().min(1, 'Description is required').max(255),
  rate: z.string().refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
  uom_id: z.string().optional().or(z.literal('')),
  charge_type: z.enum(['addition', 'deduction', 'addition_percent', 'deduction_percent']),
  unit_cost: z.string().refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
  sales_account_id: z.string().min(1, 'Sales Account is required'),
  purchase_account_id: z.string().min(1, 'Purchase Account is required'),
})

type MiscellaneousItemFormSchemaValues = z.infer<typeof miscellaneousItemFormSchema>

const emptyValues: MiscellaneousItemFormSchemaValues = {
  misc_code: '',
  description: '',
  rate: '0',
  uom_id: '',
  charge_type: 'addition',
  unit_cost: '0',
  sales_account_id: '',
  purchase_account_id: '',
}

interface MiscellaneousItemFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  miscellaneousItem?: MiscellaneousItem | null
}

export function MiscellaneousItemFormDrawer({ open, onOpenChange, miscellaneousItem }: MiscellaneousItemFormDrawerProps) {
  const isEdit = !!miscellaneousItem
  const queryClient = useQueryClient()

  const form = useForm<MiscellaneousItemFormSchemaValues>({
    resolver: zodResolver(miscellaneousItemFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return
    form.reset(
      miscellaneousItem
        ? {
            misc_code: miscellaneousItem.misc_code,
            description: miscellaneousItem.description,
            rate: String(miscellaneousItem.rate),
            uom_id: miscellaneousItem.uom_id ?? '',
            charge_type: miscellaneousItem.charge_type,
            unit_cost: String(miscellaneousItem.unit_cost),
            sales_account_id: miscellaneousItem.sales_account_id,
            purchase_account_id: miscellaneousItem.purchase_account_id,
          }
        : emptyValues,
    )
  }, [open, miscellaneousItem, form])

  const uoms = useQuery({ queryKey: ['uoms'], queryFn: fetchUoms })
  const accounts = useChartOfAccountsLookup()

  const mutation = useMutation({
    mutationFn: (values: MiscellaneousItemFormSchemaValues) => {
      const payload = {
        ...values,
        rate: Number(values.rate),
        unit_cost: Number(values.unit_cost),
        uom_id: values.uom_id || null,
      }
      return isEdit ? updateMiscellaneousItem(miscellaneousItem.id, payload) : createMiscellaneousItem(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['miscellaneous-items-paged'] })
      toast.success(isEdit ? 'Miscellaneous item updated.' : 'Miscellaneous item created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: MiscellaneousItemFormSchemaValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Miscellaneous Item' : 'New Miscellaneous Item'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${miscellaneousItem.misc_code}.` : 'Add a new miscellaneous charge item.'}
          </SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="misc_code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Misc Code</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. MISC001" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="description"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Description</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Handling Fee" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="rate"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Rate</FormLabel>
                    <FormControl>
                      <Input type="number" min={0} step="0.01" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="uom_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>UOM</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={uoms.isLoading ? 'Loading…' : 'Select unit of measurement'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {uoms.data?.map((uom) => (
                          <SelectItem key={uom.id} value={uom.id}>
                            {uom.name}
                            {uom.symbol ? ` (${uom.symbol})` : ''}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="charge_type"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Charge Type</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Select charge type" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {Object.entries(CHARGE_TYPE_LABELS).map(([value, label]) => (
                          <SelectItem key={value} value={value}>
                            {label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="unit_cost"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Unit Cost</FormLabel>
                    <FormControl>
                      <Input type="number" min={0} step="0.01" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="sales_account_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Sales Account</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={accounts.isLoading ? 'Loading…' : 'Select account'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {accounts.data?.map((account) => (
                          <SelectItem key={account.id} value={account.id}>
                            {account.code} — {account.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="purchase_account_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Purchase Account</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={accounts.isLoading ? 'Loading…' : 'Select account'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {accounts.data?.map((account) => (
                          <SelectItem key={account.id} value={account.id}>
                            {account.code} — {account.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create Miscellaneous Item'}
              </Button>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
                Cancel
              </Button>
            </SheetFooter>
          </form>
        </Form>
      </SheetContent>
    </Sheet>
  )
}
