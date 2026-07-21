import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate } from '@/lib/utils'
import type { Supplier } from '../types'

interface SupplierDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  supplier: Supplier | null
  onEdit: (supplier: Supplier) => void
}

/** Supplier has a real is_active flag, so the badge reflects it directly — no derived placeholder needed (contrast with ItemDetailDrawer). */
export function SupplierDetailDrawer({ open, onOpenChange, supplier, onEdit }: SupplierDetailDrawerProps) {
  if (!supplier) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={supplier.supplier_name}
      subtitle={supplier.supplier_code}
      badge={<StatusBadge status={supplier.is_active ? 'active' : 'inactive'} />}
      primaryAction={{ label: 'Edit Supplier', icon: Pencil, onClick: () => onEdit(supplier) }}
    >
      <DetailSection>
        <DetailField label="Supplier Code" value={supplier.supplier_code} />
        <DetailField label="Supplier Name" value={supplier.supplier_name} />
        <DetailField label="Phone" value={supplier.phone ?? '—'} />
        <DetailField label="Email" value={supplier.email ?? '—'} />
        <DetailField label="Address" value={supplier.address ?? '—'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(supplier.created_at)} />
        <DetailField label="Last Updated" value={formatDate(supplier.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
