import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'
import type { PublicFormOptions } from '@/services/clubInscripcionService'

export const publicFormSettingsService = {
  async get(): Promise<PublicFormOptions> {
    const { data } = await api.get<ApiEnvelope<PublicFormOptions>>('/api/v1/settings/public-form')
    return data.data
  },

  async update(payload: PublicFormOptions): Promise<PublicFormOptions> {
    const { data } = await api.put<ApiEnvelope<PublicFormOptions>>('/api/v1/settings/public-form', payload)
    return data.data
  },
}
