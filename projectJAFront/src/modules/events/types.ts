import type { AsignacionCama } from '@/modules/cabanas/types'

export interface EventOrganizacionRef {
  id: number
  nombre: string
  codigo?: string | null
}

export interface EventTipoOrganizacionRef {
  id: number
  nombre: string
}

export interface TipoEvento {
  id: number
  nombre: string
  slug: string
  descripcion?: string | null
  color?: string | null
  icono?: string | null
  orden?: number
}

export interface CategoriaSubevento {
  id: number
  nombre: string
  slug: string
  color?: string | null
  icono?: string | null
  orden?: number
  estado?: boolean
  maneja_puntos?: boolean
  maneja_fecha_inicio?: boolean
  maneja_fecha_fin?: boolean
}

export interface CategoriaSubeventoPayload {
  nombre: string
  slug?: string | null
  color?: string | null
  icono?: string | null
  orden?: number
  estado?: boolean
  maneja_puntos?: boolean
  maneja_fecha_inicio?: boolean
  maneja_fecha_fin?: boolean
}

export interface CriterioEvaluacion {
  id: number
  nombre: string
  descripcion?: string | null
  estado?: boolean
  orden?: number
}

export interface CriterioEvaluacionPayload {
  nombre: string
  descripcion?: string | null
  estado?: boolean
  orden?: number
}

export interface EventoCriterioAsignado {
  id: number
  nombre: string
  descripcion?: string | null
  puntos: number
  orden: number
}

export interface EventoEvidenciaItem {
  id: number
  evento_id: number
  organizacion_id?: number | null
  persona_id?: number | null
  inscripcion_id: number
  tipo: 'link' | 'pdf' | 'imagen' | 'audio' | 'video' | string
  titulo?: string | null
  descripcion?: string | null
  url?: string | null
  file_id?: number | null
  file?: { id: number; name: string; path: string; mime_type?: string | null; size?: number } | null
  estado: string
  created_at?: string | null
}

export interface ParticipationCalificacionAporte {
  etiqueta: string
  puntaje_obtenido: number
  observaciones?: string | null
  updated_at?: string | null
}

export interface ParticipationCalificacion {
  id?: number | null
  puntaje_obtenido: number
  observaciones?: string | null
  calificado_por?: number | null
  updated_at?: string | null
  es_agregado?: boolean
  es_promedio?: boolean
  jueces_count?: number
  aportes?: ParticipationCalificacionAporte[]
  observaciones_director?: string | null
  observaciones_director_updated_at?: string | null
  detalles?: Array<{ criterio_evaluacion_id: number; puntos: number }>
}

export interface ParticipationNode {
  id: number
  name: string
  descripcion?: string | null
  reglas?: string | null
  estado?: string | null
  image_url?: string | null
  evento_padre_id?: number | null
  es_calificable: boolean
  puntaje_maximo?: number | null
  puntaje_desde_hijos?: boolean
  requiere_evidencia: boolean
  tipos_evidencia: string[]
  maneja_fecha_fin?: boolean
  maneja_penalizaciones?: boolean
  puntos_penalizacion?: number | null
  reglas_penalizacion?: string | null
  tiempo_estimado_minutos?: number | null
  participantes_min?: number | null
  participantes_max?: number | null
  es_conjunto?: boolean
  nivel_conjunto?: string | null
  requiere_pago?: boolean
  precio?: number | null
  starts_at?: string | null
  ends_at?: string | null
  tipo_evento?: Pick<TipoEvento, 'id' | 'nombre' | 'slug' | 'color' | 'icono'> | null
  categoria_subevento?: Pick<CategoriaSubevento, 'id' | 'nombre' | 'slug' | 'color' | 'icono'> | null
  jueces?: Array<{ id: number; name: string; email?: string | null }>
  supervisores?: Array<{ id: number; name: string; email?: string | null }>
  criterios: EventoCriterioAsignado[]
  calificacion?: ParticipationCalificacion | null
  evidencias: EventoEvidenciaItem[]
  is_root: boolean
  hijos: ParticipationNode[]
}

export interface EventParticipation {
  evento: ParticipationNode
  inscripcion: {
    id: number
    estado: string
    tipo: string
    organizacion_id: number
    created_at?: string | null
  } | null
  organizacion: { id: number; nombre: string; logo_url?: string | null }
  progreso: {
    puntos_inscripcion: number
    puntos_inscripcion_max: number
    puntos_subeventos: number
    puntos_subeventos_max: number
    puntos_total: number
    puntos_total_max: number
    observacion_inscripcion?: string | null
  }
}

export interface JudgeCalificacionDetalle {
  criterio_evaluacion_id: number
  puntos: number
}

export interface JudgeCalificacion {
  id: number
  evento_id: number
  organizacion_id: number | null
  puntaje_obtenido: number
  observaciones?: string | null
  calificado_por?: number | null
  observaciones_director?: string | null
  observaciones_director_updated_at?: string | null
  detalles: JudgeCalificacionDetalle[]
  updated_at?: string | null
}

export interface JudgeClub {
  organizacion_id: number
  nombre: string
  logo_url?: string | null
  estado: 'pendiente' | 'evaluado' | string
  puntaje_obtenido?: number | null
  puntaje_maximo?: number | null
  porcentaje?: number | null
  evidencias_count: number
  evidencias_en_actividad?: number
  evidencias: EventoEvidenciaItem[]
  calificacion?: JudgeCalificacion | null
  observaciones_director?: string | null
  observaciones_director_updated_at?: string | null
  /** Resumen global (fase club). */
  eventos_pendientes?: number
  evidencias_pendientes?: number
  eventos_evaluados?: number
}

export interface JudgeClubResumen {
  organizacion_id: number
  nombre: string
  logo_url?: string | null
  estado: 'pendiente' | 'evaluado' | string
  eventos_pendientes: number
  evidencias_pendientes: number
  eventos_evaluados: number
  evidencias_count: number
}

export interface JudgeSubeventoHijo {
  id: number
  name: string
  image_url?: string | null
  puntaje_maximo?: number | null
  requiere_evidencia?: boolean
  es_calificable?: boolean
  puntaje_desde_hijos?: boolean
  puede_calificar?: boolean
}

export type JudgeDetailTab =
  | 'info'
  | 'subeventos'
  | 'descripcion'
  | 'reglas'
  | 'puntaje'
  | 'categoria'
  | 'calificacion'
  | 'resultado'
  | 'observaciones'

export interface JudgePersonRef {
  id: number
  name: string
  email?: string | null
}

export interface JudgeSubevento {
  id: number
  name: string
  descripcion?: string | null
  reglas?: string | null
  estado?: string | null
  image_url?: string | null
  starts_at?: string | null
  ends_at?: string | null
  puntaje_maximo?: number | null
  requiere_evidencia?: boolean
  tipos_evidencia?: string[]
  es_calificable?: boolean
  puntaje_desde_hijos?: boolean
  /** El juez actual puede calificar este nodo (asignado + calificable). */
  puede_calificar?: boolean
  /** El juez está asignado directamente a este nodo. */
  asignado?: boolean
  tiempo_estimado_minutos?: number | null
  participantes_min?: number | null
  participantes_max?: number | null
  es_conjunto?: boolean
  nivel_conjunto?: string | null
  maneja_fecha_fin?: boolean
  maneja_penalizaciones?: boolean
  puntos_penalizacion?: number | null
  reglas_penalizacion?: string | null
  requiere_pago?: boolean
  precio?: number | null
  tipo_evento?: Pick<TipoEvento, 'id' | 'nombre' | 'slug' | 'color' | 'icono'> | null
  categoria_subevento?: Pick<CategoriaSubevento, 'id' | 'nombre' | 'slug' | 'color' | 'icono'> | null
  jueces?: JudgePersonRef[]
  jueces_heredados?: boolean
  supervisores?: JudgePersonRef[]
  supervisores_heredados?: boolean
  criterios: EventoCriterioAsignado[]
  hijos?: JudgeSubeventoHijo[]
}

export type JudgeNodeStatus = 'pendiente' | 'evaluado' | 'neutral'

export interface JudgeTreeNode {
  id: number
  name: string
  image_url?: string | null
  puntaje_maximo?: number | null
  es_calificable?: boolean
  requiere_evidencia?: boolean
  puede_calificar?: boolean
  asignado?: boolean
  icono?: string | null
  color?: string | null
  categoria?: string | null
  tipo?: string | null
  hijos?: JudgeTreeNode[]
}

export interface JudgeBoard {
  evento: { id: number; name: string; image_url?: string | null; estado?: string }
  subeventos: Array<{
    id: number
    name: string
    label?: string
    depth?: number
    puntaje_maximo?: number | null
    requiere_evidencia?: boolean
    es_calificable?: boolean
    puntaje_desde_hijos?: boolean
    tiene_hijos_calificables?: boolean
    actividad_ids?: number[]
    puede_calificar?: boolean
    asignado?: boolean
  }>
  arbol?: JudgeTreeNode[]
  subevento: JudgeSubevento | null
  actividad: JudgeSubevento | null
  clubes: JudgeClub[]
  /** Clubes del alcance del juez con totales de pendientes (fase 1). */
  clubes_resumen?: JudgeClubResumen[]
  progreso: { evaluados: number; pendientes: number; total: number; pct: number }
  /** organizacion_id => { evento_id => cantidad de evidencias pendientes } */
  pendientes?: Record<string, Record<string, number>>
  /** organizacion_id => { evento_id => puntaje obtenido } */
  evaluados?: Record<string, Record<string, number>>
  /** organizacion_id => { evento_id => total evidencias cargadas } */
  evidencias?: Record<string, Record<string, number>>
}

export type JudgeEvaluacionEstado = 'completado' | 'pendiente' | 'sin_evidencia'

export interface JudgeEvaluacionClub {
  organizacion_id: number
  club_id?: number | null
  nombre: string
  logo_url?: string | null
  distrito: string
  iglesia?: string | null
  estado: JudgeEvaluacionEstado
  eventos_pendientes: number
  eventos_evaluados: number
  evidencias_count: number
  puntaje_otorgado: number
  puntaje_maximo?: number | null
  porcentaje?: number | null
  subevento_evaluado?: string | null
  updated_at?: string | null
  inscrito: boolean
}

export interface JudgeEvaluacionDesgloseItem {
  evento_id: number
  name: string
  image_url?: string | null
  puntaje_obtenido?: number | null
  puntaje_maximo?: number | null
  porcentaje?: number | null
  estado: JudgeEvaluacionEstado
  observaciones?: string | null
  observaciones_director?: string | null
  updated_at?: string | null
  evidencias: EventoEvidenciaItem[]
  calificacion?: JudgeCalificacion | null
}

export interface JudgeEvaluacionDetalle {
  organizacion_id: number
  nombre: string
  logo_url?: string | null
  distrito: string
  iglesia?: string | null
  puntaje_otorgado: number
  puntaje_maximo?: number | null
  porcentaje?: number | null
  evaluado_por?: string | null
  desglose: JudgeEvaluacionDesgloseItem[]
}

export interface JudgeEvaluaciones {
  evento: { id: number; name: string; image_url?: string | null; estado?: string }
  filtros: {
    distritos: string[]
    subeventos: Array<{ id: number; name: string }>
  }
  totales: {
    evaluados: number
    completos: number
    pendientes: number
    sin_evidencia: number
    promedio_pct?: number | null
    total: number
  }
  clubes: JudgeEvaluacionClub[]
  detalle: JudgeEvaluacionDetalle | null
}

export type EventStandingsSort = 'puesto' | 'puntaje' | 'nombre' | 'distrito'

export interface EventStandingRow {
  puesto: number
  organizacion_id: number
  club_id?: number | null
  nombre: string
  logo_url?: string | null
  distrito: string
  distrito_organizacion_id?: number | null
  iglesia?: string | null
  puntos_inscripcion?: number | null
  puntos_subeventos: number
  puntos_total: number
  puntos_maximo?: number | null
  porcentaje?: number | null
  inscrito: boolean
}

export interface EventStandings {
  evento: { id: number; name: string; estado?: string; image_url?: string | null }
  alcance: {
    evento_id: number
    nombre: string
    es_root: boolean
    puntaje_desde_hijos?: boolean
    puntaje_maximo?: number | null
  }
  subeventos: Array<{
    id: number
    name: string
    label?: string
    es_root?: boolean
    evento_padre_id?: number | null
    puntaje_maximo?: number | null
    puntaje_desde_hijos?: boolean
  }>
  sort: EventStandingsSort
  totales: {
    clubes: number
    con_puntaje: number
    puntaje_maximo_alcance?: number | null
  }
  standings: EventStandingRow[]
}

export interface EventStandingsTreeNode {
  id: number
  name: string
  evento_padre_id: number | null
  es_root: boolean
  es_calificable: boolean
  puntaje_desde_hijos: boolean
  puntaje_maximo: number | null
  puntaje_maximo_rollup: number
  has_children: boolean
  children: EventStandingsTreeNode[]
}

export interface EventStandingTreeRow {
  puesto: number
  organizacion_id: number
  club_id?: number | null
  nombre: string
  logo_url?: string | null
  distrito: string
  distrito_organizacion_id?: number | null
  iglesia?: string | null
  puntos_inscripcion?: number | null
  puntos_total: number
  puntos_maximo?: number | null
  porcentaje?: number | null
  inscrito: boolean
  /** evento_id (string) => puntaje rollup en ese nodo */
  scores: Record<string, number>
}

export interface EventStandingsTree {
  evento: { id: number; name: string; estado?: string; image_url?: string | null }
  tree: EventStandingsTreeNode
  sort: EventStandingsSort
  totales: {
    clubes: number
    con_puntaje: number
    puntaje_maximo_alcance?: number | null
  }
  standings: EventStandingTreeRow[]
}

export interface EventoDescuentoDirectiva {
  codigo: string
  nombre: string
  porcentaje: number
}

export type EventoVisibilidad = 'publico' | 'privado' | 'organizacion'

export interface ClubEvent {
  id: number
  name: string
  descripcion?: string | null
  reglas?: string | null
  lugar?: string | null
  latitud?: number | null
  longitud?: number | null
  image_url: string | null
  starts_at: string
  ends_at: string
  is_active: boolean
  estado: 'borrador' | 'publicado' | 'cerrado' | 'cancelado' | string
  visibilidad: EventoVisibilidad
  evento_padre_id?: number | null
  orden?: number
  organizacion_id?: number | null
  tipo_evento_id?: number | null
  tipo_evento?: Pick<TipoEvento, 'id' | 'nombre' | 'slug' | 'color' | 'icono'> | null
  categoria_subevento_id?: number | null
  categoria_subevento?: Pick<
    CategoriaSubevento,
    | 'id'
    | 'nombre'
    | 'slug'
    | 'color'
    | 'icono'
    | 'maneja_puntos'
    | 'maneja_fecha_inicio'
    | 'maneja_fecha_fin'
  > | null
  organizacion?: EventOrganizacionRef | null
  organizacion_ids: number[]
  organizaciones: EventOrganizacionRef[]
  tipo_organizacion_ids: number[]
  tipos_organizacion: EventTipoOrganizacionRef[]
  es_en_sitio: boolean
  es_calificable: boolean
  puntaje_maximo?: number | null
  puntaje_desde_hijos?: boolean
  tiempo_estimado_minutos?: number | null
  participantes_min?: number | null
  participantes_max?: number | null
  equipos_org_min?: number | null
  equipos_org_max?: number | null
  es_conjunto?: boolean
  nivel_conjunto?: 'club' | 'iglesia' | 'distrito' | 'asociacion' | string | null
  maneja_fecha_fin?: boolean
  maneja_penalizaciones?: boolean
  puntos_penalizacion?: number | null
  reglas_penalizacion?: string | null
  requiere_evidencia?: boolean
  tipos_evidencia?: Array<'link' | 'pdf' | 'imagen' | 'audio' | 'video' | string>
  requiere_pago: boolean
  precio?: number | null
  precio_fuera_tiempo?: number | null
  precio_acompanante?: number | null
  precio_acompanante_fuera_tiempo?: number | null
  precio_acompanante_menor?: number | null
  precio_acompanante_menor_fuera_tiempo?: number | null
  precio_directiva?: number | null
  precio_directiva_fuera_tiempo?: number | null
  descuentos_directiva?: EventoDescuentoDirectiva[]
  fecha_limite_pago?: string | null
  metodo_pago?: string | null
  requiere_seguro?: boolean
  tipo_seguro_id?: number | null
  seguro_valor?: number | null
  seguro_fecha_inicio?: string | null
  seguro_fecha_fin?: string | null
  inscripcion_estado?: EventoInscripcionEstado | string | null
  inscripcion_id?: number | null
  puede_elegir_lote?: boolean
  requiere_alojamiento?: boolean
  puede_elegir_cama?: boolean
  alojamiento_asignado?: boolean
  cupo_minimo?: number | null
  cupo_maximo?: number | null
  cupo_ilimitado: boolean
  cupo_max_organizacion?: number | null
  cupo_max_club?: number | null
  cupo_max_iglesia?: number | null
  permite_inscripcion_individual: boolean
  permite_inscripcion_organizacion: boolean
  permite_inscripcion_club: boolean
  permite_inscripcion_iglesia: boolean
  fecha_limite_inscripcion?: string | null
  puntos_inscripcion_a_tiempo?: number | null
  puntos_inscripcion_fuera_tiempo?: number | null
  criterios?: EventoCriterioAsignado[]
  inscrito?: boolean
  created_by: number | null
  juez_ids?: number[]
  jueces?: Array<{ id: number; name: string; email?: string | null }>
  jueces_efectivos?: Array<{ id: number; name: string; email?: string | null }>
  jueces_heredados?: boolean
  supervisor_ids?: number[]
  supervisores?: Array<{ id: number; name: string; email?: string | null }>
  supervisores_efectivos?: Array<{ id: number; name: string; email?: string | null }>
  supervisores_heredados?: boolean
  padre?: { id: number; name: string } | null
  hijos_count?: number
  hijos?: ClubEvent[] | null
  /** Juez: nodo asignado al usuario actual */
  asignado_a_mi?: boolean
  visible_para_juez?: boolean
  progreso_juez?: {
    calificados: number
    pendientes: number
    total: number
  } | null
  /** Director: evidencia de su club en este nodo */
  evidencia_enviada?: boolean | null
  progreso_evidencia?: {
    con_evidencia: number
    sin_evidencia: number
    total: number
  } | null
  created_at?: string
  updated_at?: string
}

export interface EventListParams {
  page?: number
  per_page?: number
  search?: string
  is_active?: boolean | null
  estado?: string | null
  tipo_evento_id?: number | null
  evento_padre_id?: number | null
  solo_raiz?: boolean
  incluir_hijos?: boolean
}

export interface EventFormPayload {
  name: string
  descripcion?: string | null
  lugar?: string | null
  latitud?: number | null
  longitud?: number | null
  starts_at: string
  ends_at: string
  is_active?: boolean
  estado?: string
  visibilidad?: EventoVisibilidad
  evento_padre_id?: number | null
  orden?: number | null
  organizacion_id?: number | null
  tipo_evento_id?: number | null
  categoria_subevento_id?: number | null
  juez_ids?: number[]
  supervisor_ids?: number[]
  organizacion_ids?: number[]
  tipo_organizacion_ids?: number[]
  es_en_sitio?: boolean
  es_calificable?: boolean
  puntaje_maximo?: number | null
  puntaje_desde_hijos?: boolean
  tiempo_estimado_minutos?: number | null
  participantes_min?: number | null
  participantes_max?: number | null
  equipos_org_min?: number | null
  equipos_org_max?: number | null
  es_conjunto?: boolean
  nivel_conjunto?: 'club' | 'iglesia' | 'distrito' | 'asociacion' | string | null
  maneja_fecha_fin?: boolean
  maneja_penalizaciones?: boolean
  puntos_penalizacion?: number | null
  reglas_penalizacion?: string | null
  requiere_evidencia?: boolean
  tipos_evidencia?: Array<'link' | 'pdf' | 'imagen' | 'audio' | 'video' | string> | null
  reglas?: string | null
  requiere_pago?: boolean
  precio?: number | null
  precio_fuera_tiempo?: number | null
  precio_acompanante?: number | null
  precio_acompanante_fuera_tiempo?: number | null
  precio_acompanante_menor?: number | null
  precio_acompanante_menor_fuera_tiempo?: number | null
  precio_directiva?: number | null
  precio_directiva_fuera_tiempo?: number | null
  descuentos_directiva?: EventoDescuentoDirectiva[] | null
  fecha_limite_pago?: string | null
  metodo_pago?: string | null
  requiere_seguro?: boolean
  tipo_seguro_id?: number | null
  seguro_valor?: number | null
  seguro_fecha_inicio?: string | null
  seguro_fecha_fin?: string | null
  cupo_minimo?: number | null
  cupo_maximo?: number | null
  cupo_ilimitado?: boolean
  cupo_max_organizacion?: number | null
  cupo_max_club?: number | null
  cupo_max_iglesia?: number | null
  permite_inscripcion_individual?: boolean
  permite_inscripcion_organizacion?: boolean
  permite_inscripcion_club?: boolean
  permite_inscripcion_iglesia?: boolean
  fecha_limite_inscripcion?: string | null
  puntos_inscripcion_a_tiempo?: number | null
  puntos_inscripcion_fuera_tiempo?: number | null
  criterios?: Array<{ id?: number; criterio_evaluacion_id?: number; puntos: number; orden?: number }>
  image_url?: string | null
}

export interface TipoSeguro {
  id: number
  nombre: string
  tipo: string
  descripcion?: string | null
  duracion_dias?: number | null
  requiere_evento?: boolean
  activo?: boolean
}

export interface SeguroConsultaResultado {
  persona_id: number
  nombre: string
  tipo_identificacion?: string | null
  identificacion?: string | null
  vigente: boolean
  estado: 'vigente' | 'no_vigente' | 'sin_seguro'
  dias_restantes: number
  seguro: {
    id: number
    estado: string
    tipo?: string | null
    fecha_inicio?: string | null
    fecha_fin?: string | null
    evento?: string | null
  } | null
}

export interface ProductoServicio {
  id: number
  nombre: string
  tipo: string
  descripcion?: string | null
  precio?: number | null
  unidad?: string | null
  activo?: boolean
}

export interface ProductoServicioPayload {
  nombre: string
  tipo: string
  descripcion?: string | null
  precio?: number | null
  unidad?: string | null
  activo?: boolean
}

export interface EventoProductoServicioOferta {
  id?: number
  evento_id?: number
  producto_servicio_id: number
  precio: number
  activo: boolean
  producto?: ProductoServicio | null
}

export interface EventoProductoServicioSyncPayload {
  items: Array<{
    producto_servicio_id: number
    precio: number
    activo?: boolean
  }>
}

export interface RosterCoberturaMiembro {
  id: number
  nombre: string
  identificacion?: string | null
  fecha_nacimiento?: string | null
  cargo_directiva?: 'director' | 'subdirector' | 'secretario' | 'tesorero' | null
  cobertura: {
    cubierta: boolean
    estado: 'ASEGURADO' | 'SIN_SEGURO' | string
    motivo?: string | null
  }
}

export interface RosterCobertura {
  evento: {
    id: number
    name: string
    requiere_pago: boolean
    precio?: number | null
    precio_fuera_tiempo?: number | null
    precio_acompanante?: number | null
    precio_acompanante_fuera_tiempo?: number | null
    precio_acompanante_menor?: number | null
    precio_acompanante_menor_fuera_tiempo?: number | null
    precio_directiva?: number | null
    precio_directiva_fuera_tiempo?: number | null
    fecha_limite_inscripcion?: string | null
    inscripcion_fuera_tiempo: boolean
    descuentos_directiva?: EventoDescuentoDirectiva[]
    requiere_seguro: boolean
    seguro_valor?: number | null
  }
  miembros: RosterCoberturaMiembro[]
}

export interface EventoAcompanantePersona {
  id: number
  tipo_identificacion: string
  identificacion: string
  nombre1: string
  nombre2?: string | null
  apellido1: string
  apellido2?: string | null
  fecha_nacimiento?: string | null
  sexo?: string | null
  telefono?: string | null
  correo?: string | null
  full_name: string
  cubierta?: boolean
}

export interface EventoAcompanantePersonaPayload {
  tipo_identificacion: string
  identificacion: string
  nombre1: string
  nombre2?: string | null
  apellido1: string
  apellido2?: string | null
  fecha_nacimiento?: string | null
  sexo?: string | null
  telefono?: string | null
  correo?: string | null
}

export type EventoInscripcionEstado =
  | 'pendiente_revision'
  | 'en_revision'
  | 'aprobada'
  | 'no_aprobada'
  | string

export type EventoComprobanteEstado = 'pendiente' | 'aprobado' | 'rechazado' | string

export interface EventoInscripcionComprobante {
  id: number
  inscripcion_id: number
  movimiento_id?: number | null
  movimiento_numero?: number | null
  valor: number
  estado: EventoComprobanteEstado
  observacion?: string | null
  archivo_url?: string | null
  archivo_nombre?: string | null
  mime_type?: string | null
  comentarios?: EventoInscripcionComprobanteComentario[]
  revisado_at?: string | null
  created_at?: string | null
}

export interface EventoInscripcionComprobanteComentario {
  id: number
  comprobante_id: number
  autor_tipo: 'director' | 'supervisor' | string
  autor_nombre?: string | null
  mensaje: string
  created_at?: string | null
}

export interface EventoInscripcionMovimientoServicio {
  clave: string
  evento_producto_servicio_id: number
  producto?: string | null
  tipo?: string | null
  cantidad: number
  precio_unitario: number
  valor_total: number
  participante_ref?: string
  participante_nombre?: string | null
}

export interface EventoInscripcionMovimientoParticipante {
  ref: string
  persona_id?: number | null
  nombre?: string | null
  identificacion?: string | null
  tipo: string
  cargo_directiva?: 'director' | 'subdirector' | 'secretario' | 'tesorero' | null
  parentesco?: string | null
  descuento_nombre?: string | null
  descuento_porcentaje: number
  valor_base: number
  valor_descuento: number
  valor_inscripcion: number
  valor_seguro: number
  servicios?: EventoInscripcionMovimientoServicio[]
}

export interface EventoInscripcionMovimiento {
  id: number
  numero: number
  tipo: 'inicial' | 'modificacion' | string
  total_anterior: number
  total_nuevo: number
  valor_diferencia: number
  snapshot: {
    total: number
    participantes: EventoInscripcionMovimientoParticipante[]
  }
  cambios: {
    participantes_agregados: EventoInscripcionMovimientoParticipante[]
    participantes_retirados: EventoInscripcionMovimientoParticipante[]
    participantes_modificados: Array<{
      ref: string
      nombre?: string | null
      anterior: EventoInscripcionMovimientoParticipante
      nuevo: EventoInscripcionMovimientoParticipante
    }>
    servicios_agregados: EventoInscripcionMovimientoServicio[]
    servicios_retirados: EventoInscripcionMovimientoServicio[]
    servicios_modificados: Array<{
      clave: string
      anterior: EventoInscripcionMovimientoServicio
      nuevo: EventoInscripcionMovimientoServicio
    }>
  }
  total_consignado: number
  total_aprobado: number
  saldo_por_soportar: number
  comprobantes?: EventoInscripcionComprobante[]
  created_at?: string | null
}

export interface EventoInscripcionPersona {
  id: number
  persona_id?: number | null
  referencia_cliente?: string | null
  tipo?: 'miembro' | 'directiva' | 'acompanante' | 'acompanante_menor' | 'visitante_pasadia' | string
  cargo_directiva?: 'director' | 'subdirector' | 'secretario' | 'tesorero' | null
  identificacion?: string | null
  fecha_nacimiento?: string | null
  parentesco?: string | null
  descuento_codigo?: string | null
  descuento_nombre?: string | null
  descuento_porcentaje?: number
  valor_base?: number | null
  valor_descuento?: number | null
  valor_inscripcion?: number | null
  valor_seguro?: number | null
  estado?: string | null
  nombre?: string | null
  reservas?: EventoInscripcionReserva[]
  asignacion_cama?: AsignacionCama | null
}

export interface EventoInscripcionReserva {
  id: number
  evento_producto_servicio_id: number
  producto?: string | null
  tipo?: string | null
  precio_unitario: number
  cantidad: number
  valor_total: number
  fecha_inicio?: string | null
  fecha_fin?: string | null
  fecha?: string | null
}

export interface EventoInscripcion {
  id: number
  evento_id: number
  tipo: string
  organizacion_id: number
  organizacion?: { id: number; nombre: string; logo_url?: string | null } | null
  estado: EventoInscripcionEstado
  total_declarado?: number | null
  total_consignado?: number
  total_consignado_aprobado?: number
  saldo_por_soportar?: number
  observacion_revision?: string | null
  revisado_at?: string | null
  personas?: EventoInscripcionPersona[]
  comprobantes?: EventoInscripcionComprobante[]
  movimientos?: EventoInscripcionMovimiento[]
  seguros_count?: number
  reservas_count?: number
  alojamiento?: {
    asignadas: number
    participantes: number
    capacidad_reservada?: number
  } | null
  created_at?: string | null
}

export interface EventoInscripcionEnrollPayload {
  persona_ids?: number[]
  participantes: Array<{
    ref: string
    persona_id?: number | null
    tipo: 'miembro' | 'directiva' | 'acompanante' | 'acompanante_menor' | 'visitante_pasadia'
    cargo_directiva?: 'director' | 'subdirector' | 'secretario' | 'tesorero' | null
    nombre?: string | null
    identificacion?: string | null
    fecha_nacimiento?: string | null
    parentesco?: string | null
    descuento_codigo?: string | null
  }>
  reservas?: Array<{
    evento_producto_servicio_id: number
    participante_ref: string
    fecha_inicio?: string | null
    fecha_fin?: string | null
    fecha?: string | null
    cantidad?: number | null
  }>
}

export interface EventoInscripcionRevisionPayload {
  estado: EventoInscripcionEstado
  observacion_revision?: string | null
}

export interface EventoComprobanteRevisionPayload {
  estado: EventoComprobanteEstado
  observacion?: string | null
}
