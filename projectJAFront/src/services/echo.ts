import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { TOKEN_KEY } from '@/services/api'

declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo?: Echo<'reverb'>
  }
}

window.Pusher = Pusher

let echoInstance: Echo<'reverb'> | null = null

function resolveApiOrigin(): string {
  const base = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000'
  return base.replace(/\/$/, '')
}

/**
 * Echo se crea solo cuando una página lo pide (opt-in).
 * No abre WebSocket al cargar la app.
 */
export function getEcho(): Echo<'reverb'> | null {
  const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined
  if (!key) {
    return null
  }

  if (echoInstance) {
    return echoInstance
  }

  const token = localStorage.getItem(TOKEN_KEY)
  if (!token) {
    return null
  }

  const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'http'
  const host = (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? 'localhost'
  const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080)

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${resolveApiOrigin()}/api/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    },
  })

  window.Echo = echoInstance
  return echoInstance
}

export function disconnectEcho(): void {
  if (!echoInstance) return
  echoInstance.disconnect()
  echoInstance = null
  delete window.Echo
}
