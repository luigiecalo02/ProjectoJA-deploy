import { api } from '@/services/api'
import { prepareUploadFile } from '@/utils/optimizeImage'
import type { ApiEnvelope } from '@/types/api'
import type { User } from '@/modules/auth/types'

const MAX_BYTES = 5 * 1024 * 1024

/**
 * Sube el avatar por la API Laravel (evita CORS/reglas de Firebase en el navegador).
 * La URL queda en MySQL; el archivo en storage público del backend.
 */
export const storageService = {
  async uploadUserAvatar(userId: number | string, file: File): Promise<string> {
    if (!file.type.startsWith('image/')) {
      throw new Error('Solo se permiten archivos de imagen.')
    }
    if (file.size > MAX_BYTES) {
      throw new Error('La imagen no puede superar 5 MB.')
    }

    const body = new FormData()
    body.append('avatar', await prepareUploadFile(file))

    const { data } = await api.post<ApiEnvelope<User>>(`/api/v1/users/${userId}/avatar`, body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    const url = data.data?.avatar_url
    if (!url) {
      throw new Error(data.message || 'No se pudo guardar el avatar.')
    }

    return url
  },
}
