import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios'
import type { ApiEnvelope } from '@/types/api'
import { clearAllFieldData } from '@/modules/fieldMode/db'

const TOKEN_KEY = 'projectja_token'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000',
  timeout: 30000,
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
      localStorage.removeItem('projectja_impersonator_token')
      localStorage.removeItem('projectja_impersonator_user')
      void clearAllFieldData()
      const path = window.location.pathname
      if (
        !path.startsWith('/login')
        && !path.startsWith('/registrar-club')
        && !path.startsWith('/recuperar-contrasena')
        && !path.startsWith('/restablecer-contrasena')
        && !path.startsWith('/confirmar-cuenta')
        && !path.startsWith('/eventos-publicos')
        && !path.startsWith('/auth/callback')
      ) {
        window.location.assign('/login')
      }
    }
    return Promise.reject(error)
  },
)

export function isNetworkError(error: unknown): boolean {
  return axios.isAxiosError(error) && !error.response
}

export function isUnauthorizedError(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 401
}

export function getApiErrorMessage(error: unknown, fallback = 'Error inesperado'): string {
  if (axios.isAxiosError(error)) {
    if (error.code === 'ECONNABORTED' || /timeout/i.test(error.message || '')) {
      return 'La solicitud tardó demasiado. Intenta de nuevo.'
    }
    if (!error.response) {
      return 'No se pudo conectar con el servidor.'
    }

    const data = error.response.data as ApiEnvelope | undefined

    if (data?.errors) {
      const messages = Object.values(data.errors).flatMap((item) => {
        if (Array.isArray(item)) return item.map((value) => String(value))
        if (typeof item === 'string') return [item]
        return []
      }).filter(Boolean)
      if (messages.length) {
        return messages.join(' ')
      }
    }

    if (data?.message) {
      return data.message
    }

    if (error.response.status >= 500) {
      return 'Error del servidor. Intenta de nuevo.'
    }

    return fallback
  }

  if (error instanceof Error && error.message.trim()) {
    return error.message
  }

  return fallback
}

export function resolveFileUrl(url: string | null | undefined): string | null {
  if (!url?.trim()) {
    return null
  }

  const trimmed = url.trim()
  if (trimmed.startsWith('blob:') || trimmed.startsWith('data:')) {
    return trimmed
  }

  const base = String(api.defaults.baseURL || '').replace(/\/$/, '')
  const stored = publicDiskPath(trimmed)
  if (stored) {
    return `${base}/api/v1/files/${stored}`
  }

  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed
  }

  if (trimmed.startsWith('/')) {
    return `${base}${trimmed}`
  }

  return `${base}/api/v1/files/${trimmed}`
}

function publicDiskPath(url: string): string | null {
  try {
    const path = /^https?:\/\//i.test(url) ? new URL(url).pathname : url
    const normalized = path.replace(/\\/g, '/')
    for (const prefix of ['/api/v1/files/', '/storage/', 'api/v1/files/', 'storage/']) {
      if (!normalized.startsWith(prefix)) continue
      const rest = normalized.slice(prefix.length).replace(/^\/+/, '')
      return rest && !rest.includes('..') ? rest : null
    }
  } catch {
    return null
  }
  return null
}

export { TOKEN_KEY }
