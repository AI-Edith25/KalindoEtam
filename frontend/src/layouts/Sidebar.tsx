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
    <aside className="hidden w-64 shrink-0 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground md:flex">
      <div className="flex h-14 items-center gap-2 border-b border-sidebar-border px-4">
        {logoObjectUrl ? (
          <img src={logoObjectUrl} alt="" className="size-5 shrink-0 object-contain" />
        ) : (
          <Package className="size-5 shrink-0 text-sidebar-primary" />
        )}
        <span className="truncate font-mono text-sm font-semibold tracking-wide uppercase">
          {branding?.name ?? 'Loading…'}
        </span>
      </div>
      <div className="flex-1 overflow-y-auto">
        <SidebarNav />
      </div>
      <div className="border-t border-sidebar-border px-4 py-3">
        <span className="inline-flex items-center gap-1.5 rounded-sm border border-sidebar-border bg-sidebar-accent px-2 py-1 font-mono text-[10px] tracking-wider text-sidebar-primary uppercase">
          <span className="size-1.5 rounded-full bg-sidebar-primary" />
          ENV: {import.meta.env.PROD ? 'PRODUCTION' : 'DEVELOPMENT'}
        </span>
      </div>
    </aside>
  )
}
