import { api } from '@/services/api'
import type { ApiEnvelope, PaginationMeta } from '@/types/api'
import type {
  AlojamientoEvento,
  AsignacionCama,
  Cabana,
  CabanaFloor,
  CabanaLayoutPayload,
  CabanaPayload,
  EventoCabana,
  EventoCabanaPayload,
} from '@/modules/cabanas/types'
import { occupancyOf } from '@/modules/cabanas/layout'

export interface CabanasPage {
  items: Cabana[]
  pagination: PaginationMeta | null
}

function unwrapItems<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && 'items' in payload) {
    const items = (payload as { items?: T[] }).items
    return Array.isArray(items) ? items : []
  }
  return []
}

function normalizeFloor(floor: CabanaFloor): CabanaFloor {
  return {
    ...floor,
    cuartos: (floor.cuartos ?? []).map((room) => ({
      ...room,
      ocupadas: occupancyOf(room),
      camas: (room.camas ?? []).map((bed) => ({
        ...bed,
        ocupadas: occupancyOf(bed),
      })),
    })),
  }
}

function normalizeEventCabana(item: Partial<EventoCabana> & { id: number }): EventoCabana {
  const pisos = (item.pisos ?? item.cabana?.pisos ?? []).map(normalizeFloor)
  const capacidad = Number(item.capacidad_total ?? item.capacidad ?? item.cabana?.capacidad_total ?? 0)
  const ocupadas = occupancyOf(item)
  const nombre = item.nombre || item.cabana?.nombre || 'Cabaña'
  const cabanaId = item.cabana?.id ?? item.cabana_id ?? item.id

  return {
    id: item.id,
    evento_id: item.evento_id ?? 0,
    cabana_id: item.cabana_id ?? cabanaId,
    orden: item.orden ?? 0,
    nombre,
    descripcion: item.descripcion,
    estado: item.estado ?? 'activa',
    pisos,
    ocupadas,
    ocupacion: ocupadas,
    capacidad,
    capacidad_total: capacidad,
    cabana: {
      id: cabanaId,
      nombre,
      pisos,
      capacidad_total: capacidad,
    },
  }
}

function normalizeAlojamiento(payload: AlojamientoEvento): AlojamientoEvento {
  const cabanas = (payload.cabanas ?? []).map((item) => normalizeEventCabana(item))
  const ocupadas = occupancyOf(payload) || cabanas.reduce((sum, item) => sum + occupancyOf(item), 0)

  return {
    ...payload,
    cabanas,
    ocupadas,
    ocupacion: ocupadas,
    capacidad: Number(payload.capacidad ?? cabanas.reduce((sum, item) => sum + Number(item.capacidad_total ?? 0), 0)),
  }
}

export const cabanasService = {
  async list(params: { page?: number; per_page?: number; search?: string; estado?: string } = {}): Promise<CabanasPage> {
    const { data } = await api.get<ApiEnvelope<Cabana[]>>('/api/v1/cabanas', {
      params: {
        page: params.page,
        per_page: params.per_page,
        q: params.search || undefined,
        estado: params.estado || undefined,
      },
    })
    return { items: data.data ?? [], pagination: data.pagination }
  },

  async get(id: number): Promise<Cabana> {
    const { data } = await api.get<ApiEnvelope<Cabana>>(`/api/v1/cabanas/${id}`)
    return data.data
  },

  async create(payload: CabanaPayload): Promise<Cabana> {
    const { data } = await api.post<ApiEnvelope<Cabana>>('/api/v1/cabanas', payload)
    return data.data
  },

  async update(id: number, payload: Partial<CabanaPayload>): Promise<Cabana> {
    const { data } = await api.put<ApiEnvelope<Cabana>>(`/api/v1/cabanas/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/api/v1/cabanas/${id}`)
  },

  async saveLayout(cabanaId: number, payload: CabanaLayoutPayload): Promise<Cabana> {
    const { data } = await api.put<ApiEnvelope<Cabana>>(`/api/v1/cabanas/${cabanaId}/croquis`, {
      pisos: payload.pisos.map((floor) => ({
        nombre: floor.nombre,
        orden: floor.orden,
        ancho: floor.ancho,
        alto: floor.alto,
        cuartos: floor.cuartos.map((room) => ({
          nombre: room.nombre,
          codigo: room.codigo,
          x: room.x,
          y: room.y,
          ancho: room.ancho,
          alto: room.alto,
          genero: room.genero,
          capacidad: room.capacidad,
          camas: room.camas.map((bed) => ({
            codigo: bed.codigo,
            nombre: bed.nombre,
            x: bed.x,
            y: bed.y,
            ancho: bed.ancho ?? 36,
            alto: bed.alto ?? 26,
            rotacion: bed.rotacion ?? 0,
            capacidad: bed.capacidad,
          })),
        })),
      })),
    })
    return data.data
  },

  async getEventCabanas(eventId: number): Promise<EventoCabana[]> {
    const { data } = await api.get<ApiEnvelope<{ items: EventoCabana[] } | EventoCabana[]>>(
      `/api/v1/events/${eventId}/cabanas`,
    )
    return unwrapItems<EventoCabana>(data.data).map((item) => normalizeEventCabana(item))
  },

  async syncEventCabanas(eventId: number, items: EventoCabanaPayload[]): Promise<EventoCabana[]> {
    const { data } = await api.put<ApiEnvelope<{ items: EventoCabana[] } | EventoCabana[]>>(
      `/api/v1/events/${eventId}/cabanas`,
      { items },
    )
    return unwrapItems<EventoCabana>(data.data).map((item) => normalizeEventCabana(item))
  },

  async getAlojamiento(eventId: number): Promise<AlojamientoEvento> {
    const { data } = await api.get<ApiEnvelope<AlojamientoEvento>>(`/api/v1/events/${eventId}/alojamiento`)
    return normalizeAlojamiento(data.data)
  },

  async autoAssign(eventoCamaId: number): Promise<AsignacionCama> {
    const { data } = await api.post<ApiEnvelope<AsignacionCama>>(
      `/api/v1/eventos-cabanas-camas/${eventoCamaId}/autoasignacion`,
    )
    return data.data
  },

  async releaseAssignment(assignmentId: number): Promise<void> {
    await api.post(`/api/v1/asignaciones-cama/${assignmentId}/liberar`)
  },
}
