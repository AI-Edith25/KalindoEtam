import { apiClient } from './apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'

/**
 * Every master-data entity (Item, Supplier, Customer, Warehouse,
 * ItemGroup, Uom) exposes the identical Laravel apiResource shape:
 * paginated index, show, store, update, destroy, wrapped in the same
 * {success,message,data,meta?} envelope. One factory for all of them
 * instead of five copies of the same five functions.
 */
export function createCrudApi<T, TPayload = Partial<T>>(basePath: string) {
  return {
    fetchList: async (page: number): Promise<ApiListResponse<T>> => {
      const { data } = await apiClient.get<ApiListResponse<T>>(basePath, { params: { page } })
      return data
    },
    fetchOne: async (id: string): Promise<T> => {
      const { data } = await apiClient.get<ApiResponse<T>>(`${basePath}/${id}`)
      return data.data
    },
    create: async (payload: TPayload): Promise<T> => {
      const { data } = await apiClient.post<ApiResponse<T>>(basePath, payload)
      return data.data
    },
    update: async (id: string, payload: TPayload): Promise<T> => {
      const { data } = await apiClient.put<ApiResponse<T>>(`${basePath}/${id}`, payload)
      return data.data
    },
    remove: async (id: string): Promise<void> => {
      await apiClient.delete(`${basePath}/${id}`)
    },
  }
}
