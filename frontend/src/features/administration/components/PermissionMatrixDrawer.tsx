import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { assignRolePermissions, fetchPermissions } from '../api/roleApi'
import type { Role } from '../types'

const ACTIONS = ['view', 'create', 'update', 'delete'] as const

interface PermissionMatrixDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  role: Role | null
}

function moduleLabel(module: string): string {
  return module
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

/**
 * Groups the flat `{module}.{action}` permission names by module client-side
 * — Permission has no separate module/action column in the database, and
 * this design deliberately doesn't add one. See docs/ADMINISTRATION_DESIGN.md §5.
 */
export function PermissionMatrixDrawer({ open, onOpenChange, role }: PermissionMatrixDrawerProps) {
  const queryClient = useQueryClient()
  const permissionsQuery = useQuery({ queryKey: ['permissions'], queryFn: fetchPermissions, enabled: open })
  const [selected, setSelected] = useState<Set<string>>(new Set())

  useEffect(() => {
    if (open && role) setSelected(new Set(role.permissions))
  }, [open, role])

  const modules = useMemo(() => {
    const names = (permissionsQuery.data ?? []).map((p) => p.name)
    const moduleSet = new Set(names.map((name) => name.split('.')[0]))
    return Array.from(moduleSet).sort()
  }, [permissionsQuery.data])

  const toggle = (permission: string, checked: boolean) => {
    setSelected((prev) => {
      const next = new Set(prev)
      if (checked) next.add(permission)
      else next.delete(permission)
      return next
    })
  }

  const toggleRow = (module: string, checked: boolean) => {
    setSelected((prev) => {
      const next = new Set(prev)
      for (const action of ACTIONS) {
        const permission = `${module}.${action}`
        if (checked) next.add(permission)
        else next.delete(permission)
      }
      return next
    })
  }

  const mutation = useMutation({
    mutationFn: () => assignRolePermissions(role!.id, Array.from(selected)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      toast.success('Permissions updated.')
      onOpenChange(false)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  if (!role) return null

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-xl">
        <SheetHeader>
          <SheetTitle>Permissions — {role.name}</SheetTitle>
          <SheetDescription>Grouped by module. Submitting replaces this role&apos;s entire permission set.</SheetDescription>
        </SheetHeader>

        <div className="flex-1 overflow-y-auto px-4">
          {permissionsQuery.isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="py-2 font-medium">Module</th>
                  {ACTIONS.map((action) => (
                    <th key={action} className="py-2 text-center font-medium capitalize">
                      {action}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {modules.map((module) => {
                  const rowFullySelected = ACTIONS.every((action) => selected.has(`${module}.${action}`))

                  return (
                    <tr key={module} className="border-b last:border-0">
                      <td className="py-2">
                        <button
                          type="button"
                          onClick={() => toggleRow(module, !rowFullySelected)}
                          className="text-left hover:underline"
                          title="Toggle all actions for this module"
                        >
                          {moduleLabel(module)}
                        </button>
                      </td>
                      {ACTIONS.map((action) => {
                        const permission = `${module}.${action}`
                        return (
                          <td key={action} className="py-2 text-center">
                            <Switch checked={selected.has(permission)} onCheckedChange={(checked) => toggle(permission, checked)} />
                          </td>
                        )
                      })}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          )}
        </div>

        <SheetFooter>
          <Button onClick={() => mutation.mutate()} disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            Save Permissions
          </Button>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
            Cancel
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
