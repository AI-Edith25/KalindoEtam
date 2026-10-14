import { apiClient } from './apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type {
  AuthUser,
  ChangePasswordPayload,
  ForgotPasswordPayload,
  LoginPayload,
  LoginResult,
  RegisterPayload,
  ResetPasswordPayload,
} from '@/shared/types/auth'

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

export async function registerRequest(payload: RegisterPayload): Promise<void> {
  await apiClient.post('/auth/register', payload)
}

export async function forgotPasswordRequest(payload: ForgotPasswordPayload): Promise<void> {
  await apiClient.post('/auth/forgot-password', payload)
}

export async function resetPasswordRequest(payload: ResetPasswordPayload): Promise<void> {
  await apiClient.post('/auth/reset-password', payload)
}
