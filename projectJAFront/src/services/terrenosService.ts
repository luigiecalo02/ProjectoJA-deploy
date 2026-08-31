import { api } from '@/services/api'
import { prepareUploadFile } from '@/utils/optimizeImage'
import type { ApiEnvelope, PaginationMeta } from '@/types/api'
import type {
  AsignacionLote,
  ConfiguracionTerreno,
  EstructuraTerreno,
  EventoLote,
  EventoTerreno,
  EventoZona,
  LoteTerreno,
  Terreno,
  TerrenoFormPayload,
  ZonaTerreno,
} from '@/modules/terrenos/types'

export interface TerrenosPage {
  items: Terreno[]
  pagination: PaginationMeta | null
}

export const terrenosService = {
  async list(params: { page?: number; per_page?: number; search?: string; estado?: string; lugar_id?: number } = {}): Promise<TerrenosPage> {
    const { data } = await api.get<ApiEnvelope<Terreno[]>>('/api/v1/terrenos', {
      params: {
        page: params.page,
        per_page: params.per_page,
        q: params.search || undefined,
        estado: params.estado || undefined,
        lugar_id: params.lugar_id || undefined,
      },
    })
    return { items: data.data ?? [], pagination: data.pagination }
  },

  async get(id: number): Promise<Terreno> {
    const { data } = await api.get<ApiEnvelope<Terreno>>(`/api/v1/terrenos/${id}`)
    return data.data
  },

  async create(payload: TerrenoFormPayload): Promise<Terreno> {
    const { data } = await api.post<ApiEnvelope<Terreno>>('/api/v1/terrenos', payload)
    return data.data
  },

  async update(id: number, payload: Partial<TerrenoFormPayload>): Promise<Terreno> {
    const { data } = await api.put<ApiEnvelope<Terreno>>(`/api/v1/terrenos/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/api/v1/terrenos/${id}`)
  },

  async uploadImagen(id: number, file: File): Promise<Terreno> {
    const form = new FormData()
    form.append('imagen', await prepareUploadFile(file))
    const { data } = await api.post<ApiEnvelope<Terreno>>(`/api/v1/terrenos/${id}/imagen`, form)
    return data.data
  },

  async listConfigs(terrenoId: number): Promise<ConfiguracionTerreno[]> {
    const { data } = await api.get<ApiEnvelope<ConfiguracionTerreno[]>>(
      `/api/v1/terrenos/${terrenoId}/configuraciones`,
    )
    return data.data ?? []
  },

  async getConfig(configId: number): Promise<ConfiguracionTerreno> {
    const { data } = await api.get<ApiEnvelope<ConfiguracionTerreno>>(`/api/v1/configuraciones-terreno/${configId}`)
    return data.data
  },

  async createConfig(terrenoId: number, payload: Partial<ConfiguracionTerreno>): Promise<ConfiguracionTerreno> {
    const { data } = await api.post<ApiEnvelope<ConfiguracionTerreno>>(
      `/api/v1/terrenos/${terrenoId}/configuraciones`,
      payload,
    )
    return data.data
  },

  async updateConfig(id: number, payload: Partial<ConfiguracionTerreno>): Promise<ConfiguracionTerreno> {
    const { data } = await api.put<ApiEnvelope<ConfiguracionTerreno>>(`/api/v1/configuraciones-terreno/${id}`, payload)
    return data.data
  },

  async removeConfig(id: number): Promise<void> {
    await api.delete(`/api/v1/configuraciones-terreno/${id}`)
  },

  async duplicateConfig(id: number, nombre?: string): Promise<ConfiguracionTerreno> {
    const { data } = await api.post<ApiEnvelope<ConfiguracionTerreno>>(
      `/api/v1/configuraciones-terreno/${id}/duplicar`,
      { nombre },
    )
    return data.data
  },

  async createEstructura(terrenoId: number, payload: Partial<EstructuraTerreno>): Promise<EstructuraTerreno> {
    const { data } = await api.post<ApiEnvelope<EstructuraTerreno>>(
      `/api/v1/terrenos/${terrenoId}/estructuras`,
      payload,
    )
    return data.data
  },

  async updateEstructura(id: number, payload: Partial<EstructuraTerreno>): Promise<EstructuraTerreno> {
    const { data } = await api.put<ApiEnvelope<EstructuraTerreno>>(`/api/v1/estructuras-terreno/${id}`, payload)
    return data.data
  },

  async removeEstructura(id: number): Promise<void> {
    await api.delete(`/api/v1/estructuras-terreno/${id}`)
  },

  async createZona(configId: number, payload: Partial<ZonaTerreno>): Promise<ZonaTerreno> {
    const { data } = await api.post<ApiEnvelope<ZonaTerreno>>(
      `/api/v1/configuraciones-terreno/${configId}/zonas`,
      payload,
    )
    return data.data
  },

  async updateZona(zonaId: number, payload: Partial<ZonaTerreno>): Promise<ZonaTerreno> {
    const { data } = await api.put<ApiEnvelope<ZonaTerreno>>(`/api/v1/zonas-terreno/${zonaId}`, payload)
    return data.data
  },

  async removeZona(zonaId: number): Promise<void> {
    await api.delete(`/api/v1/zonas-terreno/${zonaId}`)
  },

  async createLote(zonaId: number, payload: Partial<LoteTerreno>): Promise<LoteTerreno> {
    const { data } = await api.post<ApiEnvelope<LoteTerreno>>(`/api/v1/zonas-terreno/${zonaId}/lotes`, payload)
    return data.data
  },

  async createLoteOnConfig(configId: number, payload: Partial<LoteTerreno>): Promise<LoteTerreno> {
    const { data } = await api.post<ApiEnvelope<LoteTerreno>>(
      `/api/v1/configuraciones-terreno/${configId}/lotes`,
      payload,
    )
    return data.data
  },

  async updateLote(loteId: number, payload: Partial<LoteTerreno>): Promise<LoteTerreno> {
    const { data } = await api.put<ApiEnvelope<LoteTerreno>>(`/api/v1/lotes-terreno/${loteId}`, payload)
    return data.data
  },

  async removeLote(loteId: number): Promise<void> {
    await api.delete(`/api/v1/lotes-terreno/${loteId}`)
  },

  async getDistribucion(eventId: number): Promise<EventoTerreno | null> {
    const { data } = await api.get<ApiEnvelope<EventoTerreno | null>>(`/api/v1/events/${eventId}/distribucion`)
    return data.data
  },

  async attachTerreno(
    eventId: number,
    terrenoId: number,
    configuracionId: number,
    descripcion?: string,
  ): Promise<EventoTerreno> {
    const { data } = await api.post<ApiEnvelope<EventoTerreno>>(`/api/v1/events/${eventId}/distribucion`, {
      terreno_id: terrenoId,
      configuracion_terreno_id: configuracionId,
      descripcion,
    })
    return data.data
  },

  async detachTerreno(eventId: number): Promise<void> {
    await api.delete(`/api/v1/events/${eventId}/distribucion`)
  },

  async createEventoZona(eventoTerrenoId: number, payload: Partial<EventoZona>): Promise<EventoZona> {
    const { data } = await api.post<ApiEnvelope<EventoZona>>(`/api/v1/eventos-terrenos/${eventoTerrenoId}/zonas`, payload)
    return data.data
  },

  async updateEventoZona(id: number, payload: Partial<EventoZona>): Promise<EventoZona> {
    const { data } = await api.put<ApiEnvelope<EventoZona>>(`/api/v1/eventos-zonas/${id}`, payload)
    return data.data
  },

  async removeEventoZona(id: number): Promise<void> {
    await api.delete(`/api/v1/eventos-zonas/${id}`)
  },

  async createEventoLote(eventoZonaId: number, payload: Partial<EventoLote>): Promise<EventoLote> {
    const { data } = await api.post<ApiEnvelope<EventoLote>>(`/api/v1/eventos-zonas/${eventoZonaId}/lotes`, payload)
    return data.data
  },

  async updateEventoLote(id: number, payload: Partial<EventoLote>): Promise<EventoLote> {
    const { data } = await api.put<ApiEnvelope<EventoLote>>(`/api/v1/eventos-lotes/${id}`, payload)
    return data.data
  },

  async removeEventoLote(id: number): Promise<void> {
    await api.delete(`/api/v1/eventos-lotes/${id}`)
  },

  async assignLote(
    eventoLoteId: number,
    payload: { club_id: number; cantidad_personas: number; observaciones?: string },
  ): Promise<AsignacionLote> {
    const { data } = await api.post<ApiEnvelope<AsignacionLote>>(
      `/api/v1/eventos-lotes/${eventoLoteId}/asignaciones`,
      payload,
    )
    return data.data
  },

  async selfAssignLote(
    eventoLoteId: number,
    observaciones?: string,
  ): Promise<AsignacionLote> {
    const { data } = await api.post<ApiEnvelope<AsignacionLote>>(
      `/api/v1/eventos-lotes/${eventoLoteId}/autoasignacion`,
      { observaciones },
    )
    return data.data
  },

  async updateAsignacion(
    id: number,
    payload: Partial<{ club_id: number; cantidad_personas: number; observaciones: string }>,
  ): Promise<AsignacionLote> {
    const { data } = await api.put<ApiEnvelope<AsignacionLote>>(`/api/v1/asignaciones-lotes/${id}`, payload)
    return data.data
  },

  async liberarAsignacion(id: number): Promise<AsignacionLote> {
    const { data } = await api.post<ApiEnvelope<AsignacionLote>>(`/api/v1/asignaciones-lotes/${id}/liberar`)
    return data.data
  },
}
