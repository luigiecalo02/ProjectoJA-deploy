export type LugarEstado = 'activo' | 'inactivo'

export interface LugarCatalogItem {
  id: number
  nombre: string
  lugar_id?: number | null
  lugar_nombre?: string | null
}

export interface Lugar {
  id: number
  nombre: string
  descripcion?: string | null
  latitud?: number | null
  longitud?: number | null
  nivel_zoom?: number | null
  estado: LugarEstado | string
  terrenos_count?: number | null
  cabanas_count?: number | null
  eventos_count?: number | null
  terreno_ids?: number[] | null
  cabana_ids?: number[] | null
  terrenos?: Array<{ id: number; nombre: string }> | null
  cabanas?: Array<{ id: number; nombre: string }> | null
  created_at?: string | null
  updated_at?: string | null
}

export interface LugarPayload {
  nombre: string
  descripcion?: string | null
  latitud?: number | null
  longitud?: number | null
  nivel_zoom?: number | null
  estado?: LugarEstado | string
  terreno_ids?: number[]
  cabana_ids?: number[]
}

export interface LugarCatalogos {
  terrenos: LugarCatalogItem[]
  cabanas: LugarCatalogItem[]
}
