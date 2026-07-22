import { useQuery } from '@tanstack/react-query'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { fetchBranches, fetchChartOfAccountsLookup, fetchCompaniesLookup, fetchWarehousesLookup } from '../api/lookupsApi'

/**
 * These three lookups back Company/Branch/Chart-of-Account admin data,
 * which is *not* implied by owning the document types that reference them
 * (e.g. Accounting can view Journal Entries without company.view) — unlike
 * fetchItemsLookup/fetchWarehousesLookup/etc, which every caller so far
 * already has permission for. Gating `enabled` on the matching {module}.view
 * permission means the request — and the 403 it would otherwise draw — never
 * fires for a user who lacks it, instead of trying and toasting the failure.
 */
function usePermissionedLookup<T>(queryKey: string, queryFn: () => Promise<T[]>, permission: string, enabled = true) {
  const hasPermission = useHasPermission(permission)
  return useQuery({ queryKey: [queryKey], queryFn, enabled: hasPermission && enabled })
}

export const useBranchesLookup = (enabled = true) => usePermissionedLookup('branches-lookup', fetchBranches, 'branch.view', enabled)

export const useCompaniesLookup = (enabled = true) =>
  usePermissionedLookup('companies-lookup', fetchCompaniesLookup, 'company.view', enabled)

export const useChartOfAccountsLookup = (enabled = true) =>
  usePermissionedLookup('chart-of-accounts-lookup', fetchChartOfAccountsLookup, 'chart_of_account.view', enabled)

export const useWarehousesLookup = (enabled = true) =>
  usePermissionedLookup('warehouses-lookup', fetchWarehousesLookup, 'warehouse.view', enabled)
