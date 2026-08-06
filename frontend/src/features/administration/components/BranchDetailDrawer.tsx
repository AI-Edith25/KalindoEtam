import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate } from '@/lib/utils'
import type { Branch } from '@/features/master/types'

interface BranchDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  branch: Branch | null
  onEdit: (branch: Branch) => void
}

export function BranchDetailDrawer({ open, onOpenChange, branch, onEdit }: BranchDetailDrawerProps) {
  if (!branch) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={branch.name}
      subtitle={branch.code}
      badge={<StatusBadge status={branch.is_active ? 'active' : 'inactive'} />}
      primaryAction={{ label: 'Edit Branch', icon: Pencil, onClick: () => onEdit(branch) }}
    >
      <DetailSection>
        <DetailField label="Code" value={branch.code} />
        <DetailField label="Name" value={branch.name} />
        <DetailField label="Address" value={branch.address ?? '—'} />
        <DetailField label="Head Office" value={branch.is_head_office ? 'Yes' : 'No'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(branch.created_at)} />
        <DetailField label="Last Updated" value={formatDate(branch.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
