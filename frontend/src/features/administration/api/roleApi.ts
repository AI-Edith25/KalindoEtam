import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Permission, Role } from '../types'

export async function fetchRoles(): Promise<Role[]> {
  const { data } = await apiClient.get<ApiListResponse<Role>>('/roles')
  return data.data
}

export async function fetchPermissions(): Promise<Permission[]> {
  const { data } = await apiClient.get<ApiResponse<Permission[]>>('/permissions')
  return data.data
}

export async function createRole(name: string): Promise<Role> {
  const { data } = await apiClient.post<ApiResponse<Role>>('/roles', { name })
  return data.data
}

/** Full replace, matching RoleController::assignPermissions()'s own syncPermissions() semantics — see docs/ADMINISTRATION_DESIGN.md §5. */
export async function assignRolePermissions(roleId: string, permissions: string[]): Promise<Role> {
  const { data } = await apiClient.post<ApiResponse<Role>>(`/roles/${roleId}/permissions`, { permissions })
  return data.data
}
