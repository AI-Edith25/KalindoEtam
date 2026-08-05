import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { toastApiError } from '@/shared/services/errorHandler'
import { createNamingSeries, updateNamingSeries } from '../api/namingSeriesApi'
import type { NamingSeries } from '../types'

const namingSeriesFormSchema = z.object({
  module: z.string().min(1, 'Module is required').max(255),
  document_type: z.string().min(1, 'Document type is required').max(255),
  prefix: z.string().max(50).optional().or(z.literal('')),
  suffix: z.string().max(50).optional().or(z.literal('')),
  // String-then-convert (same pattern as discount_amount elsewhere) — a native number
  // input's value is a string, and z.coerce.number() makes the form's input/output
  // types diverge in a way react-hook-form's generics can't reconcile.
  digit_length: z
    .string()
    .refine((value) => /^\d+$/.test(value) && Number(value) >= 1 && Number(value) <= 20, 'Must be a whole number from 1 to 20'),
  is_default: z.boolean(),
  is_active: z.boolean(),
})

type NamingSeriesFormSchemaValues = z.infer<typeof namingSeriesFormSchema>

const emptyValues: NamingSeriesFormSchemaValues = {
  module: '',
  document_type: '',
  prefix: '',
  suffix: '',
  digit_length: '5',
  is_default: true,
  is_active: true,
}

interface NamingSeriesFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  namingSeries?: NamingSeries | null
}

/**
 * document_type is freeform text, not a dropdown — Sprint 2 (Invoice
 * Numbering)'s whole point is that a document type's numbering is
 * configured here instead of hardcoded in app code, so this form can't
 * hardcode a closed list either. Only one row may be the active default
 * per document_type (enforced server-side); is_default here just states
 * intent per docs/DOCUMENT_ENGINE_DESIGN.md.
 */
export function NamingSeriesFormDrawer({ open, onOpenChange, namingSeries }: NamingSeriesFormDrawerProps) {
  const isEdit = !!namingSeries
  const queryClient = useQueryClient()

  const form = useForm<NamingSeriesFormSchemaValues>({
    resolver: zodResolver(namingSeriesFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      namingSeries
        ? {
            module: namingSeries.module,
            document_type: namingSeries.document_type,
            prefix: namingSeries.prefix ?? '',
            suffix: namingSeries.suffix ?? '',
            digit_length: String(namingSeries.digit_length),
            is_default: namingSeries.is_default,
            is_active: namingSeries.is_active,
          }
        : emptyValues,
    )
  }, [open, namingSeries, form])

  const mutation = useMutation({
    mutationFn: (values: NamingSeriesFormSchemaValues) => {
      const payload = {
        module: values.module,
        document_type: values.document_type,
        prefix: values.prefix || null,
        suffix: values.suffix || null,
        digit_length: Number(values.digit_length),
        is_default: values.is_default,
        is_active: values.is_active,
      }

      return isEdit ? updateNamingSeries(namingSeries.id, payload) : createNamingSeries(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['naming-series'] })
      toast.success(isEdit ? 'Naming series updated.' : 'Naming series created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: NamingSeriesFormSchemaValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Naming Series' : 'New Naming Series'}</SheetTitle>
          <SheetDescription>
            {isEdit
              ? `Update numbering for ${namingSeries.document_type}.`
              : 'Configure the prefix/format a document type numbers from.'}
          </SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="module"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Module</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. invoice" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="document_type"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Document Type</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. invoice_transportation" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div className="grid grid-cols-2 gap-4">
                <FormField
                  control={form.control}
                  name="prefix"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Prefix</FormLabel>
                      <FormControl>
                        <Input placeholder="e.g. ANG-" autoComplete="off" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="suffix"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Suffix</FormLabel>
                      <FormControl>
                        <Input placeholder="Optional" autoComplete="off" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>
              <FormField
                control={form.control}
                name="digit_length"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Digit Length</FormLabel>
                    <FormControl>
                      <Input type="number" min={1} max={20} {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {isEdit && (
                <p className="text-xs text-muted-foreground">
                  Current number: {namingSeries.current_number} — advances automatically, not editable here.
                </p>
              )}
              <FormField
                control={form.control}
                name="is_default"
                render={({ field }) => (
                  <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                    <div>
                      <FormLabel className="cursor-pointer">Default</FormLabel>
                      <p className="text-xs text-muted-foreground">The series new documents of this type actually number from.</p>
                    </div>
                    <FormControl>
                      <Switch checked={field.value} onCheckedChange={field.onChange} />
                    </FormControl>
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="is_active"
                render={({ field }) => (
                  <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                    <FormLabel className="cursor-pointer">Active</FormLabel>
                    <FormControl>
                      <Switch checked={field.value} onCheckedChange={field.onChange} />
                    </FormControl>
                  </FormItem>
                )}
              />
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create Series'}
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
