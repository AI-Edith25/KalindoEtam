import { apiClient } from './apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { AuthUser, ChangePasswordPayload, LoginPayload, LoginResult, ResetPasswordUnverifiedPayload } from '@/shared/types/auth'

export async function loginRequest(payload: LoginPayload): Promise<LoginResult> {
  const response = await apiClient.post<ApiResponse<LoginResult>>('/auth/login', payload)
  return response.data.data
}

export async function logoutRequest(): Promise<void> {
  await apiClient.post('/auth/logout')
}

export async function meRequest(): Promise<AuthUser> {
  const response = await apiClient.get<ApiResponse<AuthUser>>('/auth/me')
  return response.data.data
}

export async function changePasswordRequest(payload: ChangePasswordPayload): Promise<void> {
  await apiClient.post('/auth/change-password', payload)
}

export async function resetPasswordUnverifiedRequest(payload: ResetPasswordUnverifiedPayload): Promise<void> {
  await apiClient.post('/auth/reset-password-unverified', payload)
}
