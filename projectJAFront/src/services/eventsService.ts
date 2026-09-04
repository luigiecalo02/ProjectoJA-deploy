import { api } from '@/services/api'
import { prepareUploadFile } from '@/utils/optimizeImage'
import type { ApiEnvelope, PaginationMeta } from '@/types/api'
import type {
  ClubEvent,
  EventFormPayload,
  EventListParams,
  TipoEvento,
  TipoSeguro,
  ProductoServicio,
  ProductoServicioPayload,
  EventoProductoServicioOferta,
  EventoProductoServicioSyncPayload,
  RosterCobertura,
  EventoInscripcion,
  EventoInscripcionEnrollPayload,
  EventoInscripcionRevisionPayload,
  EventoComprobanteRevisionPayload,
  EventoInscripcionComprobante,
  EventoInscripcionComprobanteComentario,
  EventoAcompanantePersona,
  EventoAcompanantePersonaPayload,
  CategoriaSubevento,
  CategoriaSubeventoPayload,
  CriterioEvaluacion,
  CriterioEvaluacionPayload,
  ActivityRoster,
  EventParticipation,
  EventoEvidenciaItem,
  JudgeBoard,
  JudgeCalificacion,
  JudgeEvaluaciones,
  JudgeEvaluacionEstado,
  EventStandings,
  EventStandingsSort,
  EventStandingsTree,
  SeguroConsultaResultado,
} from '@/modules/events/types'
import type { FieldOfflinePack } from '@/modules/fieldMode/types'

export interface EventsPage {
  items: ClubEvent[]
  pagination: PaginationMeta | null
}

export interface SegurosConsultaPage {
  items: SeguroConsultaResultado[]
  pagination: PaginationMeta | null
}

let tiposEventoCache: TipoEvento[] | null = null
let tiposEventoInflight: Promise<TipoEvento[]> | null = null

const IMAGE_FILE_NAME = /\.(jpe?g|png|webp|gif)$/i

function isLikelyImageFile(file: File): boolean {
  return file.type.startsWith('image/') || IMAGE_FILE_NAME.test(file.name)
}

function assertEventImageFile(file: File, maxBytes: number, tooLargeMessage: string): void {
  if (!isLikelyImageFile(file)) {
    throw new Error('Solo se permiten archivos de imagen.')
  }
  if (file.size > maxBytes) {
    throw new Error(tooLargeMessage)
  }
}

async function postEventMedia(url: string, file: File): Promise<ClubEvent> {
  const image = await prepareUploadFile(file)
  const body = new FormData()
  body.append('image', image)
  const { data } = await api.post<ApiEnvelope<ClubEvent>>(url, body, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

function appendEnrollPayload(body: FormData, value: unknown, prefix = ''): void {
  if (value === undefined || value === null || value === '') return
  if (Array.isArray(value)) {
    value.forEach((item, index) => {
      appendEnrollPayload(body, item, prefix ? `${prefix}[${index}]` : String(index))
    })
    return
  }
  if (typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) {
      appendEnrollPayload(body, child, prefix ? `${prefix}[${key}]` : key)
    }
    return
  }
  body.append(prefix, String(value))
}

export const eventsService = {
  async list(params: EventListParams = {}): Promise<EventsPage> {
    const { data } = await api.get<ApiEnvelope<ClubEvent[]>>('/api/v1/events', {
      params: {
        page: params.page,
        per_page: params.per_page,
        q: params.search || undefined,
        is_active: params.is_active === null ? undefined : params.is_active,
        estado: params.estado || undefined,
        tipo_evento_id: params.tipo_evento_id || undefined,
        evento_padre_id: params.evento_padre_id || undefined,
        solo_raiz: params.solo_raiz ? 1 : undefined,
        incluir_hijos: params.incluir_hijos ? 1 : undefined,
      },
    })
    return {
      items: data.data ?? [],
      pagination: data.pagination,
    }
  },

  async tipos(): Promise<TipoEvento[]> {
    if (tiposEventoCache) return tiposEventoCache
    if (!tiposEventoInflight) {
      tiposEventoInflight = api
        .get<ApiEnvelope<TipoEvento[]>>('/api/v1/events/tipos')
        .then(({ data }) => {
          tiposEventoCache = data.data ?? []
          return tiposEventoCache
        })
        .finally(() => {
          tiposEventoInflight = null
        })
    }
    return tiposEventoInflight
  },

  async jueces(): Promise<Array<{ id: number; name: string; email?: string | null }>> {
    const { data } = await api.get<ApiEnvelope<Array<{ id: number; name: string; email?: string | null }>>>(
      '/api/v1/events/jueces',
    )
    return data.data ?? []
  },

  async supervisores(): Promise<Array<{ id: number; name: string; email?: string | null }>> {
    const { data } = await api.get<
      ApiEnvelope<Array<{ id: number; name: string; email?: string | null }>>
    >('/api/v1/events/supervisores')
    return data.data ?? []
  },

  async categoriasSubevento(options: { todos?: boolean } = {}): Promise<CategoriaSubevento[]> {
    const { data } = await api.get<ApiEnvelope<CategoriaSubevento[]>>('/api/v1/events/categorias-subevento', {
      params: options.todos ? { todos: 1 } : undefined,
    })
    return data.data ?? []
  },

  async createCategoriaSubevento(payload: CategoriaSubeventoPayload): Promise<CategoriaSubevento> {
    const { data } = await api.post<ApiEnvelope<CategoriaSubevento>>(
      '/api/v1/events/categorias-subevento',
      payload,
    )
    return data.data
  },

  async updateCategoriaSubevento(id: number, payload: CategoriaSubeventoPayload): Promise<CategoriaSubevento> {
    const { data } = await api.patch<ApiEnvelope<CategoriaSubevento>>(
      `/api/v1/events/categorias-subevento/${id}`,
      payload,
    )
    return data.data
  },

  async removeCategoriaSubevento(id: number): Promise<void> {
    await api.delete(`/api/v1/events/categorias-subevento/${id}`)
  },

  async criteriosEvaluacion(options: { todos?: boolean } = {}): Promise<CriterioEvaluacion[]> {
    const { data } = await api.get<ApiEnvelope<CriterioEvaluacion[]>>('/api/v1/events/criterios-evaluacion', {
      params: options.todos ? { todos: 1 } : undefined,
    })
    return data.data ?? []
  },

  async createCriterioEvaluacion(payload: CriterioEvaluacionPayload): Promise<CriterioEvaluacion> {
    const { data } = await api.post<ApiEnvelope<CriterioEvaluacion>>(
      '/api/v1/events/criterios-evaluacion',
      payload,
    )
    return data.data
  },

  async updateCriterioEvaluacion(id: number, payload: CriterioEvaluacionPayload): Promise<CriterioEvaluacion> {
    const { data } = await api.patch<ApiEnvelope<CriterioEvaluacion>>(
      `/api/v1/events/criterios-evaluacion/${id}`,
      payload,
    )
    return data.data
  },

  async removeCriterioEvaluacion(id: number): Promise<void> {
    await api.delete(`/api/v1/events/criterios-evaluacion/${id}`)
  },

  async tiposSeguro(): Promise<TipoSeguro[]> {
    const { data } = await api.get<ApiEnvelope<TipoSeguro[]>>('/api/v1/events/tipos-seguro')
    return data.data ?? []
  },

  async consultarSeguros(
    q: string,
    page = 1,
    perPage = 9,
  ): Promise<SegurosConsultaPage> {
    const { data } = await api.get<ApiEnvelope<SeguroConsultaResultado[]>>(
      '/api/v1/events/seguros/consulta',
      { params: { q, page, per_page: perPage } },
    )
    return {
      items: data.data ?? [],
      pagination: data.pagination,
    }
  },

  async productosServicios(options: { all?: boolean } = {}): Promise<ProductoServicio[]> {
    const { data } = await api.get<ApiEnvelope<ProductoServicio[]>>('/api/v1/events/productos-servicios', {
      params: options.all ? { all: 1 } : undefined,
    })
    return data.data ?? []
  },

  async createProductoServicio(payload: ProductoServicioPayload): Promise<ProductoServicio> {
    const { data } = await api.post<ApiEnvelope<ProductoServicio>>(
      '/api/v1/events/productos-servicios',
      payload,
    )
    return data.data
  },

  async updateProductoServicio(id: number, payload: ProductoServicioPayload): Promise<ProductoServicio> {
    const { data } = await api.put<ApiEnvelope<ProductoServicio>>(
      `/api/v1/events/productos-servicios/${id}`,
      payload,
    )
    return data.data
  },

  async eventProductosServicios(eventId: number): Promise<EventoProductoServicioOferta[]> {
    const { data } = await api.get<ApiEnvelope<EventoProductoServicioOferta[]>>(
      `/api/v1/events/${eventId}/productos-servicios`,
    )
    return data.data ?? []
  },

  async syncEventProductos(
    eventId: number,
    payload: EventoProductoServicioSyncPayload,
  ): Promise<EventoProductoServicioOferta[]> {
    const { data } = await api.put<ApiEnvelope<EventoProductoServicioOferta[]>>(
      `/api/v1/events/${eventId}/productos-servicios`,
      payload,
    )
    return data.data ?? []
  },

  async rosterCobertura(eventId: number): Promise<RosterCobertura> {
    const { data } = await api.get<ApiEnvelope<RosterCobertura>>(
      `/api/v1/events/${eventId}/roster-cobertura`,
    )
    return data.data
  },

  async searchCompanionPersonas(eventId: number, search: string): Promise<EventoAcompanantePersona[]> {
    const { data } = await api.get<ApiEnvelope<EventoAcompanantePersona[]>>(
      `/api/v1/events/${eventId}/acompanantes/personas`,
      { params: { q: search, limit: 30 } },
    )
    return data.data ?? []
  },

  async createCompanionPersona(
    eventId: number,
    payload: EventoAcompanantePersonaPayload,
  ): Promise<EventoAcompanantePersona> {
    const { data } = await api.post<ApiEnvelope<EventoAcompanantePersona>>(
      `/api/v1/events/${eventId}/acompanantes/personas`,
      payload,
    )
    return data.data
  },

  async enroll(
    eventId: number,
    payload?: EventoInscripcionEnrollPayload,
    receipt?: { valor: number; archivo: File } | null,
  ): Promise<EventoInscripcion> {
    if (payload && receipt?.archivo) {
      const body = new FormData()
      appendEnrollPayload(body, payload)
      body.append('comprobante', await prepareUploadFile(receipt.archivo))
      body.append('comprobante_valor', String(receipt.valor))
      const { data } = await api.post<ApiEnvelope<EventoInscripcion>>(
        `/api/v1/events/${eventId}/enroll`,
        body,
        { timeout: 120000 },
      )
      return data.data
    }
    const { data } = await api.post<ApiEnvelope<EventoInscripcion>>(
      `/api/v1/events/${eventId}/enroll`,
      payload ?? undefined,
    )
    return data.data
  },

  async inscripcionesRevision(eventId: number): Promise<EventoInscripcion[]> {
    const { data } = await api.get<ApiEnvelope<EventoInscripcion[]>>(
      `/api/v1/events/${eventId}/inscripciones-revision`,
    )
    return data.data ?? []
  },

  async getInscripcion(inscripcionId: number): Promise<EventoInscripcion> {
    const { data } = await api.get<ApiEnvelope<EventoInscripcion>>(
      `/api/v1/evento-inscripciones/${inscripcionId}`,
    )
    return data.data
  },

  async uploadComprobante(
    inscripcionId: number,
    payload: { valor: number; archivo: File; movimiento_id?: number | null },
  ): Promise<EventoInscripcionComprobante> {
    const archivo = await prepareUploadFile(payload.archivo)
    const body = new FormData()
    body.append('valor', String(payload.valor))
    body.append('archivo', archivo)
    if (payload.movimiento_id) body.append('movimiento_id', String(payload.movimiento_id))
    const { data } = await api.post<ApiEnvelope<EventoInscripcionComprobante>>(
      `/api/v1/evento-inscripciones/${inscripcionId}/comprobantes`,
      body,
      { timeout: 120000 },
    )
    return data.data
  },

  async deleteComprobante(comprobanteId: number): Promise<void> {
    await api.delete(`/api/v1/evento-inscripcion-comprobantes/${comprobanteId}`)
  },

  async replaceComprobante(
    comprobanteId: number,
    payload: { valor: number; archivo: File },
  ): Promise<EventoInscripcionComprobante> {
    const archivo = await prepareUploadFile(payload.archivo)
    const body = new FormData()
    body.append('valor', String(payload.valor))
    body.append('archivo', archivo)
    const { data } = await api.post<ApiEnvelope<EventoInscripcionComprobante>>(
      `/api/v1/evento-inscripcion-comprobantes/${comprobanteId}/reemplazo`,
      body,
      { timeout: 120000 },
    )
    return data.data
  },

  async addComprobanteComentario(
    comprobanteId: number,
    mensaje: string,
  ): Promise<EventoInscripcionComprobanteComentario> {
    const { data } = await api.post<ApiEnvelope<EventoInscripcionComprobanteComentario>>(
      `/api/v1/evento-inscripcion-comprobantes/${comprobanteId}/comentarios`,
      { mensaje },
    )
    return data.data
  },

  async reviewComprobante(
    comprobanteId: number,
    payload: EventoComprobanteRevisionPayload,
  ): Promise<EventoInscripcionComprobante> {
    const { data } = await api.patch<ApiEnvelope<EventoInscripcionComprobante>>(
      `/api/v1/evento-inscripcion-comprobantes/${comprobanteId}`,
      payload,
    )
    return data.data
  },

  async reviewInscripcion(
    inscripcionId: number,
    payload: EventoInscripcionRevisionPayload,
  ): Promise<EventoInscripcion> {
    const { data } = await api.patch<ApiEnvelope<EventoInscripcion>>(
      `/api/v1/evento-inscripciones/${inscripcionId}/revision`,
      payload,
    )
    return data.data
  },

  async activityRoster(eventId: number): Promise<ActivityRoster> {
    const { data } = await api.get<ApiEnvelope<ActivityRoster>>(
      `/api/v1/events/${eventId}/actividad-participantes`,
    )
    return data.data
  },

  async syncActivityRoster(eventId: number, personaIds: number[]): Promise<ActivityRoster> {
    const { data } = await api.put<ApiEnvelope<ActivityRoster>>(
      `/api/v1/events/${eventId}/actividad-participantes`,
      { persona_ids: personaIds },
    )
    return data.data
  },

  async participation(eventId: number): Promise<EventParticipation> {
    const { data } = await api.get<ApiEnvelope<EventParticipation>>(
      `/api/v1/events/${eventId}/participation`,
    )
    return data.data
  },

  async createEvidencia(
    eventId: number,
    payload: {
      tipo: string
      titulo?: string | null
      descripcion?: string | null
      url?: string | null
      file_id?: number | null
      estado?: string
      archivo?: File | null
    },
  ): Promise<EventoEvidenciaItem> {
    if (payload.archivo) {
      const archivo = await prepareUploadFile(payload.archivo)
      const body = new FormData()
      body.append('tipo', payload.tipo)
      if (payload.titulo) body.append('titulo', payload.titulo)
      if (payload.descripcion) body.append('descripcion', payload.descripcion)
      if (payload.url) body.append('url', payload.url)
      if (payload.estado) body.append('estado', payload.estado)
      body.append('archivo', archivo)

      const { data } = await api.post<ApiEnvelope<EventoEvidenciaItem>>(
        `/api/v1/events/${eventId}/evidencias`,
        body,
        { timeout: 300000 },
      )
      return data.data
    }

    const { data } = await api.post<ApiEnvelope<EventoEvidenciaItem>>(
      `/api/v1/events/${eventId}/evidencias`,
      {
        tipo: payload.tipo,
        titulo: payload.titulo,
        descripcion: payload.descripcion,
        url: payload.url,
        file_id: payload.file_id,
        estado: payload.estado,
      },
    )
    return data.data
  },

  async removeEvidencia(evidenciaId: number): Promise<void> {
    await api.delete(`/api/v1/events/evidencias/${evidenciaId}`)
  },

  async judgeOfflinePack(): Promise<FieldOfflinePack> {
    const { data } = await api.get<ApiEnvelope<FieldOfflinePack>>('/api/v1/events/judge/offline-pack')
    return data.data
  },

  async judgeBoard(
    eventId: number,
    subeventoId?: number | null,
    actividadId?: number | null,
  ): Promise<JudgeBoard> {
    const { data } = await api.get<ApiEnvelope<JudgeBoard>>(`/api/v1/events/${eventId}/judge`, {
      params: {
        ...(subeventoId ? { subevento_id: subeventoId } : {}),
        ...(actividadId ? { actividad_id: actividadId } : {}),
      },
    })
    return data.data
  },

  async judgeEvaluaciones(
    eventId: number,
    params: {
      q?: string
      estado?: JudgeEvaluacionEstado | ''
      distrito?: string
      subevento_id?: number | null
      organizacion_id?: number | null
    } = {},
  ): Promise<JudgeEvaluaciones> {
    const { data } = await api.get<ApiEnvelope<JudgeEvaluaciones>>(
      `/api/v1/events/${eventId}/judge/evaluaciones`,
      {
        params: {
          q: params.q || undefined,
          estado: params.estado || undefined,
          distrito: params.distrito || undefined,
          subevento_id: params.subevento_id || undefined,
          organizacion_id: params.organizacion_id || undefined,
        },
      },
    )
    return data.data
  },

  async saveCalificacion(
    subeventoId: number,
    payload: {
      organizacion_id: number
      puntaje_obtenido?: number | null
      observaciones?: string | null
      criterios?: Array<{ criterio_evaluacion_id: number; puntos: number }>
      puesto_entrega?: string | null
      tiempo_entrega?: string | null
      resultado_obtenido?: number | null
    },
  ): Promise<JudgeCalificacion> {
    const { data } = await api.post<ApiEnvelope<JudgeCalificacion>>(
      `/api/v1/events/${subeventoId}/calificaciones`,
      payload,
    )
    return data.data
  },

  async saveDirectorObservacion(
    subeventoId: number,
    observaciones: string,
  ): Promise<{
    evento_id: number
    organizacion_id: number
    observaciones_director: string
    observaciones_director_updated_at?: string | null
  }> {
    const { data } = await api.post<
      ApiEnvelope<{
        evento_id: number
        organizacion_id: number
        observaciones_director: string
        observaciones_director_updated_at?: string | null
      }>
    >(`/api/v1/events/${subeventoId}/observacion-director`, { observaciones })
    return data.data
  },

  async standings(
    eventId: number,
    params: {
      subevento_id?: number | null
      sort?: EventStandingsSort
      q?: string
    } = {},
  ): Promise<EventStandings> {
    const { data } = await api.get<ApiEnvelope<EventStandings>>(`/api/v1/events/${eventId}/standings`, {
      params: {
        subevento_id: params.subevento_id || undefined,
        sort: params.sort || 'puesto',
        q: params.q || undefined,
      },
    })
    return data.data
  },

  async standingsTree(
    eventId: number,
    params: {
      sort?: EventStandingsSort
      q?: string
    } = {},
  ): Promise<EventStandingsTree> {
    const { data } = await api.get<ApiEnvelope<EventStandingsTree>>(
      `/api/v1/events/${eventId}/standings-tree`,
      {
        params: {
          sort: params.sort || 'puesto',
          q: params.q || undefined,
        },
      },
    )
    return data.data
  },

  async reorderChildren(parentId: number, orderedIds: number[]): Promise<void> {
    await api.post(`/api/v1/events/${parentId}/reorder-children`, { ordered_ids: orderedIds })
  },

  async move(
    id: number,
    payload: { evento_padre_id: number; before_id?: number | null },
  ): Promise<ClubEvent> {
    const { data } = await api.post<ApiEnvelope<ClubEvent>>(`/api/v1/events/${id}/move`, {
      evento_padre_id: payload.evento_padre_id,
      before_id: payload.before_id ?? null,
    })
    return data.data
  },

  async get(id: number): Promise<ClubEvent> {
    const { data } = await api.get<ApiEnvelope<ClubEvent>>(`/api/v1/events/${id}`)
    return data.data
  },

  async create(payload: EventFormPayload): Promise<ClubEvent> {
    const { data } = await api.post<ApiEnvelope<ClubEvent>>('/api/v1/events', payload)
    return data.data
  },

  async update(id: number, payload: EventFormPayload): Promise<ClubEvent> {
    const { data } = await api.patch<ApiEnvelope<ClubEvent>>(`/api/v1/events/${id}`, payload)
    return data.data
  },

  async updateEstado(
    id: number,
    estado: 'borrador' | 'publicado' | 'en_proceso' | 'cerrado',
  ): Promise<ClubEvent> {
    const { data } = await api.patch<ApiEnvelope<ClubEvent>>(`/api/v1/events/${id}/estado`, {
      estado,
    })
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete<ApiEnvelope<null>>(`/api/v1/events/${id}`)
  },

  async duplicate(id: number, payload: { name?: string } = {}): Promise<ClubEvent> {
    const { data } = await api.post<ApiEnvelope<ClubEvent>>(`/api/v1/events/${id}/duplicate`, payload)
    return data.data
  },

  async uploadImage(id: number, file: File): Promise<ClubEvent> {
    assertEventImageFile(file, 5 * 1024 * 1024, 'La imagen no puede superar 5 MB.')
    return postEventMedia(`/api/v1/events/${id}/image`, file)
  },

  async uploadBanner(id: number, file: File): Promise<ClubEvent> {
    assertEventImageFile(file, 10 * 1024 * 1024, 'El banner no puede superar 10 MB.')
    return postEventMedia(`/api/v1/events/${id}/banner`, file)
  },
}
