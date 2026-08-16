export type CabanaEstado = 'activa' | 'inactiva'
export type GeneroAlojamiento = 'M' | 'F' | 'MIXTO'
export type EstadoCama = 'disponible' | 'parcial' | 'completa' | 'seleccionada' | 'bloqueada'
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
  ocupacion?: number
  ocupadas?: number
  estado?: string
  bloqueada?: boolean
  asignada_a_mi?: boolean
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
  nombre: string
  descripcion?: string | null
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
  estado: string
  pisos: CabanaFloor[]
  ocupacion?: number
  ocupadas?: number
  capacidad?: number
  capacidad_total?: number
  cabana: Pick<Cabana, 'id' | 'nombre' | 'pisos' | 'capacidad_total'>
}

export interface EventoCabanaPayload {
  cabana_id: number
  orden: number
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
  puede_seleccionar: boolean
  elegibilidad_codigo?: ElegibilidadCodigo | string
  elegibilidad_motivo?: string | null
}
