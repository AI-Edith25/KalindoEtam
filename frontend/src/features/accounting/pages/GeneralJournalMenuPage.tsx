import { Link } from 'react-router-dom'
import { BookOpen } from 'lucide-react'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { findGroup, permissionName } from '@/config/navTree'

/** Chooser page — Finance's "General Journal" tab lands here first, not on a report directly, so the
 *  user picks which of the 8 Accounting Reports pages to open. Cards are generated from navTree's
 *  `accounting` group, so adding/removing a report page there is the only edit ever needed here. */
export function GeneralJournalMenuPage() {
  const accountingGroup = findGroup('accounting')
  const pages = accountingGroup?.pages ?? []

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title="General Journal" description="Choose a report to open" />
      <SectionNav group="finance" />
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {pages.map((page) => (
          <GeneralJournalMenuCard key={page.key} label={page.label} path={page.path} pageKey={page.key} />
        ))}
      </div>
    </div>
  )
}

function GeneralJournalMenuCard({ label, path, pageKey }: { label: string; path: string; pageKey: string }) {
  const canView = useHasPermission(permissionName('accounting', pageKey, 'view'))
  if (!canView) return null

  return (
    <Link to={path}>
      <Card className="transition-colors hover:bg-accent/50">
        <CardHeader className="flex-row items-center gap-3 space-y-0">
          <BookOpen className="size-5 shrink-0 text-muted-foreground" />
          <CardTitle className="text-base font-medium">{label}</CardTitle>
        </CardHeader>
      </Card>
    </Link>
  )
}
