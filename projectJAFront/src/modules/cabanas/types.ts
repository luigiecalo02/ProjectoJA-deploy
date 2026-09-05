export type CabanaEstado = 'activa' | 'inactiva'
export type GeneroAlojamiento = 'M' | 'F' | 'MIXTO'
export type EstadoCama = 'disponible' | 'parcial' | 'completa' | 'seleccionada' | 'bloqueada'
export type TipoCama = 'sencilla' | 'doble' | 'multiple' | 'camarote'
export type NivelCamarote = 'abajo' | 'arriba'
export type ElegibilidadCodigo = 'ok' | 'sin_persona' | 'sin_sexo' | 'sin_inscripcion' | 'sin_reserva' | 'sin_permiso'

export interface CabanaBed {
  id: number
  cuarto_id?: number
  codigo: string
  nombre?: string | null
  x: number
  y: number
  ancho?: number
  alto?: number
  rotacion?: number
  genero?: GeneroAlojamiento | null
  capacidad: number
  tipo?: TipoCama
  nivel_camarote?: NivelCamarote | null
  grupo_camarote?: string | null
  precio_sugerido?: number | null
  precio?: number | null
  ocupacion?: number
  ocupadas?: number
  estado?: string
  bloqueada?: boolean
  asignada_a_mi?: boolean
  ocupantes?: Array<{ id: number; nombre: string }>
}

export type RoomShape = 'rect' | 'circle' | 'polygon'

export interface CabanaPoint {
  x: number
  y: number
}

export interface CabanaDoor {
  id: number
  x: number
  y: number
  ancho: number
  rotacion?: number
}

export interface CabanaRoom {
  id: number
  piso_id?: number
  nombre: string
  codigo?: string | null
  x: number
  y: number
  ancho: number
  alto: number
  forma?: RoomShape
  vertices?: CabanaPoint[]
  puertas?: CabanaDoor[]
  genero: GeneroAlojamiento
  capacidad: number
  ocupacion?: number
  ocupadas?: number
  estado?: EstadoCama
  camas: CabanaBed[]
}

export interface CabanaFloor {
  id: number
  cabana_id?: number
  nombre: string
  orden: number
  ancho: number
  alto: number
  cuartos: CabanaRoom[]
}

export interface Cabana {
  id: number
  lugar_id?: number | null
  lugar?: { id: number; nombre: string } | null
  nombre: string
  descripcion?: string | null
  image_url?: string | null
  estado: CabanaEstado
  pisos_count?: number
  cuartos_count?: number
  camas_count?: number
  capacidad_total?: number
  pisos?: CabanaFloor[]
  created_at?: string
  updated_at?: string
}

export interface CabanaPayload {
  lugar_id: number
  nombre: string
  descripcion?: string | null
  estado?: CabanaEstado
}

export interface CabanaLayoutPayload {
  pisos: Array<{
    id?: number
    nombre: string
    orden: number
    ancho: number
    alto: number
    cuartos: Array<{
      id?: number
      nombre: string
      codigo?: string | null
      x: number
      y: number
      ancho: number
      alto: number
      genero: GeneroAlojamiento
      capacidad: number
      forma?: RoomShape
      vertices?: CabanaPoint[]
      puertas?: CabanaDoor[]
      camas: Array<{
        id?: number
        codigo: string
        nombre?: string | null
        x: number
        y: number
        ancho?: number
        alto?: number
        rotacion?: number
        genero?: GeneroAlojamiento | null
        capacidad: number
        tipo?: TipoCama
        nivel_camarote?: NivelCamarote | null
        grupo_camarote?: string | null
        precio_sugerido?: number | null
        estado?: string
      }>
    }>
  }>
}

export interface EventoCabana {
  id: number
  evento_id: number
  cabana_id: number
  orden: number
  nombre: string
  descripcion?: string | null
  image_url?: string | null
  estado: string
  pisos: CabanaFloor[]
  ocupacion?: number
  ocupadas?: number
  capacidad?: number
  capacidad_total?: number
  cabana: Pick<Cabana, 'id' | 'nombre' | 'image_url' | 'pisos' | 'capacidad_total'>
}

export interface EventoCabanaPayload {
  cabana_id: number
  orden: number
}

export interface AsignacionDesplazada {
  id: number
  inscripcion_persona_id: number
  nombre: string
  cabana?: string | null
  piso?: string | null
  cuarto?: string | null
  cama?: string | null
  precio?: number | null
}

export interface AsignacionCama {
  id: number
  evento_cabana_cama_id?: number
  evento_cama_id?: number
  cama?: Pick<CabanaBed, 'id' | 'codigo' | 'nombre'> | null
  cuarto?: Pick<CabanaRoom, 'id' | 'nombre' | 'genero'> | null
  piso?: Pick<CabanaFloor, 'id' | 'nombre'> | null
  cabana?: Pick<Cabana, 'id' | 'nombre'> | null
}

export interface AlojamientoCupoUser {
  id: number
  name: string
  email: string
}

export interface AlojamientoCupoAsignacion {
  id: number
  inscripcion_persona_id: number
  nombre?: string | null
  cama?: Pick<CabanaBed, 'id' | 'codigo' | 'nombre'> | null
}

export interface AlojamientoCupo {
  id: number
  user_id: number
  cupos: number
  usados: number
  restantes: number
  estado: 'abierto' | 'cerrado'
  cerrado_at?: string | null
  user?: AlojamientoCupoUser | null
  asignaciones?: AlojamientoCupoAsignacion[]
}

export interface AlojamientoCupoPool {
  items: AlojamientoCupo[]
  capacidad: number
  ocupadas: number
  reservados: number
  libres: number
}

export interface AlojamientoCandidato {
  id: number
  nombre: string
  identificacion?: string | null
  sexo?: string | null
}

export interface AlojamientoEvento {
  evento: {
    id: number
    name: string
    puede_elegir_cama?: boolean
  }
  cabanas: EventoCabana[]
  asignacion: AsignacionCama | null
  ocupacion?: number
  ocupadas: number
  capacidad: number
  reservados?: number
  libres?: number
  puede_seleccionar: boolean
  elegibilidad_codigo?: ElegibilidadCodigo | string
  elegibilidad_motivo?: string | null
  cupo?: AlojamientoCupo | null
}
