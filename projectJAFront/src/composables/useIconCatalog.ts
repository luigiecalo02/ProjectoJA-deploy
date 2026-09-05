import { ref } from 'vue'
import { eventsService } from '@/services/eventsService'
import type { IconoCatalogo } from '@/modules/events/types'

const items = ref<IconoCatalogo[]>([])
const loaded = ref(false)
const loading = ref(false)

export function useIconCatalog() {
  async function refresh(todos = false): Promise<IconoCatalogo[]> {
    loading.value = true
    try {
      items.value = await eventsService.iconos({ todos })
      loaded.value = true
      return items.value
    } finally {
      loading.value = false
    }
  }

  async function ensureLoaded(): Promise<IconoCatalogo[]> {
    if (loaded.value) return items.value
    return refresh()
  }

  function matches(item: IconoCatalogo, query: string): boolean {
    const q = query.trim().toLowerCase()
    if (!q) return true
    return (
      item.nombre.toLowerCase().includes(q) ||
      item.slug.toLowerCase().includes(q) ||
      item.categoria.toLowerCase().includes(q) ||
      item.valor.toLowerCase().includes(q) ||
      item.etiquetas.some((tag) => tag.toLowerCase().includes(q))
    )
  }

  return { items, loaded, loading, refresh, ensureLoaded, matches }
}
