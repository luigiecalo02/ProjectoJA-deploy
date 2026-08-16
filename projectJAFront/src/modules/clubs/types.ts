export interface ClubDirectorUser {
  id: number
  name: string
  email: string
}

export type BoardPosition = 'director' | 'subdirector' | 'secretaria' | 'tesorero'

export interface ClubDirector {
  ministry: BoardPosition | string
  user_id: number
  persona_id?: number | null
  user: ClubDirectorUser | null
  persona?: Pick<Persona, 'id' | 'user_id' | 'tipo_identificacion' | 'identificacion' | 'nombre1' | 'apellido1' | 'correo' | 'full_name'> | null
}

export interface ClubPersona {
  id: number
  user_id?: number | null
  tipo_identificacion: string
  identificacion: string
  nombre1: string
  nombre2?: string | null
  apellido1: string
  apellido2?: string | null
  correo?: string | null
  telefono?: string | null
  full_name: string
  cargo?: string | null
}

export type ClubMinistry = 'conquistadores' | 'aventureros' | 'guias_mayores'

export interface Club {
  id: number
  organizacion_id?: number | null
  iglesia_organizacion_id?: number | null
  organizacion?: {
    id: number
    nombre: string
    codigo?: string | null
    tipo_organizacion_id?: number
    organizacion_padre_id?: number | null
    padre?: { id: number; nombre: string; codigo?: string | null } | null
  } | null
  nombre: string
  nombre_corto?: string | null
  lema?: string | null
  logo?: string | null
  logo_url: string | null
  fecha_fundacion?: string | null
  descripcion?: string | null
  color_principal?: string | null
  color_secundario?: string | null
  sitio_web?: string | null
  distrito: string | null
  ciudad: string | null
  tipos: ClubMinistry[]
  is_active: boolean
  account_user_id?: number | null
  personas_count?: number | null
  directors: ClubDirector[]
  persona_ids?: number[]
  personas?: ClubPersona[]
  created_at?: string
  updated_at?: string
}

export interface ClubFormPayload {
  organizacion_id?: number
  nombre: string
  nombre_corto?: string | null
  lema?: string | null
  logo?: string | null
  fecha_fundacion?: string | null
  descripcion?: string | null
  color_principal?: string | null
  color_secundario?: string | null
  sitio_web?: string | null
  distrito?: string | null
  ciudad?: string | null
  tipos: ClubMinistry[]
  is_active?: boolean
  persona_ids?: number[]
}

export interface PersonaClubSummary {
  id: number
  nombre: string
  distrito: string | null
  ciudad: string | null
  tipos: ClubMinistry[]
}

export interface Persona {
  id: number
  user_id?: number | null
  tipo_identificacion: string
  identificacion: string
  nombre1: string
  nombre2: string | null
  apellido1: string
  apellido2: string | null
  fecha_nacimiento: string | null
  sexo: string | null
  telefono: string | null
  correo: string | null
  direccion_actual: string | null
  full_name: string
  club_ids?: number[]
  clubs?: PersonaClubSummary[]
  organizacion_ids?: number[]
  organizaciones?: Array<{
    id: number
    organizacion_id: number
    organizacion_nombre?: string | null
    estado?: boolean
  }>
}

export interface PersonaFormPayload {
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
  direccion_actual?: string | null
  club_ids?: number[]
  organizacion_ids?: number[]
}

export interface PersonaOrganizacionOption {
  id: number
  nombre: string
  codigo?: string | null
  tipo_organizacion_id?: number
  tipo_nombre?: string | null
  organizacion_padre_id?: number | null
  padre_nombre?: string | null
  abuelo_nombre?: string | null
  is_leaf?: boolean
}

export interface PersonaOrganizacionOptions {
  mode: 'admin' | 'leaf' | 'parent'
  locked: boolean
  default_ids: number[]
  options: PersonaOrganizacionOption[]
}

export type DirectorMode = 'select' | 'create'

export interface DirectorAssignmentPayload {
  mode?: DirectorMode
  clear?: boolean
  persona_id?: number | null
  user_id?: number | null
  user?: {
    name?: string
    email: string
    password?: string
  }
  persona?: {
    tipo_identificacion?: string
    identificacion?: string
    nombre1?: string
    nombre2?: string | null
    apellido1?: string
    apellido2?: string | null
    telefono?: string | null
    sexo?: string | null
  }
}

export interface DirectorsPayload {
  directors: Partial<Record<BoardPosition, DirectorAssignmentPayload | null>>
}
