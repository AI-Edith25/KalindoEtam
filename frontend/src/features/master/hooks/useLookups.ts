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
 *
 * `altPermission` mirrors the backend's own `$viewOr` mechanism
 * (routes/api.php) for the same reason: a caller that structurally needs
 * this data for a *different* module it does own (e.g. Warehouse create/edit
 * needs the Branch it belongs to) shouldn't be blocked just because it
 * lacks the source module's own view permission.
 */
function usePermissionedLookup<T>(
  queryKey: string,
  queryFn: () => Promise<T[]>,
  permission: string,
  enabled = true,
  altPermission = '',
) {
  const hasPermission = useHasPermission(permission)
  const hasAltPermission = useHasPermission(altPermission)
  return useQuery({ queryKey: [queryKey], queryFn, enabled: (hasPermission || hasAltPermission) && enabled })
}

/**
 * `altPermission` defaults to none — Accounting's report filter bars call
 * this with no args and stay gated strictly on `administration.branch.view`,
 * unchanged. The Warehouse feature passes `master.warehouses.view` explicitly
 * (see WarehouseFormDrawer/WarehouseListPage/WarehouseDetailDrawer) — see
 * backend routes/api.php's matching `$viewOr` on the `branches` resource.
 */
export const useBranchesLookup = (enabled = true, altPermission = '') =>
  usePermissionedLookup('branches-lookup', fetchBranches, 'administration.branch.view', enabled, altPermission)

export const useCompaniesLookup = (enabled = true) =>
  usePermissionedLookup('companies-lookup', fetchCompaniesLookup, 'administration.company.view', enabled)

export const useChartOfAccountsLookup = (enabled = true) =>
  usePermissionedLookup('chart-of-accounts-lookup', fetchChartOfAccountsLookup, 'master.chart_of_accounts.view', enabled)

export const useWarehousesLookup = (enabled = true) =>
  usePermissionedLookup('warehouses-lookup', fetchWarehousesLookup, 'master.warehouses.view', enabled)
