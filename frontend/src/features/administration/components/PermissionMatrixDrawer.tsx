import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ChevronDown, ChevronRight, Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { toastApiError } from '@/shared/services/errorHandler'
import { navTree, extraPermissions, standalonePermissions, permissionName, type Action } from '@/config/navTree'
import { assignRolePermissions, fetchPermissions } from '../api/roleApi'
import type { Role } from '../types'

const ALL_ACTIONS: Action[] = ['view', 'create', 'update', 'delete', 'approve']

interface PermissionMatrixDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  role: Role | null
}

type CheckedState = boolean | 'indeterminate'

/**
 * Renders the app's single navTree (the same source SidebarNav/Breadcrumbs/
 * SectionNav/router guards all derive from) as a group -> page -> action
 * permission tree — Windows-Explorer-style tri-state checkboxes: a group
 * checkbox selects/clears every action of every page beneath it, and shows
 * indeterminate when only some are selected; a page checkbox does the same
 * across just its own actions. Permission names are always derived via
 * permissionName(), never hand-typed, so this UI can't drift out of sync
 * with the pages it's supposed to represent.
 */
export function PermissionMatrixDrawer({ open, onOpenChange, role }: PermissionMatrixDrawerProps) {
  const queryClient = useQueryClient()
  const permissionsQuery = useQuery({ queryKey: ['permissions'], queryFn: fetchPermissions, enabled: open })
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [expanded, setExpanded] = useState<Set<string>>(new Set())

  useEffect(() => {
    if (open && role) setSelected(new Set(role.permissions))
  }, [open, role])

  // Guards against navTree drifting out of sync with the seeded catalog — only ever
  // offer a checkbox for a permission that actually exists server-side.
  const existingNames = useMemo(() => new Set((permissionsQuery.data ?? []).map((p) => p.name)), [permissionsQuery.data])

  const toggleMany = (names: string[], checked: boolean) => {
    setSelected((prev) => {
      const next = new Set(prev)
      for (const name of names) {
        if (checked) next.add(name)
        else next.delete(name)
      }
      return next
    })
  }

  const toggleExpanded = (key: string) => {
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  const triState = (names: string[]): CheckedState => {
    const present = names.filter((name) => existingNames.has(name))
    if (present.length === 0) return false
    const selectedCount = present.filter((name) => selected.has(name)).length
    if (selectedCount === 0) return false
    return selectedCount === present.length ? true : 'indeterminate'
  }

  const mutation = useMutation({
    mutationFn: () => assignRolePermissions(role!.id, Array.from(selected)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      toast.success('Permissions updated.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  if (!role) return null

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-xl">
        <SheetHeader>
          <SheetTitle>Permissions — {role.name}</SheetTitle>
          <SheetDescription>Grouped by application page. Submitting replaces this role&apos;s entire permission set.</SheetDescription>
        </SheetHeader>

        <div className="flex-1 overflow-y-auto px-4">
          {permissionsQuery.isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : (
            <div className="flex flex-col gap-2">
              {navTree.map((group) => {
                const groupNames = group.pages.flatMap((page) => page.actions.map((action) => permissionName(group.key, page.key, action)))
                const isExpanded = expanded.has(group.key)

                return (
                  <div key={group.key} className="rounded-md border">
                    <div className="flex items-center gap-2 px-3 py-2">
                      <button
                        type="button"
                        onClick={() => toggleExpanded(group.key)}
                        className="text-muted-foreground hover:text-foreground"
                      >
                        {isExpanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                      </button>
                      <Checkbox
                        checked={triState(groupNames)}
                        onCheckedChange={(checked) => toggleMany(groupNames, checked === true)}
                      />
                      <button type="button" onClick={() => toggleExpanded(group.key)} className="text-sm font-medium hover:underline">
                        {group.label}
                      </button>
                    </div>

                    {isExpanded && (
                      <table className="w-full text-sm">
                        <thead>
                          <tr className="border-t text-left text-muted-foreground">
                            <th className="py-2 pl-9 font-medium">Page</th>
                            {ALL_ACTIONS.map((action) => (
                              <th key={action} className="py-2 text-center font-medium capitalize">
                                {action}
                              </th>
                            ))}
                          </tr>
                        </thead>
                        <tbody>
                          {group.pages.map((page) => {
                            const pageNames = page.actions.map((action) => permissionName(group.key, page.key, action))

                            return (
                              <tr key={page.key} className="border-t">
                                <td className="py-2 pl-9">
                                  <label className="flex items-center gap-2">
                                    <Checkbox
                                      checked={triState(pageNames)}
                                      onCheckedChange={(checked) => toggleMany(pageNames, checked === true)}
                                    />
                                    {page.label}
                                  </label>
                                </td>
                                {ALL_ACTIONS.map((action) => {
                                  const name = permissionName(group.key, page.key, action)
                                  if (!page.actions.includes(action) || !existingNames.has(name)) {
                                    return (
                                      <td key={action} className="py-2 text-center text-muted-foreground/30">
                                        —
                                      </td>
                                    )
                                  }
                                  return (
                                    <td key={action} className="py-2 text-center">
                                      <Checkbox
                                        checked={selected.has(name)}
                                        onCheckedChange={(checked) => toggleMany([name], checked === true)}
                                      />
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
                )
              })}

              <div className="rounded-md border">
                <div className="px-3 py-2 text-sm font-medium">Other</div>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-t text-left text-muted-foreground">
                      <th className="py-2 pl-9 font-medium">Permission</th>
                      {ALL_ACTIONS.map((action) => (
                        <th key={action} className="py-2 text-center font-medium capitalize">
                          {action}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {extraPermissions.map((entry) => (
                      <tr key={entry.key} className="border-t">
                        <td className="py-2 pl-9">{entry.label}</td>
                        {ALL_ACTIONS.map((action) => {
                          const name = permissionName(entry.group, entry.key, action)
                          if (!entry.actions.includes(action) || !existingNames.has(name)) {
                            return (
                              <td key={action} className="py-2 text-center text-muted-foreground/30">
                                —
                              </td>
                            )
                          }
                          return (
                            <td key={action} className="py-2 text-center">
                              <Checkbox checked={selected.has(name)} onCheckedChange={(checked) => toggleMany([name], checked === true)} />
                            </td>
                          )
                        })}
                      </tr>
                    ))}
                    {standalonePermissions.map((entry) => (
                      <tr key={entry.key} className="border-t">
                        <td className="py-2 pl-9">{entry.label}</td>
                        <td className="py-2 text-center">
                          <Checkbox
                            checked={existingNames.has(entry.name) ? selected.has(entry.name) : false}
                            onCheckedChange={(checked) => toggleMany([entry.name], checked === true)}
                          />
                        </td>
                        {ALL_ACTIONS.slice(1).map((action) => (
                          <td key={action} className="py-2 text-center text-muted-foreground/30">
                            —
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
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
