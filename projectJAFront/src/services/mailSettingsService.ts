import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'

export type MailSettings = {
  host: string
  port: number
  encryption: 'tls' | 'ssl' | 'none'
  username: string
  password: string
  from_address: string
  from_name: string
  password_set: boolean
  configured: boolean
}

export const mailSettingsService = {
  async get(): Promise<MailSettings> {
    const { data } = await api.get<ApiEnvelope<MailSettings>>('/api/v1/settings/mail')
    return data.data
  },

  async update(payload: Omit<MailSettings, 'password_set' | 'configured'> & { password?: string }): Promise<MailSettings> {
    const { data } = await api.put<ApiEnvelope<MailSettings>>('/api/v1/settings/mail', payload)
    return data.data
  },

  async test(to: string): Promise<void> {
    await api.post('/api/v1/settings/mail/test', { to })
  },
}
