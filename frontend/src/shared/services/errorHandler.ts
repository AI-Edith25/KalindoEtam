import axios from 'axios'
import { toast } from 'sonner'
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
    if (error.response?.status === 403) {
      return ''
    }

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

/**
 * The one place that turns a failed request into a toast. A 403 means the
 * backend correctly rejected an action the UI shouldn't have offered in the
 * first place — never the user's fault, so getErrorMessage returns '' for
 * it and this silently no-ops instead of surfacing "You do not have
 * permission…". Every onError handler should call this instead of
 * `toastApiError(error)` directly, so that rule lives once.
 */
export function toastApiError(error: unknown): void {
  const message = getErrorMessage(error)
  if (!message) return
  toast.error(message)
}

export function getFieldErrors(error: unknown): Record<string, string[]> | null {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as Partial<ValidationErrorBody> | undefined
    return data?.errors ?? null
  }

  return null
}
