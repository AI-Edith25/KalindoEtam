import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate } from '@/lib/utils'
import type { ChartOfAccount } from '../types'

interface ChartOfAccountDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  chartOfAccount: ChartOfAccount | null
  onEdit: (chartOfAccount: ChartOfAccount) => void
}

export function ChartOfAccountDetailDrawer({ open, onOpenChange, chartOfAccount, onEdit }: ChartOfAccountDetailDrawerProps) {
  if (!chartOfAccount) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={chartOfAccount.name}
      subtitle={chartOfAccount.code}
      badge={<StatusBadge status={chartOfAccount.is_active ? 'active' : 'inactive'} />}
      primaryAction={{ label: 'Edit Account', icon: Pencil, onClick: () => onEdit(chartOfAccount) }}
    >
      <DetailSection>
        <DetailField label="Code" value={chartOfAccount.code} />
        <DetailField label="Name" value={chartOfAccount.name} />
        <DetailField label="Type" value={<StatusBadge status={chartOfAccount.account_type} />} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(chartOfAccount.created_at)} />
        <DetailField label="Last Updated" value={formatDate(chartOfAccount.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
