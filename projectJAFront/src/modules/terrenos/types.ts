export type GeoJsonGeometry = {
  type: 'Polygon' | 'MultiPolygon' | 'Feature'
  coordinates?: unknown
  geometry?: GeoJsonGeometry
  properties?: Record<string, unknown>
}

export type EstructuraTerreno = {
  id: number
  terreno_id: number
  nombre: string
  tipo: string
  descripcion?: string | null
  geometria?: GeoJsonGeometry | null
  area?: number | null
  perimetro?: number | null
  color?: string | null
  orden: number
  estado: string
}

export type LoteTerreno = {
  id: number
  configuracion_terreno_id?: number | null
  zona_terreno_id?: number | null
  codigo: string
  nombre?: string | null
  descripcion?: string | null
  geometria?: GeoJsonGeometry | null
  area?: number | null
  perimetro?: number | null
  capacidad_calculada?: number | null
  capacidad_maxima?: number | null
  tipo_capacidad: 'calculada' | 'manual'
  orden: number
  estado: string
}

export type ZonaTerreno = {
  id: number
  configuracion_terreno_id: number
  nombre: string
  descripcion?: string | null
  geometria?: GeoJsonGeometry | null
  area?: number | null
  perimetro?: number | null
  color?: string | null
  orden: number
  estado: string
  lotes?: LoteTerreno[]
}

export type ConfiguracionTerreno = {
  id: number
  terreno_id: number
  nombre: string
  descripcion?: string | null
  es_default: boolean
  orden: number
  estado: string
  zonas_count?: number | null
  lotes_count?: number | null
  terreno?: Pick<
    Terreno,
    'id' | 'nombre' | 'geometria' | 'latitud' | 'longitud' | 'nivel_zoom' | 'metros_por_persona' | 'imagen_referencia'
  > | null
  estructuras?: EstructuraTerreno[]
  zonas?: ZonaTerreno[]
  lotes?: LoteTerreno[]
}

export type Terreno = {
  id: number
  lugar_id?: number | null
  lugar?: { id: number; nombre: string } | null
  nombre: string
  descripcion?: string | null
  latitud?: number | null
  longitud?: number | null
  nivel_zoom?: number | null
  geometria?: GeoJsonGeometry | null
  area_total?: number | null
  perimetro?: number | null
  metros_por_persona: number
  imagen_referencia?: string | null
  estado: string
  created_by?: number | null
  configuraciones_count?: number | null
  estructuras_count?: number | null
  eventos_count?: number | null
  estructuras?: EstructuraTerreno[]
  configuraciones?: ConfiguracionTerreno[]
  created_at?: string | null
  updated_at?: string | null
}

export type TerrenoFormPayload = {
  lugar_id: number
  nombre: string
  descripcion?: string | null
  latitud?: number | null
  longitud?: number | null
  nivel_zoom?: number | null
  geometria?: GeoJsonGeometry | null
  area_total?: number | null
  perimetro?: number | null
  metros_por_persona?: number
  imagen_referencia?: string | null
  estado?: string
}

export type AsignacionLote = {
  id: number
  evento_lote_id: number
  club_id: number
  cantidad_personas: number
  observaciones?: string | null
  estado: string
  asignado_por?: number | null
  club?: {
    id: number
    nombre: string
    nombre_corto?: string | null
    logo?: string | null
    organizacion_id?: number | null
  } | null
}

export type EventoLote = {
  id: number
  evento_terreno_id?: number | null
  evento_zona_id?: number | null
  lote_terreno_id?: number | null
  codigo: string
  nombre?: string | null
  geometria?: GeoJsonGeometry | null
  area?: number | null
  perimetro?: number | null
  capacidad_calculada?: number | null
  capacidad_maxima?: number | null
  tipo_capacidad: string
  orden: number
  estado: string
  asignacion?: AsignacionLote | null
}

export type EventoZona = {
  id: number
  evento_terreno_id: number
  zona_terreno_id?: number | null
  nombre: string
  geometria?: GeoJsonGeometry | null
  area?: number | null
  perimetro?: number | null
  capacidad?: number | null
  color?: string | null
  orden: number
  estado: string
  lotes?: EventoLote[]
}

export type EventoEstructura = {
  id: number
  evento_terreno_id: number
  estructura_terreno_id?: number | null
  nombre: string
  tipo: string
  geometria?: GeoJsonGeometry | null
  area?: number | null
  perimetro?: number | null
  color?: string | null
  orden: number
  estado: string
}

export type EventoTerreno = {
  id: number
  evento_id: number
  terreno_id: number
  configuracion_terreno_id?: number | null
  descripcion?: string | null
  estado: string
  terreno?: Terreno | null
  configuracion?: Pick<ConfiguracionTerreno, 'id' | 'nombre' | 'es_default'> | null
  zonas?: EventoZona[]
  lotes?: EventoLote[]
  estructuras?: EventoEstructura[]
}

export type MapToolMode =
  | 'select'
  | 'draw_terreno'
  | 'draw_zona'
  | 'draw_lote'
  | 'draw_estructura'
  | 'edit'
  | 'delete'
  | 'measure'
export type MapLayerMode = 'roadmap' | 'satellite' | 'imagen'
