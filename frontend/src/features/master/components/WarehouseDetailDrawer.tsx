import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate } from '@/lib/utils'
import { useBranchesLookup } from '../hooks/useLookups'
import type { Warehouse } from '../types'

interface WarehouseDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  warehouse: Warehouse | null
  onEdit: (warehouse: Warehouse) => void
}

/**
 * WarehouseResource doesn't nest its branch (unlike Item's item_group /
 * uom), so the branch name is resolved client-side from the same
 * branches lookup the form drawer uses — react-query dedupes the
 * request under the shared ['branches'] key.
 */
export function WarehouseDetailDrawer({ open, onOpenChange, warehouse, onEdit }: WarehouseDetailDrawerProps) {
  const branches = useBranchesLookup(open, 'master.warehouses.view')

  if (!warehouse) return null

  const branchName = branches.data?.find((b) => b.id === warehouse.branch_id)?.name ?? '—'

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={warehouse.name}
      subtitle={warehouse.code}
      badge={<StatusBadge status={warehouse.warehouse_type} />}
      primaryAction={{ label: 'Edit Warehouse', icon: Pencil, onClick: () => onEdit(warehouse) }}
    >
      <DetailSection>
        <DetailField label="Warehouse Code" value={warehouse.code} />
        <DetailField label="Warehouse Name" value={warehouse.name} />
        <DetailField label="Branch" value={branchName} />
        <DetailField label="Type" value={<StatusBadge status={warehouse.warehouse_type} />} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(warehouse.created_at)} />
        <DetailField label="Last Updated" value={formatDate(warehouse.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
