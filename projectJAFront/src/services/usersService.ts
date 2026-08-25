import { api } from '@/services/api'
import type { ApiEnvelope, PaginationMeta } from '@/types/api'
import type { User } from '@/modules/auth/types'
import type { RoleOption, UserFormPayload, UserListParams } from '@/modules/users/types'

export interface UsersPage {
  items: User[]
  pagination: PaginationMeta | null
}

export const usersService = {
  async list(params: UserListParams = {}): Promise<UsersPage> {
    const { data } = await api.get<ApiEnvelope<User[]>>('/api/v1/users', {
      params: {
        page: params.page,
        per_page: params.per_page,
        q: params.search || undefined,
        is_active: params.is_active === null ? undefined : params.is_active,
        organizacion_id: params.organizacion_id || undefined,
        tipo_club: params.tipo_club || undefined,
        role: params.role || undefined,
      },
    })
    return {
      items: data.data ?? [],
      pagination: data.pagination,
    }
  },

  async get(id: number): Promise<User> {
    const { data } = await api.get<ApiEnvelope<User>>(`/api/v1/users/${id}`)
    return data.data
  },

  async create(payload: UserFormPayload): Promise<User> {
    const { data } = await api.post<ApiEnvelope<User>>('/api/v1/users', payload)
    return data.data
  },

  async update(id: number, payload: UserFormPayload): Promise<User> {
    const { data } = await api.patch<ApiEnvelope<User>>(`/api/v1/users/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete<ApiEnvelope<null>>(`/api/v1/users/${id}`)
  },

  async toggleActive(id: number, isActive: boolean): Promise<User> {
    const { data } = await api.patch<ApiEnvelope<User>>(`/api/v1/users/${id}/status`, {
      is_active: isActive,
    })
    return data.data
  },

  async roles(): Promise<RoleOption[]> {
    const { data } = await api.get<ApiEnvelope<Array<{ id: number; name: string; display_name?: string }>>>('/api/v1/roles/catalog')
    return (data.data ?? []).map((role) => ({
      id: role.id,
      name: role.name,
      label: role.display_name || role.name,
    }))
  },
}
