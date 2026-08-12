import { useLocation } from 'react-router-dom'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import { navTree, DASHBOARD_NAV } from '@/config/navTree'

function labelFor(path: string, segment: string): string {
  if (path === DASHBOARD_NAV.path) return DASHBOARD_NAV.label

  // A page can now live deeper than one level under its group's prefix (e.g. the
  // accounting pages nested under /finance/general-journal/*) — try an exact match
  // against every navTree page's own path first, so a leaf crumb gets that page's
  // real label (title-casing alone can't produce "Profit & Loss" from "profit-loss",
  // or know that "journal-entries" is labeled "General Journal").
  for (const group of navTree) {
    const page = group.pages.find((p) => p.path === path)
    if (page) return page.label
  }

  // Every page in a group shares that group's URL prefix (e.g. all `finance` pages
  // live under /finance/*) — match by prefix so the breadcrumb root resolves to the
  // section's real label instead of silently falling through to a title-cased URL segment.
  const match = navTree.find((group) => group.pages.some((page) => page.path.startsWith(`/${segment}/`)))
  if (match) return match.label
  return segment
    .split('-')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export function Breadcrumbs() {
  const location = useLocation()
  const segments = location.pathname.split('/').filter(Boolean)

  if (segments.length === 0) {
    return null
  }

  return (
    <Breadcrumb>
      <BreadcrumbList>
        {segments.map((segment, index) => {
          const isLast = index === segments.length - 1
          const path = '/' + segments.slice(0, index + 1).join('/')

          return (
            <span key={path} className="flex items-center gap-1.5">
              <BreadcrumbItem>
                {isLast ? (
                  <BreadcrumbPage>{labelFor(path, segment)}</BreadcrumbPage>
                ) : (
                  <BreadcrumbLink href={path}>{labelFor(path, segment)}</BreadcrumbLink>
                )}
              </BreadcrumbItem>
              {!isLast && <BreadcrumbSeparator />}
            </span>
          )
        })}
      </BreadcrumbList>
    </Breadcrumb>
  )
}
