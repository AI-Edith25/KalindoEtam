import { useState } from 'react'
import { Plus, RotateCw, ShieldCheck } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SectionNav } from '@/components/shared/SectionNav'
import { RowActionsMenu } from '@/components/shared/RowActionsMenu'
import { formatNumber } from '@/lib/utils'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { fetchRoles } from '../api/roleApi'
import { RoleFormDialog } from '../components/RoleFormDialog'
import { PermissionMatrixDrawer } from '../components/PermissionMatrixDrawer'
import type { Role } from '../types'

export function RoleListPage() {
  const canCreate = useHasPermission('administration.roles.create')
  const canUpdate = useHasPermission('administration.roles.update')
  const [formOpen, setFormOpen] = useState(false)
  const [matrixRole, setMatrixRole] = useState<Role | null>(null)

  const rolesQuery = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })

  const columns: DataTableColumn<Role>[] = [
    { header: 'Role Name', accessor: (row) => row.name },
    { header: 'Permissions', accessor: (row) => `${row.permissions.length} granted` },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => (
        <RowActionsMenu
          actions={canUpdate ? [{ label: 'Edit Permissions', icon: ShieldCheck, onClick: () => setMatrixRole(row) }] : []}
        />
      ),
    },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="administration" />

      <PageHeader
        title="Roles & Permissions"
        description="Roles group permissions; permissions are reused as-is (module.action), never redefined here."
        count={rolesQuery.data ? `${formatNumber(rolesQuery.data.length)} roles` : undefined}
        actions={
          <ActionBar
            actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => rolesQuery.refetch(), disabled: rolesQuery.isFetching }]}
            primary={canCreate ? { label: 'New Role', icon: Plus, onClick: () => setFormOpen(true) } : undefined}
          />
        }
      />

      <DataTable
        columns={columns}
        data={rolesQuery.data ?? []}
        rowKey={(row) => row.id}
        isLoading={rolesQuery.isLoading}
        isError={rolesQuery.isError}
        onRetry={() => rolesQuery.refetch()}
        onRowClick={(row) => setMatrixRole(row)}
        emptyMessage="No roles yet."
      />

      <RoleFormDialog open={formOpen} onOpenChange={setFormOpen} />
      <PermissionMatrixDrawer open={!!matrixRole} onOpenChange={(open) => !open && setMatrixRole(null)} role={matrixRole} />
    </div>
  )
}
