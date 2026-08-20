import { api } from '@/services/api'

export const accountMailService = {
  async forgot(payload: { email?: string; identificacion?: string }): Promise<{
    email: string
    email_masked: string
    sent: boolean
  }> {
    const { data } = await api.post('/api/v1/auth/password/forgot', payload)
    return data.data
  },

  async reset(payload: { email: string; token: string; password: string; password_confirmation: string }): Promise<void> {
    await api.post('/api/v1/auth/password/reset', payload)
  },

  async verify(id: number, hash: string): Promise<void> {
    await api.post('/api/v1/auth/email/verify', { id, hash })
  },

  async verifyCode(email: string, code: string): Promise<void> {
    await api.post('/api/v1/auth/email/verify-code', { email, code })
  },

  async resend(email: string): Promise<void> {
    await api.post('/api/v1/auth/email/resend', { email })
  },

  async recover(payload: {
    email?: string
    identificacion?: string
  }): Promise<{ email: string; email_masked: string; already_verified: boolean; sent: boolean }> {
    const { data } = await api.post('/api/v1/auth/email/recover', payload)
    return data.data
  },
}
