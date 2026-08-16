export interface TipoOrganizacion {
  id: number
  tipo_organizacion_padre_id?: number | null
  nombre: string
  descripcion?: string | null
  estado?: boolean
}

export interface UbicacionRef {
  id: number
  nombre: string
}

export interface PaisOption {
  id: number
  nombre: string
}

export interface DepartamentoOption {
  id: number
  pais_id: number
  nombre: string
  pais_nombre?: string | null
}

export interface CiudadOption {
  id: number
  departamento_id: number
  nombre: string
  departamento_nombre?: string | null
}

export interface OrganizacionParentRef {
  id: number
  nombre: string
}

export interface Organizacion {
  id: number
  organizacion_padre_id: number | null
  tipo_organizacion_id: number
  pais_id: number | null
  departamento_id: number | null
  ciudad_id: number | null
  nombre: string
  codigo: string | null
  direccion: string | null
  telefono: string | null
  correo: string | null
  estado: boolean
  fecha_creacion?: string | null
  fecha_actualizacion?: string | null
  tipo?: { id: number; nombre: string } | null
  padre?: OrganizacionParentRef | null
  pais?: UbicacionRef | null
  departamento?: UbicacionRef | null
  ciudad?: UbicacionRef | null
  departamentos?: DepartamentoOption[]
  hijas?: OrganizacionParentRef[]
}

export interface OrganizacionTreeNode {
  id: number
  nombre: string
  codigo: string | null
  tipo_organizacion_id: number
  tipo_nombre?: string | null
  organizacion_padre_id: number | null
  estado: boolean
  pais_nombre?: string | null
  departamento_nombre?: string | null
  ciudad_nombre?: string | null
  children: OrganizacionTreeNode[]
}

export interface OrganizacionParentOption {
  id: number
  nombre: string
  codigo: string | null
  tipo_organizacion_id: number
  tipo_nombre?: string | null
  organizacion_padre_id: number | null
  pais_id?: number | null
  departamento_id?: number | null
  ciudad_id?: number | null
  pais_nombre?: string | null
  departamento_nombre?: string | null
  ciudad_nombre?: string | null
  departamentos?: DepartamentoOption[]
}

export interface OrganizacionFormPayload {
  organizacion_padre_id?: number | null
  tipo_organizacion_id: number
  pais_id?: number | null
  pais_nombre?: string | null
  departamento_id?: number | null
  departamento_nombre?: string | null
  departamento_ids?: number[]
  departamento_nombres?: string[]
  ciudad_id?: number | null
  ciudad_nombre?: string | null
  nombre: string
  direccion?: string | null
  telefono?: string | null
  correo?: string | null
  estado?: boolean
}

/** IDs alineados con OrganizacionCatalogSeeder */
export const TIPO_UNION = 1
export const TIPO_ASOCIACION = 2
export const TIPO_DISTRITO = 3
export const TIPO_IGLESIA = 4
export const TIPO_CLUB = 5
/** IDs actuales en BD (pueden variar; el filtro de padre usa tipo_organizacion_padre_id). */
export const TIPO_AVENTUREROS = 6
export const TIPO_CONQUISTADORES = 7
export const TIPO_GUIAS_MAYORES = 8

export const TIPOS_HIJO_CLUB = [TIPO_AVENTUREROS, TIPO_CONQUISTADORES, TIPO_GUIAS_MAYORES] as const
export const TIPOS_HEREDAN_UBICACION = [
  TIPO_ASOCIACION,
  TIPO_DISTRITO,
  TIPO_IGLESIA,
  TIPO_CLUB,
  ...TIPOS_HIJO_CLUB,
] as const
export const TIPOS_HEREDAN_UBICACION_COMPLETA = [
  TIPO_CLUB,
  ...TIPOS_HIJO_CLUB,
] as const
