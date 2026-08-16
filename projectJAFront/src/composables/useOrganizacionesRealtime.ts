import { useRealtimeChannel } from '@/composables/useRealtimeChannel'

export type OrganizacionRealtimeAction = 'created' | 'updated' | 'deleted'

export type OrganizacionRealtimePayload = {
  action: OrganizacionRealtimeAction
  organizacion_id: number
}

/**
 * Opt-in: solo páginas de organizaciones deben llamar esto.
 * Al desmontar la página se deja el canal (sin costo en el resto de la app).
 */
export function useOrganizacionesRealtime(
  onChange: (payload: OrganizacionRealtimePayload) => void,
  enabled = true,
): void {
  useRealtimeChannel(
    'organizations',
    {
      '.organizacion.changed': (raw) => {
        const payload = raw as OrganizacionRealtimePayload
        if (!payload?.organizacion_id || !payload?.action) return
        onChange(payload)
      },
    },
    enabled,
  )
}
