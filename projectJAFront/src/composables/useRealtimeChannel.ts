import { onBeforeUnmount, onMounted, watch, type MaybeRefOrGetter, toValue } from 'vue'
import { getEcho } from '@/services/echo'

export type RealtimeHandlers = Record<string, (payload: unknown) => void>

/**
 * Suscripción opt-in a un canal privado.
 * Solo las páginas que llamen este composable abren/usan Echo.
 */
export function useRealtimeChannel(
  channelName: MaybeRefOrGetter<string | null | undefined>,
  handlers: RealtimeHandlers,
  enabled: MaybeRefOrGetter<boolean> = true,
): void {
  let subscribedName: string | null = null

  function unsubscribe(): void {
    if (!subscribedName) return
    const echo = getEcho()
    echo?.leave(subscribedName)
    subscribedName = null
  }

  function subscribe(): void {
    unsubscribe()

    if (!toValue(enabled)) return

    const name = toValue(channelName)
    if (!name) return

    const echo = getEcho()
    if (!echo) return

    const channel = echo.private(name)
    for (const [event, handler] of Object.entries(handlers)) {
      channel.listen(event, handler)
    }
    subscribedName = name
  }

  onMounted(() => {
    subscribe()
  })

  watch(
    () => [toValue(channelName), toValue(enabled)] as const,
    () => {
      subscribe()
    },
  )

  onBeforeUnmount(() => {
    unsubscribe()
  })
}
