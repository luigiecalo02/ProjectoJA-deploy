import type { Role } from '@/modules/auth/types'

export interface UserListParams {
  page?: number
  per_page?: number
  search?: string
  is_active?: boolean | null
  organizacion_id?: number | null
}

export interface UserFormPayload {
  name: string
  email: string
  password?: string | null
  password_confirmation?: string | null
  is_active?: boolean
  role_ids?: number[]
  club_ids?: number[]
  avatar_url?: string | null
  persona_id?: number | null
  organizaciones?: Array<{
    organizacion_id: number
    rol_ids: number[]
    fecha_inicio?: string | null
    fecha_fin?: string | null
    estado?: boolean
  }>
  organizacion_id?: number | null
  organizacion_rol_id?: number | null
  persona?: {
    tipo_identificacion?: string
    identificacion?: string
    nombre1?: string
    nombre2?: string | null
    apellido1?: string
    apellido2?: string | null
    telefono?: string | null
    correo?: string | null
  } | null
}

export type RoleOption = Role & { name: string }
