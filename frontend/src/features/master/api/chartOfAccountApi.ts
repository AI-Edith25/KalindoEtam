import { createCrudApi } from '@/shared/services/crudApi'
import type { ChartOfAccount, ChartOfAccountFormValues } from '../types'

const chartOfAccountCrud = createCrudApi<ChartOfAccount, ChartOfAccountFormValues>('/chart-of-accounts')

export const fetchChartOfAccountsPaged = chartOfAccountCrud.fetchList
export const fetchChartOfAccount = chartOfAccountCrud.fetchOne
export const createChartOfAccount = chartOfAccountCrud.create
export const updateChartOfAccount = chartOfAccountCrud.update
export const deleteChartOfAccount = chartOfAccountCrud.remove
