import { api } from '@/services/api'
import type { ApiEnvelope, PaginationMeta } from '@/types/api'
import type {
  CiudadOption,
  DepartamentoOption,
  Organizacion,
  OrganizacionFormPayload,
  OrganizacionParentOption,
  OrganizacionTreeNode,
  PaisOption,
  TipoOrganizacion,
} from '@/modules/organizaciones/types'

export interface OrganizacionesPage {
  items: Organizacion[]
  pagination: PaginationMeta | null
}

/** Caché en memoria: tipos/países casi no cambian durante la sesión. */
let tiposCache: TipoOrganizacion[] | null = null
let tiposInflight: Promise<TipoOrganizacion[]> | null = null
let paisesCache: PaisOption[] | null = null
let paisesInflight: Promise<PaisOption[]> | null = null

export const organizacionesService = {
  async list(params: {
    page?: number
    per_page?: number
    search?: string
    estado?: boolean | null
    tipo_organizacion_id?: number | null
    estado_aprobacion?: string | null
  } = {}): Promise<OrganizacionesPage> {
    const { data } = await api.get<ApiEnvelope<Organizacion[]>>('/api/v1/organizaciones', {
      params: {
        page: params.page,
        per_page: params.per_page ?? 15,
        q: params.search || undefined,
        estado: params.estado === null || params.estado === undefined ? undefined : params.estado,
        tipo_organizacion_id: params.tipo_organizacion_id || undefined,
        estado_aprobacion: params.estado_aprobacion || undefined,
      },
    })
    return { items: data.data ?? [], pagination: data.pagination }
  },

  async get(id: number): Promise<Organizacion> {
    const { data } = await api.get<ApiEnvelope<Organizacion>>(`/api/v1/organizaciones/${id}`)
    return data.data
  },

  async create(payload: OrganizacionFormPayload): Promise<Organizacion> {
    const { data } = await api.post<ApiEnvelope<Organizacion>>('/api/v1/organizaciones', payload)
    return data.data
  },

  async update(id: number, payload: OrganizacionFormPayload): Promise<Organizacion> {
    const { data } = await api.put<ApiEnvelope<Organizacion>>(`/api/v1/organizaciones/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/api/v1/organizaciones/${id}`)
  },

  async tipos(): Promise<TipoOrganizacion[]> {
    if (tiposCache) return tiposCache
    if (!tiposInflight) {
      tiposInflight = api
        .get<ApiEnvelope<TipoOrganizacion[]>>('/api/v1/organizaciones/tipos')
        .then(({ data }) => {
          tiposCache = data.data ?? []
          return tiposCache
        })
        .finally(() => {
          tiposInflight = null
        })
    }
    return tiposInflight
  },

  async parentOptions(excludeId?: number, tipoOrganizacionId?: number | null): Promise<OrganizacionParentOption[]> {
    const { data } = await api.get<ApiEnvelope<OrganizacionParentOption[]>>(
      '/api/v1/organizaciones/parent-options',
      {
        params: {
          ...(excludeId ? { exclude_id: excludeId } : {}),
          ...(tipoOrganizacionId ? { tipo_organizacion_id: tipoOrganizacionId } : {}),
        },
      },
    )
    return data.data ?? []
  },

  async tree(
    excludeId?: number,
    filters: {
      search?: string
      estado?: boolean | null
      tipo_organizacion_id?: number | null
      estado_aprobacion?: string | null
    } = {},
  ): Promise<OrganizacionTreeNode[]> {
    const { data } = await api.get<ApiEnvelope<OrganizacionTreeNode[]>>('/api/v1/organizaciones/tree', {
      params: {
        ...(excludeId ? { exclude_id: excludeId } : {}),
        q: filters.search || undefined,
        estado:
          filters.estado === null || filters.estado === undefined ? undefined : filters.estado,
        tipo_organizacion_id: filters.tipo_organizacion_id || undefined,
        estado_aprobacion: filters.estado_aprobacion || undefined,
      },
    })
    return data.data ?? []
  },

  async approvedOptions(tipoId: number, padreId?: number | null): Promise<OrganizacionParentOption[]> {
    const { data } = await api.get<ApiEnvelope<OrganizacionParentOption[]>>(
      '/api/v1/organizaciones/approved-options',
      {
        params: {
          tipo_organizacion_id: tipoId,
          organizacion_padre_id: padreId || undefined,
        },
      },
    )
    return data.data ?? []
  },

  async approvedClubs(iglesiaId: number): Promise<Array<{ id: number; nombre: string; organizacion_id: number }>> {
    const { data } = await api.get<ApiEnvelope<Array<{ id: number; nombre: string; organizacion_id: number }>>>(
      `/api/v1/organizaciones/${iglesiaId}/approved-clubs`,
    )
    return data.data ?? []
  },

  async aprobar(id: number, observacion?: string): Promise<Organizacion> {
    const { data } = await api.post<ApiEnvelope<Organizacion>>(`/api/v1/organizaciones/${id}/aprobar`, {
      observacion,
    })
    return data.data
  },

  async rechazar(id: number, observacion?: string): Promise<Organizacion> {
    const { data } = await api.post<ApiEnvelope<Organizacion>>(`/api/v1/organizaciones/${id}/rechazar`, {
      observacion,
    })
    return data.data
  },

  async reubicar(
    id: number,
    payload: { asociacion_id: number; distrito_id: number; iglesia_id: number; club_id?: number | null; observacion?: string },
  ): Promise<Organizacion> {
    const { data } = await api.post<ApiEnvelope<Organizacion>>(`/api/v1/organizaciones/${id}/reubicar`, payload)
    return data.data
  },

  async paises(): Promise<PaisOption[]> {
    if (paisesCache) return paisesCache
    if (!paisesInflight) {
      paisesInflight = api
        .get<ApiEnvelope<PaisOption[]>>('/api/v1/ubicacion/paises')
        .then(({ data }) => {
          paisesCache = data.data ?? []
          return paisesCache
        })
        .finally(() => {
          paisesInflight = null
        })
    }
    return paisesInflight
  },

  async departamentos(paisId?: number | null): Promise<DepartamentoOption[]> {
    const { data } = await api.get<ApiEnvelope<DepartamentoOption[]>>('/api/v1/ubicacion/departamentos', {
      params: paisId ? { pais_id: paisId } : undefined,
    })
    return data.data ?? []
  },

  async ciudades(departamentoId?: number | null, departamentoIds?: number[]): Promise<CiudadOption[]> {
    const { data } = await api.get<ApiEnvelope<CiudadOption[]>>('/api/v1/ubicacion/ciudades', {
      params: {
        departamento_id: departamentoId || undefined,
        departamento_ids: departamentoIds?.length ? departamentoIds : undefined,
      },
    })
    return data.data ?? []
  },
}
