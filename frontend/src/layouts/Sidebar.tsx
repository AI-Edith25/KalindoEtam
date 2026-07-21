import { Package } from 'lucide-react'
import { useBrandingLogoObjectUrl, useCompanyBranding } from '@/features/administration/hooks/useCompany'
import { SidebarNav } from './SidebarNav'

/**
 * Desktop-only fixed sidebar (md and up). Mobile uses the Sheet drawer
 * rendered from Header instead — see Header.tsx.
 */
export function Sidebar() {
  const { data: branding } = useCompanyBranding()
  const logoObjectUrl = useBrandingLogoObjectUrl(branding?.logo_url)

  return (
    <aside className="hidden w-64 shrink-0 border-r bg-card md:flex md:flex-col">
      <div className="flex h-14 items-center gap-2 border-b px-4">
        {logoObjectUrl ? (
          <img src={logoObjectUrl} alt="" className="size-5 shrink-0 object-contain" />
        ) : (
          <Package className="size-5 shrink-0 text-primary" />
        )}
        <span className="truncate font-semibold">{branding?.name ?? 'Loading…'}</span>
      </div>
      <div className="flex-1 overflow-y-auto">
        <SidebarNav />
      </div>
    </aside>
  )
}
