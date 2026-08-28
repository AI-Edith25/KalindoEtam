import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Target } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { EmptyState } from '@/components/shared/EmptyState'
import { fetchSalesAchievement } from '../api/dashboardApi'
import { MONTH_OPTIONS } from '@/features/master/lib/months'
import { formatCurrency } from '@/lib/utils'

const CURRENT_YEAR = new Date().getFullYear()
const YEAR_OPTIONS = [CURRENT_YEAR - 1, CURRENT_YEAR, CURRENT_YEAR + 1]

function ProgressBar({ percent }: { percent: number | null }) {
  if (percent === null) return <span className="text-xs text-muted-foreground">—</span>

  // The number itself is never capped (a salesperson can genuinely exceed 100%) — only the
  // visual bar width is, so a big surplus doesn't overflow the row.
  const width = Math.min(100, Math.max(0, percent))
  const isShort = percent < 100

  return (
    <div className="flex items-center gap-2">
      <div className="h-2 w-24 overflow-hidden rounded-full bg-muted">
        <div className={`h-full rounded-full ${isShort ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${width}%` }} />
      </div>
      <span className="text-xs tabular-nums text-muted-foreground">{percent.toFixed(1)}%</span>
    </div>
  )
}

/**
 * "Pencapaian Sales" — each Sales Person's real revenue against their
 * SalesTarget for a selectable month, on the exact same submitted-Invoice/
 * Credit-Note/Debit-Note basis DashboardService::financialSummary() itself
 * reads for the Revenue (MTD) card (see DashboardService::
 * salesAchievement()'s own docblock on the backend) — never a second,
 * differently-computed revenue figure.
 */
export function SalesAchievementCard() {
  const [month, setMonth] = useState(new Date().getMonth() + 1)
  const [year, setYear] = useState(CURRENT_YEAR)

  const { data, isLoading } = useQuery({
    queryKey: ['dashboard', 'sales-achievement', month, year],
    queryFn: () => fetchSalesAchievement({ month, year }),
    placeholderData: (previous) => previous,
  })

  const rows = data?.rows ?? []

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2">
        <CardTitle className="flex items-center gap-2 text-base">
          <Target className="size-4 text-primary" />
          Pencapaian Sales
        </CardTitle>
        <div className="flex items-center gap-2">
          <Select value={String(month)} onValueChange={(value) => setMonth(Number(value))}>
            <SelectTrigger className="w-36">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {MONTH_OPTIONS.map((option) => (
                <SelectItem key={option.value} value={String(option.value)}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={String(year)} onValueChange={(value) => setYear(Number(value))}>
            <SelectTrigger className="w-24">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {YEAR_OPTIONS.map((y) => (
                <SelectItem key={y} value={String(y)}>
                  {y}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : rows.length === 0 && !data?.unassigned ? (
          <EmptyState message="No sales targets or transactions for this period yet." />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Sales Person</TableHead>
                <TableHead className="text-right">Target</TableHead>
                <TableHead className="text-right">Pencapaian</TableHead>
                <TableHead className="text-right">Kekurangan</TableHead>
                <TableHead>% Pencapaian</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.sales_person_id}>
                  <TableCell className="font-medium">{row.sales_person_name}</TableCell>
                  <TableCell className="text-right">{row.target_amount === null ? '—' : formatCurrency(row.target_amount)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(row.achieved_amount)}</TableCell>
                  <TableCell className="text-right">{row.shortfall_amount === null ? '—' : formatCurrency(row.shortfall_amount)}</TableCell>
                  <TableCell>
                    <ProgressBar percent={row.achievement_percent} />
                  </TableCell>
                </TableRow>
              ))}
              {data?.unassigned && (
                <TableRow className="text-muted-foreground">
                  <TableCell className="font-medium italic">Unassigned</TableCell>
                  <TableCell className="text-right">—</TableCell>
                  <TableCell className="text-right">{formatCurrency(data.unassigned.achieved_amount)}</TableCell>
                  <TableCell className="text-right">—</TableCell>
                  <TableCell>—</TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}
