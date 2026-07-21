import type { LucideIcon } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

interface SummaryCardProps {
  title: string
  value: string
  description?: string
  icon: LucideIcon
  isLoading?: boolean
  tone?: 'default' | 'warning' | 'danger'
  /** Drill-down target (docs/DASHBOARD_DESIGN.md §6) — every KPI card links to the existing detailed module/report it summarizes, never a second copy of that detail. */
  to?: string
}

const TONE_ICON_CLASS: Record<NonNullable<SummaryCardProps['tone']>, string> = {
  default: 'text-primary',
  warning: 'text-amber-500',
  danger: 'text-destructive',
}

export function SummaryCard({ title, value, description, icon: Icon, isLoading, tone = 'default', to }: SummaryCardProps) {
  const navigate = useNavigate()

  return (
    <Card
      role={to ? 'link' : undefined}
      onClick={to ? () => navigate(to) : undefined}
      className={cn(to && 'cursor-pointer transition-colors hover:bg-accent/50')}
    >
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
        <Icon className={`size-4 ${TONE_ICON_CLASS[tone]}`} />
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-8 w-24" />
        ) : (
          <div className="text-2xl font-semibold">{value}</div>
        )}
        {description && <p className="mt-1 text-xs text-muted-foreground">{description}</p>}
      </CardContent>
    </Card>
  )
}
