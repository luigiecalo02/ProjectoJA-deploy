import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'
import type { MenuPage, PageFormPayload, PageFront, RolePage } from '@/modules/roles/types'

export const pagesService = {
  async menu(): Promise<MenuPage[]> {
    const { data } = await api.get<ApiEnvelope<MenuPage[]>>('/api/v1/menu')
    return data.data ?? []
  },

  async create(payload: PageFormPayload): Promise<RolePage> {
    const { data } = await api.post<ApiEnvelope<RolePage>>('/api/v1/pages', payload)
    return data.data
  },

  async updateFront(id: number, front: PageFront): Promise<RolePage> {
    const { data } = await api.patch<ApiEnvelope<RolePage>>(`/api/v1/pages/${id}`, { front })
    return data.data
  },
}
