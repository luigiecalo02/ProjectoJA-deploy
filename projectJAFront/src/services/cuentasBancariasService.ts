import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'
import type { CuentaBancaria, CuentaBancariaPayload } from '@/modules/settings/types'

export const cuentasBancariasService = {
  async list(params?: { activas?: boolean }): Promise<CuentaBancaria[]> {
    const { data } = await api.get<ApiEnvelope<CuentaBancaria[]>>('/api/v1/settings/cuentas-bancarias', {
      params: params?.activas ? { activas: 1 } : undefined,
    })
    return data.data
  },

  async create(payload: CuentaBancariaPayload): Promise<CuentaBancaria> {
    const { data } = await api.post<ApiEnvelope<CuentaBancaria>>(
      '/api/v1/settings/cuentas-bancarias',
      payload,
    )
    return data.data
  },

  async update(id: number, payload: CuentaBancariaPayload): Promise<CuentaBancaria> {
    const { data } = await api.put<ApiEnvelope<CuentaBancaria>>(
      `/api/v1/settings/cuentas-bancarias/${id}`,
      payload,
    )
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/api/v1/settings/cuentas-bancarias/${id}`)
  },

  async uploadQr(id: number, file: File): Promise<CuentaBancaria> {
    const body = new FormData()
    body.append('qr', file)
    const { data } = await api.post<ApiEnvelope<CuentaBancaria>>(
      `/api/v1/settings/cuentas-bancarias/${id}/qr`,
      body,
    )
    return data.data
  },

  async deleteQr(id: number): Promise<CuentaBancaria> {
    const { data } = await api.delete<ApiEnvelope<CuentaBancaria>>(
      `/api/v1/settings/cuentas-bancarias/${id}/qr`,
    )
    return data.data
  },
}
