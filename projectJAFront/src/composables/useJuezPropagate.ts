import { ref } from 'vue'
import { eventsService } from '@/services/eventsService'
import type { ClubEvent, JuezConflictAction, JuezPropagateConflict } from '@/modules/events/types'

export function useJuezPropagate() {
  const visible = ref(false)
  const applying = ref(false)
  const parentId = ref<number | null>(null)
  const conflicts = ref<JuezPropagateConflict[]>([])
  let finish: (() => void) | null = null

  function close(): void {
    visible.value = false
    finish?.()
    finish = null
  }

  function offer(event: ClubEvent | null | undefined): Promise<void> {
    if (!event?.juez_conflicts?.length) return Promise.resolve()
    parentId.value = event.id
    conflicts.value = event.juez_conflicts
    visible.value = true
    return new Promise((resolve) => {
      finish = resolve
    })
  }

  async function apply(
    decisions: Array<{ event_id: number; action: JuezConflictAction }>,
  ): Promise<void> {
    if (!parentId.value) {
      close()
      return
    }
    applying.value = true
    try {
      const incoming = [...new Set(conflicts.value.flatMap((item) => item.incoming_juez_ids))]
      await eventsService.resolveJuezConflicts(parentId.value, {
        incoming_juez_ids: incoming,
        decisions,
      })
      close()
    } finally {
      applying.value = false
    }
  }

  function dismiss(): void {
    close()
  }

  return { visible, applying, conflicts, offer, apply, dismiss }
}
