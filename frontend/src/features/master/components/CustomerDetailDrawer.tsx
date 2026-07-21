import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate } from '@/lib/utils'
import type { Customer } from '../types'

interface CustomerDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  customer: Customer | null
  onEdit: (customer: Customer) => void
}

export function CustomerDetailDrawer({ open, onOpenChange, customer, onEdit }: CustomerDetailDrawerProps) {
  if (!customer) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={customer.customer_name}
      subtitle={customer.customer_code}
      badge={<StatusBadge status={customer.is_active ? 'active' : 'inactive'} />}
      primaryAction={{ label: 'Edit Customer', icon: Pencil, onClick: () => onEdit(customer) }}
    >
      <DetailSection>
        <DetailField label="Customer Code" value={customer.customer_code} />
        <DetailField label="Customer Name" value={customer.customer_name} />
        <DetailField label="Phone" value={customer.phone ?? '—'} />
        <DetailField label="Email" value={customer.email ?? '—'} />
        <DetailField label="Address" value={customer.address ?? '—'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(customer.created_at)} />
        <DetailField label="Last Updated" value={formatDate(customer.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
