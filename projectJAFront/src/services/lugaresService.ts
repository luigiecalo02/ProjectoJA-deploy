import { api } from '@/services/api'
import type { ApiEnvelope, PaginationMeta } from '@/types/api'
import type { Lugar, LugarCatalogos, LugarPayload } from '@/modules/lugares/types'

export interface LugaresPage {
  items: Lugar[]
  pagination: PaginationMeta | null
}

export const lugaresService = {
  async list(
    params: { page?: number; per_page?: number; search?: string; estado?: string } = {},
  ): Promise<LugaresPage> {
    const { data } = await api.get<ApiEnvelope<Lugar[]>>('/api/v1/lugares', {
      params: {
        page: params.page,
        per_page: params.per_page,
        q: params.search || undefined,
        estado: params.estado || undefined,
      },
    })
    return { items: data.data ?? [], pagination: data.pagination }
  },

  async catalogos(): Promise<LugarCatalogos> {
    const { data } = await api.get<ApiEnvelope<LugarCatalogos>>('/api/v1/lugares/catalogos')
    return data.data ?? { terrenos: [], cabanas: [] }
  },

  async get(id: number): Promise<Lugar> {
    const { data } = await api.get<ApiEnvelope<Lugar>>(`/api/v1/lugares/${id}`)
    return data.data
  },

  async create(payload: LugarPayload): Promise<Lugar> {
    const { data } = await api.post<ApiEnvelope<Lugar>>('/api/v1/lugares', payload)
    return data.data
  },

  async update(id: number, payload: Partial<LugarPayload>): Promise<Lugar> {
    const { data } = await api.put<ApiEnvelope<Lugar>>(`/api/v1/lugares/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/api/v1/lugares/${id}`)
  },
}
