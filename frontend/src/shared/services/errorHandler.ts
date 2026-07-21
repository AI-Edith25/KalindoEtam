import axios from 'axios'
import type { ValidationErrorBody } from '@/shared/types/api'

/**
 * Normalizes any error thrown by the API client into one user-facing
 * string. Two backend error shapes exist: BusinessException's
 * {success:false, message, data:null} and Laravel's default FormRequest
 * validation shape {message, errors}. Both are handled here so callers
 * (toasts, forms) never need to know which one they got.
 */
export function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as Partial<ValidationErrorBody> & { message?: string } | undefined

    if (data?.errors) {
      const firstFieldErrors = Object.values(data.errors)[0]
      if (firstFieldErrors?.[0]) {
        return firstFieldErrors[0]
      }
    }

    if (data?.message) {
      return data.message
    }

    if (error.code === 'ERR_NETWORK') {
      return 'Cannot reach the server. Check your connection and try again.'
    }

    return error.message
  }

  if (error instanceof Error) {
    return error.message
  }

  return 'Something went wrong. Please try again.'
}

export function getFieldErrors(error: unknown): Record<string, string[]> | null {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as Partial<ValidationErrorBody> | undefined
    return data?.errors ?? null
  }

  return null
}
