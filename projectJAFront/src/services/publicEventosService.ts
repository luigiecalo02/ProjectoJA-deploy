import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'
import type { EventoArchivoMaterial } from '@/modules/events/types'

export type PublicEventoLote = {
  id: number
  codigo?: string | null
  nombre?: string | null
  capacidad_maxima?: number | null
  estado: string
}

export type PublicEventoCabana = {
  id: number
  nombre: string
  descripcion?: string | null
  image_url?: string | null
  capacidad: number
  disponible: boolean
}

export type PublicEventoOfertaCabana = {
  id: number
  nombre: string
  precio_dia: number
  dias: number
  total: number
}

export type PublicEventoCuenta = {
  id: number
  nombre: string
  banco?: string | null
  tipo_cuenta?: string | null
  numero_cuenta?: string | null
  titular?: string | null
  identificacion_titular?: string | null
  qr_url?: string | null
}

export type PublicEventoCard = {
  id: number
  name: string
  descripcion?: string | null
  lugar?: string | null
  lugar_catalogo?: { id: number; nombre: string } | null
  image_url?: string | null
  banner_url?: string | null
  starts_at?: string | null
  ends_at?: string | null
  requiere_pago: boolean
  precio: number
  precio_lista?: number | null
  fuera_de_tiempo: boolean
  usar_lotes: boolean
  usar_cabanas: boolean
  fecha_limite_inscripcion?: string | null
}

export type PublicEventoDetail = PublicEventoCard & {
  reglas?: string | null
  requiere_seguro: boolean
  seguro_valor?: number | null
  metodo_pago?: string | null
  cuenta_bancaria?: PublicEventoCuenta | null
  noches: number
  oferta_cabana?: PublicEventoOfertaCabana | null
  lotes: PublicEventoLote[]
  cabanas: PublicEventoCabana[]
  archivos?: EventoArchivoMaterial[]
}

export type PublicEventoEnrollPayload = {
  tipo_identificacion: string
  identificacion: string
  nombre1: string
  nombre2?: string
  apellido1: string
  apellido2?: string
  fecha_nacimiento?: string | null
  sexo?: string | null
  telefono?: string
  correo: string
  evento_lote_id?: number | null
  evento_cabana_id?: number | null
  crear_usuario: boolean
  password?: string
  password_confirmation?: string
  comprobante?: File | null
  comprobante_valor?: number
}

export type PublicEventoEnrollResult = {
  inscripcion_id: number
  total: number
  usuario_creado: boolean
  correo: string
  correo_enmascarado: string
}

export const publicEventosService = {
  async list(): Promise<PublicEventoCard[]> {
    const { data } = await api.get<ApiEnvelope<PublicEventoCard[]>>('/api/v1/public/eventos')
    return data.data
  },

  async show(id: number): Promise<PublicEventoDetail> {
    const { data } = await api.get<ApiEnvelope<PublicEventoDetail>>(`/api/v1/public/eventos/${id}`)
    return data.data
  },

  async enroll(id: number, payload: PublicEventoEnrollPayload): Promise<{ result: PublicEventoEnrollResult; message: string }> {
    const body = new FormData()
    body.append('tipo_identificacion', payload.tipo_identificacion)
    body.append('identificacion', payload.identificacion)
    body.append('nombre1', payload.nombre1)
    if (payload.nombre2) body.append('nombre2', payload.nombre2)
    body.append('apellido1', payload.apellido1)
    if (payload.apellido2) body.append('apellido2', payload.apellido2)
    if (payload.fecha_nacimiento) body.append('fecha_nacimiento', payload.fecha_nacimiento)
    if (payload.sexo) body.append('sexo', payload.sexo)
    if (payload.telefono) body.append('telefono', payload.telefono)
    body.append('correo', payload.correo)
    if (payload.evento_lote_id) body.append('evento_lote_id', String(payload.evento_lote_id))
    if (payload.evento_cabana_id) body.append('evento_cabana_id', String(payload.evento_cabana_id))
    body.append('crear_usuario', payload.crear_usuario ? '1' : '0')
    if (payload.crear_usuario && payload.password) {
      body.append('password', payload.password)
      body.append('password_confirmation', payload.password_confirmation ?? '')
    }
    if (payload.comprobante) body.append('comprobante', payload.comprobante)
    if (payload.comprobante_valor != null) {
      body.append('comprobante_valor', String(payload.comprobante_valor))
    }

    const { data } = await api.post<ApiEnvelope<PublicEventoEnrollResult>>(
      `/api/v1/public/eventos/${id}/inscribir`,
      body,
    )
    return { result: data.data, message: data.message }
  },
}
