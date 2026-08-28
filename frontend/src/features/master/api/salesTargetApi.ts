import { createCrudApi } from '@/shared/services/crudApi'
import type { SalesTarget, SalesTargetFormValues } from '../types'

const salesTargetCrud = createCrudApi<SalesTarget, SalesTargetFormValues>('/sales-targets')

export const fetchSalesTargets = salesTargetCrud.fetchList
export const createSalesTarget = salesTargetCrud.create
export const updateSalesTarget = salesTargetCrud.update
export const deleteSalesTarget = salesTargetCrud.remove
