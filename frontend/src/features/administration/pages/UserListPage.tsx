import { useState } from 'react'
import { Key, Pencil, Plus, Power, PowerOff, RotateCw, ShieldPlus } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { Badge } from '@/components/ui/badge'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatNumber } from '@/lib/utils'
import { activateUser, deactivateUser, fetchUsersPaged } from '../api/userApi'
import { UserFormDrawer } from '../components/UserFormDrawer'
import { ResetPasswordDialog } from '../components/ResetPasswordDialog'
import { AssignRoleDialog } from '../components/AssignRoleDialog'
import { administrationNav } from '../navigation'
import type { User } from '../types'

export function UserListPage() {
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('user.create')
  const canUpdate = useHasPermission('user.update')
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [editingUser, setEditingUser] = useState<User | null>(null)
  const [resetPasswordUser, setResetPasswordUser] = useState<User | null>(null)
  const [assignRoleUser, setAssignRoleUser] = useState<User | null>(null)

  const listQuery = useQuery({
    queryKey: ['users-paged', page],
    queryFn: () => fetchUsersPaged(page),
    placeholderData: (previous) => previous,
  })

  const toggleActiveMutation = useMutation({
    mutationFn: (user: User) => (user.is_active ? deactivateUser(user.id) : activateUser(user.id)),
    onSuccess: (user) => {
      queryClient.invalidateQueries({ queryKey: ['users-paged'] })
      toast.success(user.is_active ? `${user.email} activated.` : `${user.email} deactivated.`)
    },
    onError: (error) => toastApiError(error),
  })

  const rows = (listQuery.data?.data ?? []).filter((user) => {
    const query = search.trim().toLowerCase()
    if (!query) return true
    return user.name.toLowerCase().includes(query) || user.email.toLowerCase().includes(query)
  })

  const openCreate = () => {
    setEditingUser(null)
    setFormOpen(true)
  }

  const openEdit = (user: User) => {
    setEditingUser(user)
    setFormOpen(true)
  }

  const columns: DataTableColumn<User>[] = [
    { header: 'Name', accessor: (row) => row.name },
    { header: 'Email', accessor: (row) => row.email },
    {
      header: 'Role',
      accessor: (row) => (row.roles.length ? row.roles.map((role) => <Badge key={role} variant="secondary" className="mr-1">{role}</Badge>) : <span className="text-muted-foreground">No role</span>),
    },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.is_active ? 'active' : 'inactive'} /> },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => (
        <RowActionsMenu
          actions={
            canUpdate
              ? [
                  { label: 'Edit', icon: Pencil, onClick: () => openEdit(row) },
                  { label: 'Assign Role', icon: ShieldPlus, onClick: () => setAssignRoleUser(row) },
                  { label: 'Reset Password', icon: Key, onClick: () => setResetPasswordUser(row) },
                  row.is_active
                    ? { label: 'Deactivate', icon: PowerOff, onClick: () => toggleActiveMutation.mutate(row) }
                    : { label: 'Activate', icon: Power, onClick: () => toggleActiveMutation.mutate(row) },
                ]
              : []
          }
        />
      ),
    },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={administrationNav} />

      <PageHeader
        title="Users"
        description="Who can sign in, and what role they have."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} users` : undefined}
        actions={
          <ActionBar
            actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching }]}
            primary={canCreate ? { label: 'New User', icon: Plus, onClick: openCreate } : undefined}
          />
        }
      />

      <SearchBox value={search} onChange={setSearch} placeholder="Search name or email…" />

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={search ? 'No users match your search.' : 'No users yet.'}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <UserFormDrawer open={formOpen} onOpenChange={setFormOpen} user={editingUser} />
      <ResetPasswordDialog open={!!resetPasswordUser} onOpenChange={(open) => !open && setResetPasswordUser(null)} user={resetPasswordUser} />
      <AssignRoleDialog open={!!assignRoleUser} onOpenChange={(open) => !open && setAssignRoleUser(null)} user={assignRoleUser} />
    </div>
  )
}
