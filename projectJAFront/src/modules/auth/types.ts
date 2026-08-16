export interface Role {
  id: number
  name: string
  label?: string | null
  display_name?: string | null
}

export interface User {
  id: number
  name: string
  email: string
  avatar_url: string | null
  is_active: boolean
  roles: Role[]
  club_ids?: number[]
  clubs?: Array<{
    id: number
    nombre: string
    distrito?: string | null
    ciudad?: string | null
    logo_url?: string | null
    tipos?: string[]
  }>
  persona_id?: number | null
  persona?: {
    id: number
    user_id?: number | null
    tipo_identificacion?: string | null
    identificacion?: string | null
    nombre1?: string | null
    nombre2?: string | null
    apellido1?: string | null
    apellido2?: string | null
    correo?: string | null
    telefono?: string | null
    full_name?: string | null
  } | null
  organizaciones?: Array<{
    id: number
    organizacion_id: number
    organizacion_nombre?: string | null
    fecha_inicio?: string | null
    fecha_fin?: string | null
    estado?: boolean
    roles?: Array<{
      id: number
      rol_id: number
      rol_nombre?: string | null
    }>
  }>
  permissions?: string[]
  provider?: string | null
  created_at?: string
  updated_at?: string
}

export interface AuthContextOption {
  key: string
  organizacion_id: number | null
  organizacion_nombre: string
  organizacion_codigo?: string | null
  tipo_organizacion_id?: number | null
  tipo_nombre?: string | null
  rol_id: number
  rol_name: string
  rol_display_name: string
  descripcion?: string | null
  theme?: string
  icon?: string
  is_platform?: boolean
  is_club?: boolean
  club_logo_url?: string | null
  color_principal?: string | null
  color_secundario?: string | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  avatar_url: string | null
  is_active: boolean
  roles: string[]
  permissions: string[]
  is_super?: boolean
  persona_id?: number | null
  organizaciones?: Array<{
    id: number
    organizacion_id: number
    organizacion_nombre?: string | null
    organizacion_codigo?: string | null
    tipo_organizacion_id?: number | null
    roles?: Array<{
      id: number
      rol_id: number
      name?: string | null
      display_name?: string | null
    }>
  }>
  organizacion_ids?: number[]
  contexto?: AuthContextOption | null
  requires_context?: boolean
  context_options?: AuthContextOption[]
}

export interface LoginPayload {
  email: string
  password: string
}

export interface LoginResult {
  token: string
  user: AuthUser
}

export type ParticipantRegistrationField =
  | 'correo'
  | 'telefono'
  | 'sexo'
  | 'nombre1'
  | 'apellido1'
  | 'password'

export interface ParticipantRegistrationStartResult {
  challenge_id: string
  expires_in: number
}

export interface ParticipantRegistrationVerifyResult {
  verification_token: string
  missing_fields: ParticipantRegistrationField[]
  expires_in: number
}

export interface ParticipantRegistrationCompleteResult {
  token: string
}

