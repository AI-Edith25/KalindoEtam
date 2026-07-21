import type { GeneralLedgerFilterValues } from '../types'

export const emptyGeneralLedgerFilters: GeneralLedgerFilterValues = {
  status: null,
  referenceType: null,
  referenceNumber: '',
  branchId: null,
  companyId: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveGeneralLedgerFilters(filters: GeneralLedgerFilterValues): boolean {
  return (
    filters.status !== null ||
    filters.referenceType !== null ||
    filters.referenceNumber !== '' ||
    filters.branchId !== null ||
    filters.companyId !== null ||
    filters.dateFrom !== '' ||
    filters.dateTo !== ''
  )
}
