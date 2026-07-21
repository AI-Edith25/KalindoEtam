import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchCompanyBranding, fetchCompanyBrandingLogoObjectUrl, fetchCurrentCompany } from '../api/companyApi'

/** Full Company record — Administration > Company page only. Requires company.view; do not use from Header/Sidebar. */
export function useCompany() {
  return useQuery({ queryKey: ['company'], queryFn: fetchCurrentCompany })
}

/** Shared by Sidebar/Header — name/logo only, works for every authenticated user regardless of company.view. React Query dedupes the two callers into one request. */
export function useCompanyBranding() {
  return useQuery({ queryKey: ['company-branding'], queryFn: fetchCompanyBranding })
}

/** Shared by Sidebar/Header — fetches the branding logo as an authenticated blob (same pattern as CompanyPage's own logo preview) and hands back an object URL, or null while there isn't one. */
export function useBrandingLogoObjectUrl(logoUrl: string | null | undefined): string | null {
  const [objectUrl, setObjectUrl] = useState<string | null>(null)

  useEffect(() => {
    if (!logoUrl) {
      setObjectUrl(null)
      return
    }

    let cancelled = false
    fetchCompanyBrandingLogoObjectUrl().then((url) => {
      if (!cancelled) setObjectUrl(url)
    })

    return () => {
      cancelled = true
    }
  }, [logoUrl])

  return objectUrl
}
