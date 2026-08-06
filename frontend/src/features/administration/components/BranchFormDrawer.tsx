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
import { Switch } from '@/components/ui/switch'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchCompaniesLookup } from '@/features/master/api/lookupsApi'
import type { Branch } from '@/features/master/types'
import { createBranch, updateBranch } from '../api/branchApi'

const branchFormSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  code: z.string().min(1, 'Code is required').max(255),
  address: z.string().max(255).optional().or(z.literal('')),
  is_head_office: z.boolean(),
  is_active: z.boolean(),
})

type BranchFormValues = z.infer<typeof branchFormSchema>

const emptyValues: BranchFormValues = {
  name: '',
  code: '',
  address: '',
  is_head_office: false,
  is_active: true,
}

interface BranchFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  branch?: Branch | null
}

export function BranchFormDrawer({ open, onOpenChange, branch }: BranchFormDrawerProps) {
  const isEdit = !!branch
  const queryClient = useQueryClient()

  // Company is a de facto singleton in this app (see CompanyPage) — a Branch's required
  // company_id is resolved automatically from the first/only company, no picker shown.
  const companiesQuery = useQuery({ queryKey: ['companies-lookup'], queryFn: fetchCompaniesLookup, enabled: open })

  const form = useForm<BranchFormValues>({
    resolver: zodResolver(branchFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      branch
        ? {
            name: branch.name,
            code: branch.code,
            address: branch.address ?? '',
            is_head_office: branch.is_head_office,
            is_active: branch.is_active,
          }
        : emptyValues,
    )
  }, [open, branch, form])

  const mutation = useMutation({
    mutationFn: (values: BranchFormValues) => {
      const payload = {
        ...values,
        company_id: branch?.company_id ?? companiesQuery.data?.[0]?.id ?? '',
        address: values.address || null,
      }
      return isEdit ? updateBranch(branch.id, payload) : createBranch(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['branches'] })
      toast.success(isEdit ? 'Branch updated.' : 'Branch created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: BranchFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Branch' : 'New Branch'}</SheetTitle>
          <SheetDescription>{isEdit ? `Update details for ${branch.code}.` : 'Add a new branch.'}</SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Code</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. BR001" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Name</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Cabang Balikpapan" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="address"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Address</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="is_head_office"
                render={({ field }) => (
                  <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                    <FormLabel className="cursor-pointer">Head Office</FormLabel>
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
                {isEdit ? 'Save Changes' : 'Create Branch'}
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
