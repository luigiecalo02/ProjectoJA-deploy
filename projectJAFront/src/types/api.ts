export interface PaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  from?: number | null
  to?: number | null
}

export interface ApiEnvelope<T = unknown> {
  success: boolean
  message: string | null
  data: T
  errors: Record<string, string[]> | null
  pagination: PaginationMeta | null
  meta: Record<string, unknown> | null
}

export interface ApiErrorBody {
  success: false
  message: string | null
  data: null
  errors: Record<string, string[]> | null
  pagination: null
  meta: null
}
