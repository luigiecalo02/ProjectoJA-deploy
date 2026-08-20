import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios'
import type { ApiEnvelope } from '@/types/api'

const TOKEN_KEY = 'projectja_token'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  // Dejar que el navegador ponga el boundary en FormData
  if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
    delete config.headers['Content-Type']
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiEnvelope>) => {
    if (error.response?.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem('projectja_user')
      if (!window.location.pathname.startsWith('/login')) {
        window.location.assign('/login')
      }
    }
    return Promise.reject(error)
  },
)

export function getApiErrorMessage(error: unknown, fallback = 'Error inesperado'): string {
  if (!axios.isAxiosError(error)) {
    return fallback
  }

  const data = error.response?.data as ApiEnvelope | undefined

  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first[0]) {
      return String(first[0])
    }
    if (typeof first === 'string') {
      return first
    }
  }

  if (data?.message && data.message !== 'Datos inválidos') {
    return data.message
  }

  if (data?.message) {
    return data.message
  }

  return fallback
}

export function resolveFileUrl(url: string | null | undefined): string | null {
  if (!url?.trim()) {
    return null
  }

  const trimmed = url.trim()
  if (/^https?:\/\//i.test(trimmed) || trimmed.startsWith('data:')) {
    return trimmed
  }

  const base = String(api.defaults.baseURL || '').replace(/\/$/, '')
  if (trimmed.startsWith('/')) {
    return `${base}${trimmed}`
  }

  return `${base}/storage/${trimmed}`
}

export { TOKEN_KEY }
