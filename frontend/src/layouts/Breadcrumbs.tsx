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

function labelFor(segment: string): string {
  if (`/${segment}` === DASHBOARD_NAV.path) return DASHBOARD_NAV.label

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
                  <BreadcrumbPage>{labelFor(segment)}</BreadcrumbPage>
                ) : (
                  <BreadcrumbLink href={path}>{labelFor(segment)}</BreadcrumbLink>
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
