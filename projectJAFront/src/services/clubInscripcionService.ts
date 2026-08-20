import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'
import type { AuthUser } from '@/modules/auth/types'

export type CatalogOrg = {
  id: number
  nombre: string
  codigo?: string | null
  tipo_organizacion_id: number
  organizacion_padre_id?: number | null
  pais_id?: number | null
  departamento_id?: number | null
  departamento_ids?: number[]
  ciudad_id?: number | null
  ciudad_ids?: number[]
}

export type CatalogClub = {
  id: number
  organizacion_id: number
  nombre: string
  nombre_corto?: string | null
  cargos_ocupados: Array<{
    cargo: 'director' | 'subdirector' | 'secretaria' | 'tesorero'
    nombre?: string | null
  }>
}

export type PublicFormOptions = {
  enabled: boolean
  allow_request_asociacion: boolean
  allow_request_distrito: boolean
  allow_request_iglesia: boolean
  allow_request_club: boolean
}

export type UbicacionOption = {
  id: number
  nombre: string
  label: string
  pais_id?: number
  departamento_id?: number
}

export type ClubInscripcionPayload = {
  asociacion_id?: number | null
  distrito_id?: number | null
  iglesia_id?: number | null
  club_id?: number | null
  solicitud_asociacion?: {
    union_id?: number | null
    nombre: string
    departamento_ids: number[]
  } | null
  solicitud_distrito?: {
    nombre: string
    departamento_ids: number[]
    ciudad_ids: number[]
  } | null
  solicitud_iglesia?: {
    nombre: string
    direccion: string
    departamento_id?: number | null
    ciudad_id?: number | null
    telefono?: string | null
    correo?: string | null
  } | null
  club?: {
    nombre: string
    nombre_corto?: string | null
    tipo: 'conquistadores' | 'aventureros' | 'guias_mayores'
  } | null
  usuario: {
    cargo: 'director' | 'subdirector' | 'secretaria' | 'tesorero'
    email: string
    password: string
    password_confirmation: string
    persona: {
      tipo_identificacion: string
      identificacion: string
      nombre1: string
      nombre2?: string | null
      apellido1: string
      apellido2?: string | null
      telefono?: string | null
    }
  }
}

export const clubInscripcionService = {
  async options(): Promise<PublicFormOptions> {
    const { data } = await api.get<ApiEnvelope<PublicFormOptions>>('/api/v1/public/club-inscripcion/opciones')
    return data.data
  },

  async catalogClubes(iglesiaId?: number | null): Promise<CatalogClub[]> {
    const { data } = await api.get<ApiEnvelope<CatalogClub[]>>('/api/v1/public/club-inscripcion/clubes', {
      params: { iglesia_id: iglesiaId || undefined },
    })
    return data.data ?? []
  },

  async catalog(tipoId: number, padreId?: number | null): Promise<CatalogOrg[]> {
    const { data } = await api.get<ApiEnvelope<CatalogOrg[]>>('/api/v1/public/club-inscripcion/catalogo', {
      params: {
        tipo_organizacion_id: tipoId,
        organizacion_padre_id: padreId || undefined,
      },
    })
    return data.data ?? []
  },

  async paises(): Promise<UbicacionOption[]> {
    const { data } = await api.get<ApiEnvelope<UbicacionOption[]>>('/api/v1/public/club-inscripcion/paises')
    return data.data ?? []
  },

  async departamentos(paisId?: number | null): Promise<UbicacionOption[]> {
    const { data } = await api.get<ApiEnvelope<UbicacionOption[]>>(
      '/api/v1/public/club-inscripcion/departamentos',
      { params: { pais_id: paisId || undefined } },
    )
    return data.data ?? []
  },

  async ciudades(departamentoId?: number | null, departamentoIds?: number[]): Promise<UbicacionOption[]> {
    const { data } = await api.get<ApiEnvelope<UbicacionOption[]>>('/api/v1/public/club-inscripcion/ciudades', {
      params: {
        departamento_id: departamentoId || undefined,
        departamento_ids: departamentoIds?.length ? departamentoIds : undefined,
      },
    })
    return data.data ?? []
  },

  async register(payload: ClubInscripcionPayload): Promise<{ organizacion_id: number; club_id: number }> {
    const { data } = await api.post<ApiEnvelope<{ organizacion_id: number; club_id: number }>>(
      '/api/v1/public/club-inscripcion',
      payload,
    )
    return data.data
  },
}

export type { AuthUser }
