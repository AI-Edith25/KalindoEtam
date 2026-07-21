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
import { getErrorMessage } from '@/shared/services/errorHandler'
import { createUser, updateUser } from '../api/userApi'
import { fetchRoles } from '../api/roleApi'
import type { User } from '../types'

const NO_ROLE = '__none__'

const createSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  email: z.string().email('Must be a valid email').max(255),
  password: z.string().min(8, 'Must be at least 8 characters'),
  role: z.string(),
})

const editSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  email: z.string().email('Must be a valid email').max(255),
  password: z.string(),
  role: z.string(),
})

type UserFormSchemaValues = z.infer<typeof createSchema>

interface UserFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  user?: User | null
}

export function UserFormDrawer({ open, onOpenChange, user }: UserFormDrawerProps) {
  const isEdit = !!user
  const queryClient = useQueryClient()
  const rolesQuery = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })

  const form = useForm<UserFormSchemaValues>({
    resolver: zodResolver(isEdit ? editSchema : createSchema),
    defaultValues: { name: '', email: '', password: '', role: NO_ROLE },
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      user
        ? { name: user.name, email: user.email, password: '', role: user.roles[0] ?? NO_ROLE }
        : { name: '', email: '', password: '', role: NO_ROLE },
    )
  }, [open, user, form])

  const mutation = useMutation({
    mutationFn: (values: UserFormSchemaValues) => {
      const role = values.role === NO_ROLE ? undefined : values.role

      return isEdit ? updateUser(user.id, { name: values.name, email: values.email }) : createUser({ ...values, role })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users-paged'] })
      toast.success(isEdit ? 'User updated.' : 'User created.')
      onOpenChange(false)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const onSubmit = (values: UserFormSchemaValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit User' : 'New User'}</SheetTitle>
          <SheetDescription>{isEdit ? `Update details for ${user.email}.` : 'Add a new user and optionally assign a role.'}</SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Name</FormLabel>
                    <FormControl>
                      <Input autoComplete="off" {...field} />
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
                      <Input type="email" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {!isEdit && (
                <FormField
                  control={form.control}
                  name="password"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Password</FormLabel>
                      <FormControl>
                        <Input type="password" autoComplete="new-password" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}
              {!isEdit && (
                <FormField
                  control={form.control}
                  name="role"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Role</FormLabel>
                      <Select value={field.value} onValueChange={field.onChange}>
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select role" />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          <SelectItem value={NO_ROLE}>No role</SelectItem>
                          {(rolesQuery.data ?? []).map((role) => (
                            <SelectItem key={role.id} value={role.name}>
                              {role.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create User'}
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
