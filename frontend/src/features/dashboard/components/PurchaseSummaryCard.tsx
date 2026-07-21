import { useQuery } from '@tanstack/react-query'
import { ShoppingCart } from 'lucide-react'
import { SummaryCard } from './SummaryCard'
import { fetchPurchasesToday } from '../api/dashboardApi'
import { formatCurrency, formatNumber } from '@/lib/utils'

/** Renders the existing purchasesToday data DashboardService already exposed (Sprint 7) — no replacement endpoint. Drill-down: Purchase Summary → Purchase Orders, per the approved ticket. */
export function PurchaseSummaryCard() {
  const { data, isLoading } = useQuery({ queryKey: ['dashboard', 'purchases-today'], queryFn: fetchPurchasesToday })

  return (
    <SummaryCard
      title="Purchases Today"
      value={formatCurrency(data?.total_amount ?? 0)}
      description={`${formatNumber(data?.count ?? 0)} purchase orders today`}
      icon={ShoppingCart}
      isLoading={isLoading}
      to="/purchase/orders"
    />
  )
}
