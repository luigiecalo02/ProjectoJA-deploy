import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'
import type { ManagedRole, RoleFormPayload, RolePage } from '@/modules/roles/types'

export const rolesService = {
  async list(): Promise<ManagedRole[]> {
    const { data } = await api.get<ApiEnvelope<ManagedRole[]>>('/api/v1/roles')
    return data.data ?? []
  },

  async get(id: number): Promise<ManagedRole> {
    const { data } = await api.get<ApiEnvelope<ManagedRole>>(`/api/v1/roles/${id}`)
    return data.data
  },

  async create(payload: RoleFormPayload): Promise<ManagedRole> {
    const { data } = await api.post<ApiEnvelope<ManagedRole>>('/api/v1/roles', payload)
    return data.data
  },

  async update(id: number, payload: RoleFormPayload): Promise<ManagedRole> {
    const { data } = await api.patch<ApiEnvelope<ManagedRole>>(`/api/v1/roles/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete<ApiEnvelope<null>>(`/api/v1/roles/${id}`)
  },

  async pages(): Promise<RolePage[]> {
    const { data } = await api.get<ApiEnvelope<RolePage[]>>('/api/v1/roles/pages')
    return data.data ?? []
  },

  async syncPermissions(id: number, permissionIds: number[]): Promise<ManagedRole> {
    const { data } = await api.put<ApiEnvelope<ManagedRole>>(`/api/v1/roles/${id}/permissions`, {
      permission_ids: permissionIds,
    })
    return data.data
  },
}
