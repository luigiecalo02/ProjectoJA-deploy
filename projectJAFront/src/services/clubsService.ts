import { api } from '@/services/api'
import { prepareUploadFile } from '@/utils/optimizeImage'
import type { ApiEnvelope, PaginationMeta } from '@/types/api'
import type {
  Club,
  ClubFormPayload,
  ClubDirectorUser,
  DirectorsPayload,
  Persona,
  PersonaFormPayload,
  PersonaOrganizacionOptions,
} from '@/modules/clubs/types'

export interface ClubsPage {
  items: Club[]
  pagination: PaginationMeta | null
}

export interface PersonasPage {
  items: Persona[]
  pagination: PaginationMeta | null
}

export const clubsService = {
  async list(params: { page?: number; per_page?: number; search?: string; is_active?: boolean | null } = {}): Promise<ClubsPage> {
    const { data } = await api.get<ApiEnvelope<Club[]>>('/api/v1/clubs', {
      params: {
        page: params.page,
        per_page: params.per_page,
        q: params.search || undefined,
        is_active: params.is_active === null ? undefined : params.is_active,
      },
    })
    return { items: data.data ?? [], pagination: data.pagination }
  },

  async availableForAccount(userId?: number | null): Promise<Club[]> {
    const { data } = await api.get<ApiEnvelope<Club[]>>('/api/v1/clubs/available-for-account', {
      params: {
        user_id: userId || undefined,
      },
    })
    return data.data ?? []
  },

  async iglesiaOptions(): Promise<
    Array<{
      id: number
      nombre: string
      codigo?: string | null
      tipo_nombre?: string | null
      distrito?: string | null
      ciudad?: string | null
    }>
  > {
    const { data } = await api.get<
      ApiEnvelope<
        Array<{
          id: number
          nombre: string
          codigo?: string | null
          tipo_nombre?: string | null
          distrito?: string | null
          ciudad?: string | null
        }>
      >
    >('/api/v1/clubs/iglesia-options')
    return data.data ?? []
  },

  async get(id: number): Promise<Club> {
    const { data } = await api.get<ApiEnvelope<Club>>(`/api/v1/clubs/${id}`)
    return data.data
  },

  async create(payload: ClubFormPayload): Promise<Club> {
    const { data } = await api.post<ApiEnvelope<Club>>('/api/v1/clubs', payload)
    return data.data
  },

  async update(id: number, payload: ClubFormPayload): Promise<Club> {
    const { data } = await api.patch<ApiEnvelope<Club>>(`/api/v1/clubs/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/api/v1/clubs/${id}`)
  },

  async uploadLogo(id: number, file: File): Promise<Club> {
    const body = new FormData()
    body.append('logo', await prepareUploadFile(file))
    const { data } = await api.post<ApiEnvelope<Club>>(`/api/v1/clubs/${id}/logo`, body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data.data
  },

  async syncDirectors(id: number, payload: DirectorsPayload): Promise<Club> {
    const { data } = await api.put<ApiEnvelope<Club>>(`/api/v1/clubs/${id}/directors`, payload)
    return data.data
  },

  async directorsCatalog(position: string, clubId?: number): Promise<ClubDirectorUser[]> {
    const { data } = await api.get<ApiEnvelope<ClubDirectorUser[]>>('/api/v1/clubs/directors-catalog', {
      params: {
        position,
        club_id: clubId,
      },
    })
    return data.data ?? []
  },
}

export const personasService = {
  async list(params: {
    page?: number
    per_page?: number
    search?: string
    sin_usuario?: boolean
    organizacion_id?: number | null
    organizacion_padre_id?: number | null
    solo_tipo_club?: boolean
  } = {}): Promise<PersonasPage> {
    const { data } = await api.get<ApiEnvelope<Persona[]>>('/api/v1/personas', {
      params: {
        page: params.page,
        per_page: params.per_page ?? 50,
        q: params.search || undefined,
        sin_usuario:
          params.sin_usuario === undefined ? undefined : params.sin_usuario ? 1 : 0,
        organizacion_id: params.organizacion_id || undefined,
        organizacion_padre_id: params.organizacion_padre_id || undefined,
        solo_tipo_club: params.solo_tipo_club ? 1 : undefined,
      },
    })
    return { items: data.data ?? [], pagination: data.pagination }
  },

  async get(id: number): Promise<Persona> {
    const { data } = await api.get<ApiEnvelope<Persona>>(`/api/v1/personas/${id}`)
    return data.data
  },

  async organizacionOptions(params: { solo_tipo_club?: boolean } = {}): Promise<PersonaOrganizacionOptions> {
    const { data } = await api.get<ApiEnvelope<PersonaOrganizacionOptions>>(
      '/api/v1/personas/organizacion-options',
      {
        params: {
          solo_tipo_club: params.solo_tipo_club ? 1 : undefined,
        },
      },
    )
    return (
      data.data ?? {
        mode: 'admin',
        locked: false,
        default_ids: [],
        options: [],
      }
    )
  },

  async create(payload: PersonaFormPayload): Promise<Persona> {
    const { data } = await api.post<ApiEnvelope<Persona>>('/api/v1/personas', payload)
    return data.data
  },

  async update(id: number, payload: PersonaFormPayload): Promise<Persona> {
    const { data } = await api.patch<ApiEnvelope<Persona>>(`/api/v1/personas/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/api/v1/personas/${id}`)
  },
}
