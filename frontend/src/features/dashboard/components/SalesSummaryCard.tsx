import { useQuery } from '@tanstack/react-query'
import { ShoppingBag } from 'lucide-react'
import { SummaryCard } from './SummaryCard'
import { fetchSalesToday } from '../api/dashboardApi'
import { formatCurrency, formatNumber } from '@/lib/utils'

/** Renders the existing salesToday data DashboardService already exposed (Sprint 7) — no replacement endpoint. Drill-down: Sales Summary → Sales Invoices, per the approved ticket. */
export function SalesSummaryCard() {
  const { data, isLoading } = useQuery({ queryKey: ['dashboard', 'sales-today'], queryFn: fetchSalesToday })

  return (
    <SummaryCard
      title="Sales Today"
      value={formatCurrency(data?.total_amount ?? 0)}
      description={`${formatNumber(data?.count ?? 0)} sales orders today`}
      icon={ShoppingBag}
      isLoading={isLoading}
      to="/sales/invoices"
    />
  )
}
