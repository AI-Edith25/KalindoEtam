import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ListChecks } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { EmptyState } from '@/components/shared/EmptyState'
import { Skeleton } from '@/components/ui/skeleton'
import { Badge } from '@/components/ui/badge'
import { fetchPendingTasks } from '../api/dashboardApi'

const MODULE_LINKS: Record<string, string> = {
  purchase_order: '/purchase/orders',
  sales_order: '/sales/orders',
  journal_entry: '/accounting/journal-entries',
}

/** Draft/unposted counts across modules — each count reuses that module's own repository (docs/DASHBOARD_DESIGN.md §3); this widget only lists them. */
export function PendingTasksCard() {
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({ queryKey: ['dashboard', 'pending-tasks'], queryFn: fetchPendingTasks })

  const tasksWithCount = (data ?? []).filter((task) => task.count > 0)

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <ListChecks className="size-4 text-primary" />
          Pending Tasks
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-32 w-full" />
        ) : tasksWithCount.length === 0 ? (
          <EmptyState message="Nothing pending — all documents are submitted." />
        ) : (
          <ul className="flex flex-col gap-2">
            {tasksWithCount.map((task) => (
              <li key={task.module}>
                <button
                  type="button"
                  onClick={() => navigate(MODULE_LINKS[task.module] ?? '/dashboard')}
                  className="flex w-full items-center justify-between rounded-md border px-3 py-2 text-left text-sm transition-colors hover:bg-accent/50"
                >
                  <span>{task.label}</span>
                  <Badge variant="secondary">{task.count}</Badge>
                </button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
