import { createCrudApi } from '@/shared/services/crudApi'
import type { Branch, BranchFormValues } from '@/features/master/types'

const branchCrud = createCrudApi<Branch, BranchFormValues>('/branches')

export const fetchBranchesPaged = branchCrud.fetchList
export const createBranch = branchCrud.create
export const updateBranch = branchCrud.update
export const deleteBranch = branchCrud.remove
