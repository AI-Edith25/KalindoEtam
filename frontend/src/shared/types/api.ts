export interface PaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export interface ApiResponse<T> {
  success: boolean
  message: string
  data: T
  meta?: PaginationMeta
}

export interface ApiListResponse<T> extends ApiResponse<T[]> {
  meta: PaginationMeta
}

export interface ValidationErrorBody {
  message: string
  errors: Record<string, string[]>
}
