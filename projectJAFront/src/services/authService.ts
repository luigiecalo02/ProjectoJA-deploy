import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'
import type {
  AuthContextOption,
  AuthUser,
  LoginPayload,
  LoginResult,
  ParticipantRegistrationCompleteResult,
  ParticipantRegistrationStartResult,
  ParticipantRegistrationVerifyResult,
} from '@/modules/auth/types'

export const authService = {
  async login(payload: LoginPayload): Promise<LoginResult> {
    const { data } = await api.post<ApiEnvelope<LoginResult>>('/api/v1/auth/login', payload)
    return data.data
  },

  async startParticipantRegistration(payload: {
    tipo_identificacion: string
    identificacion: string
  }): Promise<ParticipantRegistrationStartResult> {
    const { data } = await api.post<ApiEnvelope<ParticipantRegistrationStartResult>>(
      '/api/v1/auth/participant-registration/start',
      payload,
    )
    return data.data
  },

  async verifyParticipantRegistration(payload: {
    challenge_id: string
    otp: string
  }): Promise<ParticipantRegistrationVerifyResult> {
    const { data } = await api.post<ApiEnvelope<ParticipantRegistrationVerifyResult>>(
      '/api/v1/auth/participant-registration/verify',
      payload,
    )
    return data.data
  },

  async completeParticipantRegistration(payload: {
    verification_token: string
    correo?: string
    telefono?: string
    sexo?: 'M' | 'F'
    nombre1?: string
    apellido1?: string
    password: string
    password_confirmation: string
  }): Promise<ParticipantRegistrationCompleteResult> {
    const { data } = await api.post<ApiEnvelope<ParticipantRegistrationCompleteResult>>(
      '/api/v1/auth/participant-registration/complete',
      payload,
    )
    return data.data
  },

  async logout(): Promise<void> {
    await api.post<ApiEnvelope<null>>('/api/v1/auth/logout')
  },

  async impersonate(userId: number): Promise<LoginResult> {
    const { data } = await api.post<ApiEnvelope<LoginResult>>(`/api/v1/auth/impersonate/${userId}`)
    return data.data
  },

  async stopImpersonation(): Promise<LoginResult> {
    const { data } = await api.post<ApiEnvelope<LoginResult>>('/api/v1/auth/stop-impersonation')
    return data.data
  },

  async me(): Promise<AuthUser> {
    const { data } = await api.get<ApiEnvelope<AuthUser>>('/api/v1/auth/me')
    return data.data
  },

  async contextOptions(): Promise<{
    requires_context: boolean
    contexto: AuthContextOption | null
    options: AuthContextOption[]
  }> {
    const { data } = await api.get<
      ApiEnvelope<{
        requires_context: boolean
        contexto: AuthContextOption | null
        options: AuthContextOption[]
      }>
    >('/api/v1/auth/context-options')
    return (
      data.data ?? {
        requires_context: false,
        contexto: null,
        options: [],
      }
    )
  },

  async setContext(payload: {
    organizacion_id?: number | null
    rol_id: number
  }): Promise<AuthUser> {
    const { data } = await api.post<ApiEnvelope<AuthUser>>('/api/v1/auth/context', payload)
    return data.data
  },

  async clearContext(): Promise<AuthUser> {
    const { data } = await api.delete<ApiEnvelope<AuthUser>>('/api/v1/auth/context')
    return data.data
  },

  oauthRedirectUrl(provider: 'google' | 'facebook'): string {
    const base = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000'
    return `${base}/api/v1/auth/oauth/${provider}/redirect`
  },
}
