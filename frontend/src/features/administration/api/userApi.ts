import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { User, UserFormValues } from '../types'

export async function fetchUsersPaged(page: number): Promise<ApiListResponse<User>> {
  const { data } = await apiClient.get<ApiListResponse<User>>('/users', { params: { page } })
  return data
}

export async function createUser(payload: UserFormValues): Promise<User> {
  const { data } = await apiClient.post<ApiResponse<User>>('/users', payload)
  return data.data
}

export async function updateUser(id: string, payload: Partial<UserFormValues>): Promise<User> {
  const { data } = await apiClient.put<ApiResponse<User>>(`/users/${id}`, payload)
  return data.data
}

export async function activateUser(id: string): Promise<User> {
  const { data } = await apiClient.post<ApiResponse<User>>(`/users/${id}/activate`)
  return data.data
}

export async function deactivateUser(id: string): Promise<User> {
  const { data } = await apiClient.post<ApiResponse<User>>(`/users/${id}/deactivate`)
  return data.data
}

/** Returns the new plain-text password once — no email infra exists to send a reset link (docs/ADMINISTRATION_DESIGN.md Open Question 4). */
export async function resetUserPassword(id: string): Promise<string> {
  const { data } = await apiClient.post<ApiResponse<{ password: string }>>(`/users/${id}/reset-password`)
  return data.data.password
}

export async function assignUserRole(id: string, role: string): Promise<User> {
  const { data } = await apiClient.post<ApiResponse<User>>(`/users/${id}/role`, { role })
  return data.data
}
