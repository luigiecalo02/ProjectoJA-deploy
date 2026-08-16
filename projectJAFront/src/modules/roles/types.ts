export interface RolePermission {
  id: number
  name: string
  display_name: string
  action?: string
}

export interface RolePage {
  id: number
  key: string
  name: string
  route_name: string | null
  icon: string | null
  description: string | null
  permissions: RolePermission[]
}

export interface ManagedRole {
  id: number
  name: string
  display_name: string
  description: string | null
  icon?: string | null
  is_system: boolean
  is_super: boolean
  users_count: number
  permissions_count: number
  permission_ids?: number[]
  permissions?: RolePermission[]
}

export interface RoleFormPayload {
  display_name: string
  description?: string | null
  icon?: string | null
  name?: string | null
  permission_ids: number[]
}
